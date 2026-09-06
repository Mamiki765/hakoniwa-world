<?php

namespace App\Domain\Facility;

use App\Models\FacilityDefinition;
use DomainException;

final class FacilityRankPolicy
{
    public const SETTINGS_KEY = 'facility_rank_system';

    public const ORDINARY_TERRAIN_DESTRUCTION = 'ordinary_terrain_destruction';

    public const LAND_DESTRUCTION = 'land_destruction';

    public const FIRE = 'fire';

    public const EARTHQUAKE = 'earthquake';

    /**
     * @param  array<string, mixed>  $rulesetSettings
     * @return array<string, mixed>|null
     */
    public function contract(array $rulesetSettings, string $facilityKey): ?array
    {
        $definitions = $rulesetSettings[self::SETTINGS_KEY]['definitions'] ?? null;
        if ($definitions === null) {
            return null;
        }
        if (! is_array($definitions) || array_is_list($definitions)) {
            throw new DomainException('The active Ruleset has an invalid facility-rank definition map.');
        }
        $contract = $definitions[$facilityKey] ?? null;
        if ($contract === null) {
            return null;
        }
        if (! is_array($contract) || array_is_list($contract)) {
            throw new DomainException("Facility rank contract {$facilityKey} must be an authored map.");
        }

        return $contract;
    }

    /**
     * @param  array<string, mixed>  $rulesetSettings
     * @return 1|2
     */
    public function rank(array $rulesetSettings, string $facilityKey, int $scale): int
    {
        $contract = $this->contract($rulesetSettings, $facilityKey);

        return $contract !== null && $scale > $this->positiveInteger(
            $contract['rank_one_maximum_scale'] ?? null,
            "{$facilityKey}.rank_one_maximum_scale",
        ) ? 2 : 1;
    }

    /** @param array<string, mixed> $rulesetSettings */
    public function isRankTwo(array $rulesetSettings, string $facilityKey, ?int $scale): bool
    {
        return is_int($scale) && $this->rank($rulesetSettings, $facilityKey, $scale) === 2;
    }

    /** @param array<string, mixed> $rulesetSettings */
    public function expandedScale(
        array $rulesetSettings,
        FacilityDefinition $facility,
        int $currentScale,
    ): int {
        $contract = $this->contract($rulesetSettings, $facility->key);
        if ($contract === null) {
            $authored = $rulesetSettings['facility_definitions'][$facility->key] ?? null;
            if (! is_array($authored)) {
                throw new DomainException("The active Ruleset is missing facility {$facility->key}.");
            }

            return min(
                $this->positiveInteger($authored['maximum_scale'] ?? null, "{$facility->key}.maximum_scale"),
                $currentScale + $this->positiveInteger($authored['scale_increment'] ?? null, "{$facility->key}.scale_increment"),
            );
        }

        $rankOneMaximum = $this->positiveInteger(
            $contract['rank_one_maximum_scale'] ?? null,
            "{$facility->key}.rank_one_maximum_scale",
        );
        $rankTwoMaximum = $this->positiveInteger(
            $contract['rank_two_maximum_scale'] ?? null,
            "{$facility->key}.rank_two_maximum_scale",
        );
        $rankTwoIncrement = $this->positiveInteger(
            $contract['rank_two_scale_increment'] ?? null,
            "{$facility->key}.rank_two_scale_increment",
        );
        $authored = $rulesetSettings['facility_definitions'][$facility->key] ?? null;
        if (! is_array($authored)) {
            throw new DomainException("The active Ruleset is missing facility {$facility->key}.");
        }

        return $currentScale < $rankOneMaximum
            ? min($rankOneMaximum, $currentScale + $this->positiveInteger(
                $authored['scale_increment'] ?? null,
                "{$facility->key}.scale_increment",
            ))
            : min($rankTwoMaximum, $currentScale + $rankTwoIncrement);
    }

    /** @param array<string, mixed> $rulesetSettings */
    public function maximumScale(
        array $rulesetSettings,
        FacilityDefinition $facility,
    ): int {
        $contract = $this->contract($rulesetSettings, $facility->key);
        if ($contract !== null) {
            return $this->positiveInteger(
                $contract['rank_two_maximum_scale'] ?? null,
                "{$facility->key}.rank_two_maximum_scale",
            );
        }
        $authored = $rulesetSettings['facility_definitions'][$facility->key] ?? null;
        if (! is_array($authored)) {
            throw new DomainException("The active Ruleset is missing facility {$facility->key}.");
        }

        return $this->positiveInteger($authored['maximum_scale'] ?? null, "{$facility->key}.maximum_scale");
    }

    /** @param array<string, mixed> $rulesetSettings */
    public function damageScaleLoss(
        array $rulesetSettings,
        string $facilityKey,
        ?int $scale,
        string $damageKind,
    ): ?int {
        if (! $this->isRankTwo($rulesetSettings, $facilityKey, $scale)) {
            return null;
        }
        $damage = $this->contract($rulesetSettings, $facilityKey)['damage_scale_loss'] ?? null;
        if (! is_array($damage) || array_is_list($damage) || ! array_key_exists($damageKind, $damage)) {
            return null;
        }
        $loss = $damage[$damageKind];
        if (! is_int($loss) || $loss < 0) {
            throw new DomainException("Facility {$facilityKey} has an invalid {$damageKind} scale loss.");
        }

        return $loss;
    }

    /**
     * @param  array<string, mixed>  $rulesetSettings
     * @return array{name: string, asset_key: string, effect_description: string, promotion_description: string, rank: int}|null
     */
    public function presentation(
        array $rulesetSettings,
        FacilityDefinition $facility,
        int $scale,
    ): ?array {
        $contract = $this->contract($rulesetSettings, $facility->key);
        if ($contract === null) {
            return null;
        }
        $rank = $this->rank($rulesetSettings, $facility->key, $scale);

        return [
            'name' => $rank === 2
                ? $this->string($contract['rank_two_name'] ?? null, "{$facility->key}.rank_two_name")
                : $facility->name,
            'asset_key' => $rank === 2
                ? $this->string($contract['rank_two_asset_key'] ?? null, "{$facility->key}.rank_two_asset_key")
                : (string) $facility->asset_key,
            'effect_description' => $rank === 2
                ? $this->string($contract['rank_two_effect_description'] ?? null, "{$facility->key}.rank_two_effect_description")
                : '',
            'promotion_description' => $this->string(
                $contract['promotion_description'] ?? null,
                "{$facility->key}.promotion_description",
            ),
            'rank' => $rank,
        ];
    }

    /** @param array<string, mixed> $rulesetSettings */
    public function rankTwoTyphoonThreshold(array $rulesetSettings, string $facilityKey, ?int $scale): ?int
    {
        if (! $this->isRankTwo($rulesetSettings, $facilityKey, $scale)) {
            return null;
        }
        $threshold = $this->contract($rulesetSettings, $facilityKey)['typhoon_damage_threshold'] ?? null;
        if ($threshold === null) {
            return null;
        }
        if (! is_int($threshold) || $threshold < 0) {
            throw new DomainException("Facility {$facilityKey} has an invalid rank-two typhoon threshold.");
        }

        return $threshold;
    }

    private function positiveInteger(mixed $value, string $path): int
    {
        if (! is_int($value) || $value < 1) {
            throw new DomainException("Facility rank {$path} must be a positive integer.");
        }

        return $value;
    }

    private function string(mixed $value, string $path): string
    {
        if (! is_string($value) || $value === '') {
            throw new DomainException("Facility rank {$path} must be a non-empty string.");
        }

        return $value;
    }
}
