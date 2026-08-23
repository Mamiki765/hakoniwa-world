<?php

namespace App\Domain\Turn;

use InvalidArgumentException;

final class TurnRandomStreamFactory
{
    public const DERIVATION_VERSION = 'hakoniwa-turn-random-stream-v1';

    public const DEVELOPMENT_NATION_ORDER = 'development_commands:nation_order';

    public const SURFACE_CELL_ORDER = 'process_cells:surface_cell_order';

    public const TERRITORY_INFLUENCE_DIRECTION = 'territory_influence:direction:v1';

    public const LAND_CLEAR_BURIED_TREASURE = 'development_commands:land_clear:buried_treasure';

    public const SEABED_OIL_SEARCH = 'development_commands:excavate:seabed_oil_search';

    public const SETTLEMENT_APPEARANCE = 'process_cells:settlement_appearance';

    public const POPULATION_GROWTH = 'process_cells:population_growth';

    public const FAMINE_POPULATION_LOSS = 'process_cells:famine_population_loss';

    public const FACILITY_RIOT = 'process_cells:facility_riot';

    public const FIRE = 'process_cells:fire:v1';

    public const OIL_DEPLETION = 'process_cells:oil_depletion:v1';

    public const LAND_LEVEL_EARTHQUAKE_TRIGGER = 'development_commands:land_level:earthquake:trigger:v1';

    public const LAND_LEVEL_EARTHQUAKE_EFFECT = 'development_commands:land_level:earthquake:effect:v1';

    public const GLOBAL_EARTHQUAKE_TRIGGER = 'global_disasters:earthquake:trigger:v1';

    public const GLOBAL_EARTHQUAKE_CENTER = 'global_disasters:earthquake:center:v1';

    public const GLOBAL_EARTHQUAKE_EFFECT = 'global_disasters:earthquake:effect:v1';

    public const GLOBAL_TSUNAMI_TRIGGER = 'global_disasters:tsunami:trigger:v1';

    public const GLOBAL_TSUNAMI_CENTER = 'global_disasters:tsunami:center:v1';

    public const GLOBAL_TSUNAMI_EFFECT = 'global_disasters:tsunami:effect:v1';

    public const GLOBAL_TYPHOON_TRIGGER = 'global_disasters:typhoon:trigger:v1';

    public const GLOBAL_TYPHOON_CENTER = 'global_disasters:typhoon:center:v1';

    public const GLOBAL_TYPHOON_EFFECT = 'global_disasters:typhoon:effect:v1';

    public const GLOBAL_METEOR_SHOWER_TRIGGER = 'global_disasters:meteor_shower:trigger:v1';

    public const GLOBAL_METEOR_SHOWER_CENTER = 'global_disasters:meteor_shower:center:v1';

    public const GLOBAL_METEOR_SHOWER_EFFECT = 'global_disasters:meteor_shower:effect:v1';

    public const GLOBAL_HUGE_METEOR_TRIGGER = 'global_disasters:huge_meteor:trigger:v1';

    public const GLOBAL_HUGE_METEOR_CENTER = 'global_disasters:huge_meteor:center:v1';

    public const GLOBAL_HUGE_METEOR_EFFECT = 'global_disasters:huge_meteor:effect:v1';

    public const GLOBAL_ERUPTION_TRIGGER = 'global_disasters:eruption:trigger:v1';

    public const GLOBAL_ERUPTION_CENTER = 'global_disasters:eruption:center:v1';

    public const GLOBAL_ERUPTION_EFFECT = 'global_disasters:eruption:effect:v1';

    private const LAND_SUBSIDENCE_TRIGGER_PREFIX = 'global_disasters:land_subsidence:nation:';

    private const WORLD_DISASTER_AREA_FRACTION_PREFIX = 'global_disasters:world_area_fraction:';

    private const MONSTER_MOVEMENT_PREFIX = 'process_cells:monster:';

    private const MONSTER_SPAWN_PREFIX = 'global_disasters:monster_spawn:nation:';

    private const MONSTER_DISPATCH_PREFIX = 'development_commands:monster_dispatch:item:';

    private const MONSTER_WORLD_SPAWN_PREFIX = 'global_disasters:aoi_inora:';

    private const MISSILE_IMPACT_PREFIX = 'development_commands:missile:item:';

    private const KARMA_SANCTION_PREFIX = 'settle_deferred_effects:karma_sanction:nation:';

    private const MONUMENT_FLIGHT_PREFIX = 'development_commands:monument:item:';

    private const SECRETARY_OLD_BOW_PREFIX = 'secretary_item:old_bow:nation:';

    /** @var array<string, DeterministicRandomStream> */
    private array $streams = [];

    private readonly string $masterSeedBytes;

