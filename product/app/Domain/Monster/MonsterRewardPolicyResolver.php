<?php

namespace App\Domain\Monster;

use DomainException;

final class MonsterRewardPolicyResolver
{
    public const METADATA_KEY = 'reward_policy';

    public const STANDARD_SPLIT = 'standard_split';

    public const HOSTLESS_FULL_KILLER_MONEY = 'hostless_full_killer_money';

    public function validate(mixed $policy): string
    {
        if (! is_string($policy) || ! in_array($policy, [
            self::STANDARD_SPLIT,
            self::HOSTLESS_FULL_KILLER_MONEY,
        ], true)) {
            throw new DomainException('Monster reward policy is invalid.');
        }

        return $policy;
    }

    /**
     * @param  array<string, mixed>  $rulesetSettings
     * @return array{policy: string, explicitly_authored: bool, killer_share: int, host_share: int, unclaimed_share: int}
     */
    public function shares(
        array $rulesetSettings,
        string $monsterKey,
        int $wreckageValue,
        bool $hostNationExists,
    ): array {
        if ($wreckageValue < 0) {
            throw new DomainException('Monster wreckage value cannot be negative.');
        }
        $definitions = $rulesetSettings['monster_definitions'] ?? null;
        if (! is_array($definitions) || ! array_is_list($definitions)) {
            throw new DomainException('Monster reward resolution requires loaded ruleset definitions.');
        }

        $matched = [];
        foreach ($definitions as $definition) {
            if (is_array($definition) && ($definition['key'] ?? null) === $monsterKey) {
                $matched[] = $definition;
            }
        }
        if (count($matched) !== 1) {
            throw new DomainException('Monster reward resolution requires one matching ruleset definition.');
        }
        $sourceMetadata = $matched[0]['source_metadata'] ?? null;
        if (! is_array($sourceMetadata) || array_is_list($sourceMetadata)) {
            throw new DomainException('Monster reward resolution requires definition source metadata.');
        }
        if (! array_key_exists(self::METADATA_KEY, $sourceMetadata)) {
            throw new DomainException('Monster reward policy must be explicitly authored.');
        }
        $policy = $this->validate($sourceMetadata[self::METADATA_KEY]);

        if ($policy === self::HOSTLESS_FULL_KILLER_MONEY && ! $hostNationExists) {
            return [
                'policy' => $policy,
                'explicitly_authored' => true,
                'killer_share' => $wreckageValue,
                'host_share' => 0,
                'unclaimed_share' => 0,
            ];
        }

        $killerShare = intdiv($wreckageValue, 2);
        $remainder = $wreckageValue - $killerShare;

        return [
            'policy' => $policy,
            'explicitly_authored' => true,
            'killer_share' => $killerShare,
            'host_share' => $hostNationExists ? $remainder : 0,
            'unclaimed_share' => $hostNationExists ? 0 : $remainder,
        ];
    }
}
