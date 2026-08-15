<?php

namespace App\Application;

use App\Domain\Concurrency\OptimisticLockException;
use App\Domain\Economy\SalePolicy;
use App\Domain\Economy\SalePolicyRules;
use App\Domain\Ruleset\CurrentRulesetGuard;
use App\Models\Nation;
use App\Models\NationMembership;
use App\Models\NationResourceSalePolicy;
use App\Models\ResourceDefinition;
use App\Models\User;
use App\Models\World;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class SalePolicyService
{
    public function __construct(private readonly CurrentRulesetGuard $rulesetGuard) {}

    public function update(
        User $user,
        Nation $nation,
        ResourceDefinition $resource,
        string $policy,
        ?int $keepAmount,
        int $expectedVersion,
    ): NationResourceSalePolicy {
        return DB::transaction(function () use ($user, $nation, $resource, $policy, $keepAmount, $expectedVersion): NationResourceSalePolicy {
            $world = World::query()->whereKey($nation->world_id)->lockForUpdate()->firstOrFail();
            $ruleset = $world->rulesetVersion()->firstOrFail();
            $this->rulesetGuard->assertMutable($world, $ruleset);
            $lockedNation = Nation::query()
                ->whereKey($nation->id)
                ->where('world_id', $world->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($lockedNation->state !== 'active') {
                throw new DomainException('現役ではない島のsale policyは変更できません。');
            }
            $this->authorize($user, $lockedNation);
            $policyRules = SalePolicyRules::fromSettings($ruleset->settings);
            if (! $resource->tradable) {
                throw new DomainException('売却できないresourceです。');
            }
            if (! SalePolicy::isSupported($policy)) {
                throw new DomainException('sale policyが不正です。');
            }
            $policyRules->assertAllowed($resource, $policy);
            if ($policy === SalePolicy::KeepAmount->value && ($keepAmount === null || $keepAmount < 0)) {
                throw new DomainException('keep_amountには0以上の保持数量が必要です。');
            }
            if ($policy !== SalePolicy::KeepAmount->value && $keepAmount !== null) {
                throw new DomainException('keep_amount以外では保持数量を指定できません。');
            }

            $record = NationResourceSalePolicy::query()->firstOrCreate(
                ['nation_id' => $lockedNation->id, 'resource_definition_id' => $resource->id],
                ['policy' => $policyRules->defaultPolicy, 'keep_amount' => null, 'version' => 1],
            );
            $record = NationResourceSalePolicy::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            if ($record->version !== $expectedVersion) {
                throw new OptimisticLockException('sale policyが他の操作で更新されました。再読込してください。');
            }
            $record->update(['policy' => $policy, 'keep_amount' => $keepAmount, 'version' => $record->version + 1]);
            DB::table('audit_events')->insert([
                'actor_user_id' => $user->id,
                'event_type' => 'resource.sale_policy.updated',
                'subject_type' => NationResourceSalePolicy::class,
                'subject_id' => $record->id,
                'metadata' => json_encode(['resource_key' => $resource->key, 'policy' => $policy, 'keep_amount' => $keepAmount], JSON_THROW_ON_ERROR),
                'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);

            return $record->refresh()->load('resourceDefinition');
        }, 3);
    }

    private function authorize(User $user, Nation $nation): void
    {
        $membership = NationMembership::query()
            ->where('user_id', $user->id)
            ->where('world_id', $nation->world_id)
            ->where('nation_id', $nation->id)
            ->where('role', 'owner')
            ->lockForUpdate()
            ->first();
        if ($membership === null) {
            throw new AuthorizationException('自国のsale policyだけを変更できます。');
        }
    }
}