    public function __construct(public readonly string $masterSeed)
    {
        if (preg_match('/\A[0-9a-f]{64}\z/D', $masterSeed) !== 1) {
            throw new InvalidArgumentException('Turn master seed must be 64 lowercase hexadecimal characters.');
        }

        $decoded = hex2bin($masterSeed);
        if (! is_string($decoded) || strlen($decoded) !== 32) {
            throw new InvalidArgumentException('Turn master seed must decode to exactly 256 bits.');
        }

        $this->masterSeedBytes = $decoded;
    }

    public function stream(string $label): DeterministicRandomStream
    {
        if ($label === '') {
            throw new InvalidArgumentException('Turn random stream label must not be empty.');
        }
        if (preg_match('//u', $label) !== 1) {
            throw new InvalidArgumentException('Turn random stream label must be valid UTF-8.');
        }

        return $this->streams[$label] ??= new DeterministicRandomStream(
            hash_hmac(
                'sha256',
                self::DERIVATION_VERSION."\0".$label,
                $this->masterSeedBytes,
                true,
            ),
        );
    }

    public static function landSubsidenceTrigger(int $nationId, int $streamVersion): string
    {
        if ($nationId < 1 || $streamVersion < 1) {
            throw new InvalidArgumentException('Land-subsidence stream identity must use positive integers.');
        }

        return self::LAND_SUBSIDENCE_TRIGGER_PREFIX.$nationId.':trigger:v'.$streamVersion;
    }

    public static function worldDisasterAreaFraction(string $disasterKey): string
    {
        if (! in_array($disasterKey, [
            'earthquake', 'tsunami', 'typhoon', 'meteor_shower', 'huge_meteor', 'eruption',
        ], true)) {
            throw new InvalidArgumentException('World-disaster area stream key is invalid.');
        }

        return self::WORLD_DISASTER_AREA_FRACTION_PREFIX.$disasterKey.':v1';
    }

    public static function monsterMovement(int $monsterId, int $streamVersion): string
    {
        if ($monsterId < 1 || $streamVersion < 1) {
            throw new InvalidArgumentException('Monster-movement stream identity must use positive integers.');
        }

        return self::MONSTER_MOVEMENT_PREFIX.$monsterId.':movement:v'.$streamVersion;
    }

    public static function monsterSpawn(int $nationId, string $purpose, int $streamVersion): string
    {
        if ($nationId < 1 || $streamVersion < 1
            || ! in_array($purpose, ['trigger', 'candidate', 'type', 'hp'], true)) {
            throw new InvalidArgumentException('Monster-spawn stream identity is invalid.');
        }

        return self::MONSTER_SPAWN_PREFIX.$nationId.':'.$purpose.':v'.$streamVersion;
    }

    public static function monsterDispatch(int $queueItemId): string
    {
        if ($queueItemId < 1) {
            throw new InvalidArgumentException('Monster-dispatch stream identity must use a positive queue item ID.');
        }

        return self::MONSTER_DISPATCH_PREFIX.$queueItemId.':candidate:v1';
    }

    public static function monsterWorldSpawn(string $purpose, int $streamVersion): string
    {
        if ($streamVersion < 1 || ! in_array($purpose, ['trigger', 'candidate', 'hp'], true)) {
            throw new InvalidArgumentException('World monster-spawn stream identity is invalid.');
        }

        return self::MONSTER_WORLD_SPAWN_PREFIX.$purpose.':v'.$streamVersion;
    }

    public static function missileImpact(int $queueItemId): string
    {
        if ($queueItemId < 1) {
            throw new InvalidArgumentException('Missile-impact stream identity must use a positive queue item ID.');
        }

        return self::MISSILE_IMPACT_PREFIX.$queueItemId.':deviation:v1';
    }

    public static function karmaSanction(int $nationId, int $streamVersion): string
    {
        if ($nationId < 1 || $streamVersion < 1) {
            throw new InvalidArgumentException('KARMA-sanction stream identity must use positive integers.');
        }

        return self::KARMA_SANCTION_PREFIX.$nationId.':target:v'.$streamVersion;
    }

    public static function monumentFlight(int $queueItemId): string
    {
        if ($queueItemId < 1) {
            throw new InvalidArgumentException('Monument-flight stream identity must use a positive queue item ID.');
        }

        return self::MONUMENT_FLIGHT_PREFIX.$queueItemId.':target:v1';
    }

    public static function secretaryOldBow(int $nationId, string $purpose, int $streamVersion): string
    {
        if ($nationId < 1 || $streamVersion < 1 || ! in_array($purpose, ['trigger', 'target'], true)) {
            throw new InvalidArgumentException('Secretary Old Bow stream identity is invalid.');
        }

        return self::SECRETARY_OLD_BOW_PREFIX.$nationId.':'.$purpose.':v'.$streamVersion;
    }
}
