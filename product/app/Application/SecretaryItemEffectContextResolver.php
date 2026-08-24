<?php

namespace App\Application;

use App\Domain\Secretary\SecretaryEquipmentValidationException;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use JsonException;

final class SecretaryItemEffectContextResolver
{
    public function resolve(User $user, ?int $worldId): ?SecretaryItemEffectProjection
    {
        return $this->resolveForNationStates($user, $worldId, ['active', 'recovery']);
    }

    public function resolveForPublicProfile(User $user, ?int $worldId): ?SecretaryItemEffectProjection
    {
        return $this->resolveForNationStates($user, $worldId, ['active', 'dormant', 'recovery']);
    }

    /** @param list<string> $nationStates */
    private function resolveForNationStates(
        User $user,
        ?int $worldId,
        array $nationStates,
    ): ?SecretaryItemEffectProjection {
        if ($worldId === null) {
            return null;
        }
        $context = DB::table('nation_memberships as membership')
            ->join('nations as nation', 'nation.id', '=', 'membership.nation_id')
            ->join('worlds as world', 'world.id', '=', 'membership.world_id')
            ->join('ruleset_versions as ruleset', 'ruleset.id', '=', 'world.ruleset_version_id')
            ->where('membership.user_id', $user->id)
            ->where('membership.world_id', $worldId)
            ->where('membership.role', 'owner')
            ->whereIn('nation.state', $nationStates)
            ->whereColumn('nation.world_id', 'membership.world_id')
            ->select([
                'world.id as world_id',
                'ruleset.id as ruleset_version_id',
                'ruleset.key as ruleset_key',
                'ruleset.version as ruleset_version',
                'ruleset.settings as ruleset_settings',
            ])
            ->first();
        if ($context === null) {
            throw new SecretaryEquipmentValidationException('指定したWorldの装備効果を表示できません。');
        }
        try {
            $settings = is_string($context->ruleset_settings)
                ? json_decode($context->ruleset_settings, true, 512, JSON_THROW_ON_ERROR)
                : $context->ruleset_settings;
        } catch (JsonException $exception) {
            throw new DomainException('Owned World ruleset settings are invalid.', previous: $exception);
        }
        if (! is_array($settings)
            || ($settings['key'] ?? null) !== $context->ruleset_key
            || ($settings['version'] ?? null) !== (int) $context->ruleset_version) {
            throw new DomainException('Owned World ruleset metadata and immutable settings do not match.');
        }

        return new SecretaryItemEffectProjection(
            context: [
                'source' => 'owned_world',
                'world_id' => (int) $context->world_id,
                'ruleset_version_id' => (int) $context->ruleset_version_id,
                'ruleset_key' => (string) $context->ruleset_key,
                'ruleset_version' => (int) $context->ruleset_version,
            ],
            rulesetSettings: $settings,
        );
    }
}
