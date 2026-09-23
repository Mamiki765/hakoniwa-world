<?php

namespace App\Application\Underground;

use App\Domain\Nation\NationProfileText;
use DomainException;
use Normalizer;
use RuntimeException;

final class UndergroundIntroCatalog
{
    public function __construct(private readonly UndergroundEquipmentCatalog $equipment = new UndergroundEquipmentCatalog) {}

    public function identity(): string
    {
        $identity = $this->data()['story_identity'] ?? null;

        return is_string($identity) && $identity !== ''
            ? $identity
            : throw new RuntimeException('Underground intro identity is missing.');
    }

    /** @return array<string, array{name: string, price: int, capacity_before?: int, capacity_after?: int}> */
    public function residence(): array
    {
        $items = $this->data()['residence'] ?? null;
        if (! is_array($items)) {
            throw new RuntimeException('Underground residence configuration is missing.');
        }
        $result = [];
        foreach (['villa', 'mirror', 'trophy_shelf', 'vault_expansion', 'resonance_expansion'] as $key) {
            $item = $items[$key] ?? null;
            if (! is_array($item) || ! is_string($item['name'] ?? null)
                || ! is_int($item['price'] ?? null) || $item['price'] < 1) {
                throw new RuntimeException('Underground residence configuration is invalid.');
            }
            $result[$key] = ['name' => $item['name'], 'price' => $item['price']];
            if (in_array($key, ['vault_expansion', 'resonance_expansion'], true)) {
                $inventory = $key === 'resonance_expansion' ? 'resonance' : 'equipment';
                $result[$key]['capacity_before'] = $this->equipment->vaultCapacity($inventory);
                $result[$key]['capacity_after'] = $this->equipment->vaultCapacity($inventory, true);
            }
        }

        return $result;
    }

    /** @return non-empty-list<int> */
    public function distortedStoneDailyPrices(): array
    {
        $prices = $this->data()['distorted_stone_daily_prices'] ?? null;
        if (! is_array($prices) || ! array_is_list($prices) || $prices === []) {
            throw new RuntimeException('Distorted stone daily prices are missing.');
        }
        foreach ($prices as $price) {
            if (! is_int($price) || $price < 0) {
                throw new RuntimeException('Distorted stone daily price is invalid.');
            }
        }

        return $prices;
    }

    /** @return array<string, mixed> */
    public function recollections(): array
    {
        $recollections = $this->data()['recollections'] ?? null;
        if (! is_array($recollections)
            || ! is_string($recollections['identity'] ?? null)
            || $recollections['identity'] === ''
            || ! is_array($recollections['past'] ?? null)
            || ! is_array($recollections['serious_talk'] ?? null)
            || ! is_array($recollections['history'] ?? null)) {
            throw new RuntimeException('Underground recollection configuration is invalid.');
        }

        return $recollections;
    }

