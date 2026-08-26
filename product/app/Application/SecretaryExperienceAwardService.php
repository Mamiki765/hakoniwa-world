<?php

namespace App\Application;

use App\Domain\Secretary\SecretaryItemEffectAggregator;
use App\Domain\Turn\TurnContext;
use App\Domain\Turn\TurnRandomStreamFactory;
use DomainException;

final class SecretaryExperienceAwardService
{
    public const PASSIVE_SKILL = 'passive_skill_experience';

    public const MONSTER = 'monster_experience';

    public function __construct(private readonly SecretaryItemEffectAggregator $items) {}

    public function awardSkill(
        TurnContext $context,
        int $nationId,
        string $skillKey,
        int $amount = 1,
    ): void {
        $context->state->awardSecretaryExperience(
            $nationId,
            $skillKey,
            $this->resolvedAmount($context, $nationId, self::PASSIVE_SKILL, $amount, $skillKey),
        );
    }

    public function awardMonster(TurnContext $context, int $nationId, int $amount): int
    {
        $resolvedAmount = $this->resolvedAmount($context, $nationId, self::MONSTER, $amount);
        $context->state->awardSecretaryMonsterExperience(
            $nationId,
            $resolvedAmount,
        );

        return $resolvedAmount;
    }

    private function resolvedAmount(
        TurnContext $context,
        int $nationId,
        string $source,
        int $amount,
        ?string $skillKey = null,
    ): int {
        if ($amount < 1) {
            throw new DomainException('Secretary experience award amount must be positive.');
        }
        $effect = $this->items->snapshotExperienceDouble($context->state, $nationId, $source, $skillKey);
        if ($effect === null) {
            return $amount;
        }
        $draw = $context->random->stream(TurnRandomStreamFactory::secretaryExperience(
            $nationId,
            $source,
            $effect['random_stream_version'],
        ))->integer(1, 100);
        if ($draw > $effect['chance_percent']) {
            return $amount;
        }
        if ($amount > intdiv(PHP_INT_MAX, $effect['multiplier'])) {
            throw new DomainException('Secretary experience award exceeds the supported integer range.');
        }

        return $amount * $effect['multiplier'];
    }
}
