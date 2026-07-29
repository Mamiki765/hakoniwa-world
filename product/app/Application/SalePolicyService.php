<?php

namespace App\Application;

use App\Domain\Concurrency\OptimisticLockException;
use App\Domain\Economy\SalePolicy;
use App\Models\Nation;
use App\Models\NationMembership;
use App\Models\NationResourceSalePolicy;
use App\Models\ResourceDefinition;
use App\Models\User;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class SalePolicyService
{
    public function update(
        User $user,
        Nation $nation,
        ResourceDefinition $resource,
        string $policy,
        ?int $keepAmount,
        int $expectedVersion,
    ): NationResourceSalePolicy {
        return DB::transaction(function () use ($user, $nation, $resource, $policy, $keepAmount, $expectedVersion): NationResourceSalePolicy {
            $this->authorize($user, $nation);
            if (! $resource->tradable) {
                throw new DomainException('売却できないresourceです。');
            }
            if (! SalePolicy::isSupported($policy)) {
                throw new DomainException('sale policyが不正です。');
            }
            $this->assertResourceCapability($nation, $resource, $policy);
            if ($policy === SalePolicy::KeepAmount->value && ($keepAmount === null || $keepAmount < 0)) {
                throw new DomainException('keep_amountには0以上の保持数量が必要です。');
            }
            if ($policy !== SalePolicy::KeepAmount->value && $keepAmount !== null) {
                throw new DomainException('keep_amount以外では保持数量を指定できません。');
            }

            $record = NationResourceSalePolicy::query()->firstOrCreate(
                ['nation_id' => $nation->id, 'resource_definition_id' => $resource->id],
                ['policy' => $this->defaultPolicy($nation), 'keep_amount' => null, 'version' => 1],
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
        if (! NationMembership::query()->where('user_id', $user->id)->where('nation_id', $nation->id)->exists()) {
            throw new AuthorizationException('自国のsale policyだけを変更できます。');
        }
    }

    private function defaultPolicy(Nation $nation): string
    {
        $settings = $nation->world()->firstOrFail()->rulesetVersion()->firstOrFail()->settings;
        $policy = $settings['default_sale_policy'] ?? null;
        if (! SalePolicy::isSupportedRulesetDefault($policy)) {
            throw new DomainException('Worldのdefault sale policy設定が不正です。');
        }

        return $policy;
    }

    private function assertResourceCapability(
        Nation $nation,
        ResourceDefinition $resource,
        string $policy,
    ): void {
        if ($policy !== SalePolicy::SellAll->value) {
            return;
        }
        $settings = $nation->world()->firstOrFail()->rulesetVersion()->firstOrFail()->settings;
        $forbidden = $settings['turn_processing']['sale_policy']['sell_all_forbidden_resource_keys'] ?? [];
        if (is_array($forbidden) && in_array($resource->key, $forbidden, true)) {
            throw new DomainException("{$resource->name}ではsell_allを使用できません。");
        }
    }
}
