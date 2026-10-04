<?php

namespace App\Application;

use App\Domain\Secretary\SecretarySkillCatalog;
use App\Domain\Turn\TurnContext;
use App\Domain\Turn\TurnRandomStreamFactory;
use App\Models\Ship;

final class SecretaryNavyEvasionService
{
    /** @param array<string, mixed> $effect */
    public function chancePercent(array $effect, int $level): float
    {
        return $effect['maximum_percent'] * $level / ($level + $effect['level_offset']);
    }

    public function evades(TurnContext $context, Ship $ship, int $damage, bool $instantSink = false): bool
    {
        if ($instantSink || $damage !== 1 || $ship->ship_type_key !== 'warship' || $ship->nation_id === null
            || ! $context->state->hasSecretarySnapshot((int) $ship->nation_id)) {
            return false;
        }
        $effect = $context->ruleset->settings['secretary']['skills'][SecretarySkillCatalog::NAVY]['effect'];
        $level = $context->state->secretarySkillLevel((int) $ship->nation_id, SecretarySkillCatalog::NAVY);
        if ($level === 0 || $effect['type'] !== 'warship_damage_evasion') {
            return false;
        }
        $draw = $context->random->stream(TurnRandomStreamFactory::secretaryNavyEvasion(
            (int) $ship->id, $effect['random_stream_version'],
        ))->integer(1, 1_000_000);

        return $draw * ($level + $effect['level_offset']) <= 10_000 * $effect['maximum_percent'] * $level;
    }
}
