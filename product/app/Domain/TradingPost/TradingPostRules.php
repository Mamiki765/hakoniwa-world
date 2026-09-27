<?php

namespace App\Domain\TradingPost;

use DomainException;

final readonly class TradingPostRules
{
    /**
     * @param  list<string>  $npcResourceKeys
     */
    private function __construct(
        public int $activeListingLimit,
        public int $minimumDurationTurns,
        public int $maximumDurationTurns,
        public int $minimumIncrementMoney,
        public int $sellerProceedsNumerator,
        public int $sellerProceedsDenominator,
        public string $npcSellerKey,
        public string $npcSellerName,
        public int $npcDurationTurns,
        public int $npcAttemptsPerTurn,
        public int $npcProbabilityNumerator,
        public int $npcProbabilityDenominator,
        public int $npcResourceLimit,
        public int $npcItemLimit,
        public array $npcResourceKeys,
        public int $npcResourceValueMinimum,
        public int $npcResourceValueMaximum,
        public int $npcResourcePricePercentMinimum,
        public int $npcResourcePricePercentMaximum,
        public string $npcItemRarity,
        public int $npcItemLevelMinimum,
        public int $npcItemLevelMaximum,
        public int $npcItemPriceMoneyPerLevel,
        public int $npcRandomStreamVersion,
    ) {}

    /** @param array<string, mixed> $settings */
    public static function fromSettings(array $settings): self
    {
        $rules = self::map($settings['trading_post'] ?? null, 'ruleset.trading_post');
        self::exactKeys($rules, ['player', 'npc'], 'ruleset.trading_post');
        $player = self::map($rules['player'] ?? null, 'ruleset.trading_post.player');
        self::exactKeys($player, [
            'active_listing_limit', 'minimum_duration_turns', 'maximum_duration_turns',
            'minimum_increment_money', 'seller_proceeds_numerator', 'seller_proceeds_denominator',
            'seller_proceeds_rounding', 'fee_behavior',
        ], 'ruleset.trading_post.player');
        $activeLimit = self::positiveInt($player, 'active_listing_limit');
        $minimumDuration = self::positiveInt($player, 'minimum_duration_turns');
        $maximumDuration = self::positiveInt($player, 'maximum_duration_turns');
        $minimumIncrement = self::positiveInt($player, 'minimum_increment_money');
        $sellerNumerator = self::positiveInt($player, 'seller_proceeds_numerator');
        $sellerDenominator = self::positiveInt($player, 'seller_proceeds_denominator');
        if ($maximumDuration < $minimumDuration || $sellerNumerator > $sellerDenominator
            || ($player['seller_proceeds_rounding'] ?? null) !== 'floor'
            || ($player['fee_behavior'] ?? null) !== 'discard_remainder_on_sale') {
            throw new DomainException('ruleset.trading_post.player has invalid ranges or behavior.');
        }

        $npc = self::map($rules['npc'] ?? null, 'ruleset.trading_post.npc');
        self::exactKeys($npc, [
            'seller_key', 'seller_name', 'duration_turns', 'attempts_per_turn', 'listing_probability',
            'active_resource_limit', 'active_item_limit', 'resource_keys', 'resource_base_value_money',
            'resource_start_price_percent', 'item_rarity', 'item_level', 'item_price_money_per_level',
            'random_stream_version',
        ], 'ruleset.trading_post.npc');
        $probability = self::map($npc['listing_probability'] ?? null, 'ruleset.trading_post.npc.listing_probability');
        $value = self::map($npc['resource_base_value_money'] ?? null, 'ruleset.trading_post.npc.resource_base_value_money');
        $price = self::map($npc['resource_start_price_percent'] ?? null, 'ruleset.trading_post.npc.resource_start_price_percent');
        $level = self::map($npc['item_level'] ?? null, 'ruleset.trading_post.npc.item_level');
        self::exactKeys($probability, ['numerator', 'denominator'], 'ruleset.trading_post.npc.listing_probability');
        self::exactKeys($value, ['minimum', 'maximum'], 'ruleset.trading_post.npc.resource_base_value_money');
        self::exactKeys($price, ['minimum', 'maximum'], 'ruleset.trading_post.npc.resource_start_price_percent');
        self::exactKeys($level, ['minimum', 'maximum'], 'ruleset.trading_post.npc.item_level');
        $npcDuration = self::positiveInt($npc, 'duration_turns');
        $npcAttempts = self::positiveInt($npc, 'attempts_per_turn');
        $probabilityNumerator = self::nonNegativeInt($probability, 'numerator');
        $probabilityDenominator = self::positiveInt($probability, 'denominator');
        $resourceLimit = self::nonNegativeInt($npc, 'active_resource_limit');
        $itemLimit = self::nonNegativeInt($npc, 'active_item_limit');
        $valueMinimum = self::positiveInt($value, 'minimum');
        $valueMaximum = self::positiveInt($value, 'maximum');
        $priceMinimum = self::positiveInt($price, 'minimum');
        $priceMaximum = self::positiveInt($price, 'maximum');
        $levelMinimum = self::positiveInt($level, 'minimum');
        $levelMaximum = self::positiveInt($level, 'maximum');
        $itemPricePerLevel = self::positiveInt($npc, 'item_price_money_per_level');
        $resources = $npc['resource_keys'] ?? null;
        $expectedResources = ['wheat', 'fish', 'monster_meat', 'industrial_goods', 'minerals', 'oil'];
        if ($npcDuration < $minimumDuration || $npcDuration > $maximumDuration
            || $probabilityNumerator > $probabilityDenominator
            || $valueMaximum < $valueMinimum || $priceMaximum < $priceMinimum
            || $levelMaximum < $levelMinimum) {
            throw new DomainException('ruleset.trading_post.npc has invalid ranges.');
        }
        if (($npc['seller_key'] ?? null) !== 'hakoniwa_federation'
            || ($npc['seller_name'] ?? null) !== '箱庭連合'
            || $resources !== $expectedResources
            || ($npc['item_rarity'] ?? null) !== 'novice'
            || ($npc['random_stream_version'] ?? null) !== 1) {
            throw new DomainException('ruleset.trading_post.npc differs from the v16 trading contract.');
        }
        $definitions = $settings['resource_definitions'] ?? null;
        $saleRates = $settings['inventory_sale_rates'] ?? null;
        if (! is_array($definitions) || ! is_array($saleRates)) {
            throw new DomainException('ruleset.trading_post NPC resources require authored definitions and sale rates.');
        }
        $definitionsByKey = [];
        foreach ($definitions as $definition) {
            if (is_array($definition) && is_string($definition['key'] ?? null)) {
                $definitionsByKey[$definition['key']] = $definition;
            }
        }
        foreach ($expectedResources as $resourceKey) {
            $definition = $definitionsByKey[$resourceKey] ?? null;
            $rate = $saleRates[$resourceKey] ?? null;
            if (! is_array($definition) || ($definition['tradable'] ?? null) !== true
                || ! is_array($rate) || ! is_int($rate['inventory_units'] ?? null)
                || ! is_int($rate['money_units'] ?? null) || $rate['inventory_units'] < 1
                || $rate['money_units'] < 1) {
                throw new DomainException("ruleset.trading_post NPC resource {$resourceKey} is not tradable at a valid sale rate.");
            }
        }

        return new self(
            activeListingLimit: $activeLimit,
            minimumDurationTurns: $minimumDuration,
            maximumDurationTurns: $maximumDuration,
            minimumIncrementMoney: $minimumIncrement,
            sellerProceedsNumerator: $sellerNumerator,
            sellerProceedsDenominator: $sellerDenominator,
            npcSellerKey: 'hakoniwa_federation',
            npcSellerName: '箱庭連合',
            npcDurationTurns: $npcDuration,
            npcAttemptsPerTurn: $npcAttempts,
            npcProbabilityNumerator: $probabilityNumerator,
            npcProbabilityDenominator: $probabilityDenominator,
            npcResourceLimit: $resourceLimit,
            npcItemLimit: $itemLimit,
            npcResourceKeys: $expectedResources,
            npcResourceValueMinimum: $valueMinimum,
            npcResourceValueMaximum: $valueMaximum,
            npcResourcePricePercentMinimum: $priceMinimum,
            npcResourcePricePercentMaximum: $priceMaximum,
            npcItemRarity: 'novice',
            npcItemLevelMinimum: $levelMinimum,
            npcItemLevelMaximum: $levelMaximum,
            npcItemPriceMoneyPerLevel: $itemPricePerLevel,
            npcRandomStreamVersion: 1,
        );
    }

    /** @return array<string, mixed> */
    private static function map(mixed $value, string $path): array
    {
        if (! is_array($value) || array_is_list($value)) {
            throw new DomainException("{$path} must be an object map.");
        }

        return $value;
    }

    /** @param array<string, mixed> $settings */
    private static function positiveInt(array $settings, string $key): int
    {
        $value = $settings[$key] ?? null;
        if (! is_int($value) || $value < 1) {
            throw new DomainException("ruleset.trading_post.{$key} must be a positive integer.");
        }

        return $value;
    }

    /** @param array<string, mixed> $settings */
    private static function nonNegativeInt(array $settings, string $key): int
    {
        $value = $settings[$key] ?? null;
        if (! is_int($value) || $value < 0) {
            throw new DomainException("ruleset.trading_post.{$key} must be a non-negative integer.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  list<string>  $expected
     */
    private static function exactKeys(array $value, array $expected, string $path): void
    {
        $actual = array_keys($value);
        sort($actual);
        sort($expected);
        if ($actual !== $expected) {
            throw new DomainException("{$path} contains missing or unknown fields.");
        }
    }
}