    /**
     * @return array{
     *   activity_type: string, activity_key: string, encounter_key: string, display_name: string,
     *   seed: int, max_rounds: int, expected_winner: string, xp_reward: int, shard_reward: int,
     *   actor: array<string, mixed>, loadout: list<string>, enemy: array<string, mixed>
     * }
     */
    public function battle(string $key): array
    {
        $battles = $this->data()['battles'] ?? null;
        $battle = is_array($battles) ? ($battles[$key] ?? null) : null;
        if (! is_array($battle)) {
            throw new RuntimeException("Unknown Underground intro battle [{$key}].");
        }
        foreach (['activity_type', 'activity_key', 'encounter_key', 'display_name', 'expected_winner'] as $field) {
            if (! is_string($battle[$field] ?? null) || $battle[$field] === '') {
                throw new RuntimeException("Underground intro battle [{$key}] is invalid.");
            }
        }
        foreach (['seed', 'max_rounds', 'xp_reward', 'shard_reward'] as $field) {
            if (! is_int($battle[$field] ?? null) || $battle[$field] < 0) {
                throw new RuntimeException("Underground intro battle [{$key}] is invalid.");
            }
        }
        if (! is_array($battle['actor'] ?? null)
            || ! is_array($battle['enemy'] ?? null)
            || ! is_array($battle['loadout'] ?? null)
            || ! array_is_list($battle['loadout'])) {
            throw new RuntimeException("Underground intro battle [{$key}] is invalid.");
        }
        $loadout = [];
        foreach ($battle['loadout'] as $skillKey) {
            if (! is_string($skillKey) || $skillKey === '') {
                throw new RuntimeException("Underground intro battle [{$key}] is invalid.");
            }
            $loadout[] = $skillKey;
        }

        return [
            'activity_type' => $battle['activity_type'],
            'activity_key' => $battle['activity_key'],
            'encounter_key' => $battle['encounter_key'],
            'display_name' => $battle['display_name'],
            'seed' => $battle['seed'],
            'max_rounds' => $battle['max_rounds'],
            'expected_winner' => $battle['expected_winner'],
            'xp_reward' => $battle['xp_reward'],
            'shard_reward' => $battle['shard_reward'],
            'actor' => $battle['actor'],
            'loadout' => $loadout,
            'enemy' => $battle['enemy'],
        ];
    }

    public function normalizeShopkeeperName(string $value): string
    {
        if (! mb_check_encoding($value, 'UTF-8')
            || preg_match(NationProfileText::SINGLE_LINE_PATTERN, $value) !== 1) {
            throw new DomainException('名前に改行や制御文字は使用できません。');
        }
        $value = NationProfileText::trimSpaces($value);
        if (preg_match('/<\s*\/?\s*[A-Za-z][^>]*>/u', $value) === 1) {
            throw new DomainException('名前にHTMLは使用できません。');
        }
        $length = grapheme_strlen($value);
        if ($length === false || $length < 1 || $length > $this->maximumNameGraphemes()) {
            throw new DomainException('名前は1文字以上20文字以下で入力してください。');
        }

        return $value;
    }

    public function branchIdentity(string $name): string
    {
        $comparisonName = Normalizer::normalize($name, Normalizer::FORM_C);
        if (! is_string($comparisonName)) {
            throw new RuntimeException('Underground hidden naming normalization failed.');
        }
        $nameConfig = $this->data()['shopkeeper_name'] ?? null;
        $aliases = is_array($nameConfig) ? ($nameConfig['true_name_aliases'] ?? null) : null;
        if (! is_array($aliases) || ! array_is_list($aliases)) {
            throw new RuntimeException('Underground hidden naming contract is invalid.');
        }
        foreach ($aliases as $alias) {
            if (! is_string($alias) || $alias === '') {
                throw new RuntimeException('Underground hidden naming contract is invalid.');
            }
            $comparisonAlias = Normalizer::normalize($alias, Normalizer::FORM_C);
            if (! is_string($comparisonAlias)) {
                throw new RuntimeException('Underground hidden naming normalization failed.');
            }
            if (hash_equals($comparisonAlias, $comparisonName)) {
                return 'true_name';
            }
        }

        if (preg_match('/\A雨宮[ \x{3000}]+利香\z/u', $comparisonName) === 1) {
            return 'true_name';
        }

        return 'normal';
    }

    private function maximumNameGraphemes(): int
    {
        $nameConfig = $this->data()['shopkeeper_name'] ?? null;
        $maximum = is_array($nameConfig) ? ($nameConfig['maximum_graphemes'] ?? null) : null;

        return is_int($maximum) && $maximum === 20
            ? $maximum
            : throw new RuntimeException('Underground shopkeeper name contract is invalid.');
    }

    /** @return array<string, mixed> */
    private function data(): array
    {
        $data = config('underground-intro');
        if (! is_array($data) || ($data['schema_version'] ?? null) !== 2) {
            throw new RuntimeException('Underground intro configuration is invalid.');
        }

        return $data;
    }
}
