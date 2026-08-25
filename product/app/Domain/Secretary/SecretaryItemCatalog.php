<?php

namespace App\Domain\Secretary;

use DomainException;

class SecretaryItemCatalog
{
    public const OLD_BOW = 'old_bow';

    public const RING = 'ring';

    public const SECRETARY_SUIT = 'secretary_suit';

    public const INORA_BRACELET = 'inora_bracelet';

    public const HOARDER_TALISMAN = 'hoarder_talisman';

    public const GOOD_PERSON_TREASURE = 'good_person_treasure';

    public const VAULT_KEY = 'vault_key';

    public const MONSTER_REPELLENT_INCENSE = 'monster_repellent_incense';

    public const FULLNESS_HERB = 'fullness_herb';

    public const RARITY_NOVICE = 'novice';

    private const DEFAULT_SAME_ITEM_MAX_EQUIPPED = 1;

    /** @var array<string, array{label: string, maximum_equipped: int, display_limit: bool}> */
    private const CATEGORIES = [
        'accessory' => ['label' => 'アクセサリー', 'maximum_equipped' => 99, 'display_limit' => false],
        'bow' => ['label' => '弓', 'maximum_equipped' => 1, 'display_limit' => true],
        'clothing' => ['label' => '衣服', 'maximum_equipped' => 1, 'display_limit' => true],
    ];

    /**
     * @return array{
     *   key: string,
     *   category: string,
     *   category_label: string,
     *   category_max_equipped: int,
     *   rarity: string,
     *   rarity_label: string,
     *   tradable: bool,
     *   npc_tradable: bool,
     *   max_level: int,
     *   name: string,
     *   flavor_text: string,
     *   unique_per_secretary: bool,
     *   same_item_max_equipped?: int
     * }
     */
    public function definition(string $itemKey): array
    {
        $definition = $this->definitions()[$itemKey] ?? null;
        if (! is_array($definition)) {
            throw new DomainException("Unknown Secretary item {$itemKey}.");
        }

        return $definition;
    }

    /**
     * @return array<string, array{
     *   key: string,
     *   category: string,
     *   category_label: string,
     *   category_max_equipped: int,
     *   rarity: string,
     *   rarity_label: string,
     *   tradable: bool,
     *   npc_tradable: bool,
     *   max_level: int,
     *   name: string,
     *   flavor_text: string,
     *   unique_per_secretary: bool,
     *   same_item_max_equipped?: int
     * }>
     */
    public function definitions(): array
    {
        return [
            self::OLD_BOW => [
                'key' => self::OLD_BOW,
                'category' => 'bow',
                'category_label' => self::CATEGORIES['bow']['label'],
                'category_max_equipped' => self::CATEGORIES['bow']['maximum_equipped'],
                'rarity' => self::RARITY_NOVICE,
                'rarity_label' => 'ノービス',
                'tradable' => true,
                'npc_tradable' => false,
                'max_level' => 1,
                'name' => '古びた弓',
                'flavor_text' => '秘書が捕らえられていた施設の最奥から見つかった、大きく古ぼけた弓。宝石があしらわれており、どこか不思議な力を感じさせる。',
                'unique_per_secretary' => true,
            ],
            self::RING => [
                'key' => self::RING,
                'category' => 'accessory',
                'category_label' => self::CATEGORIES['accessory']['label'],
                'category_max_equipped' => self::CATEGORIES['accessory']['maximum_equipped'],
                'rarity' => self::RARITY_NOVICE,
                'rarity_label' => 'ノービス',
                'tradable' => true,
                'npc_tradable' => true,
                'max_level' => 10,
                'name' => '指輪',
                'flavor_text' => '貴金属が使われた豪華な指輪。魔法の道具ではないが、贈り物にはぴったりだ。',
                'unique_per_secretary' => false,
            ],
            self::SECRETARY_SUIT => [
                'key' => self::SECRETARY_SUIT,
                'category' => 'clothing',
                'category_label' => self::CATEGORIES['clothing']['label'],
                'category_max_equipped' => self::CATEGORIES['clothing']['maximum_equipped'],
                'rarity' => self::RARITY_NOVICE,
                'rarity_label' => 'ノービス',
                'tradable' => true,
                'npc_tradable' => true,
                'max_level' => 10,
                'name' => '秘書のスーツ',
                'flavor_text' => '秘書がはじめて袖を通した銘柄のスーツ。まだ着慣れないのか、時々恥ずかしそうにしている。',
                'unique_per_secretary' => false,
            ],
            self::INORA_BRACELET => [
                'key' => self::INORA_BRACELET,
                'category' => 'accessory',
                'category_label' => self::CATEGORIES['accessory']['label'],
                'category_max_equipped' => self::CATEGORIES['accessory']['maximum_equipped'],
                'rarity' => self::RARITY_NOVICE,
                'rarity_label' => 'ノービス',
                'tradable' => true,
                'npc_tradable' => true,
                'max_level' => 10,
                'name' => 'いのらの腕輪',
                'flavor_text' => '怪獣いのらのモチーフが刻まれた腕輪、怪獣が恐れられるこの世界でこんなものをつけたがるのは余程の物好きだろう',
                'unique_per_secretary' => false,
            ],
            self::HOARDER_TALISMAN => [
                'key' => self::HOARDER_TALISMAN,
                'category' => 'accessory',
                'category_label' => self::CATEGORIES['accessory']['label'],
                'category_max_equipped' => self::CATEGORIES['accessory']['maximum_equipped'],
                'rarity' => self::RARITY_NOVICE,
                'rarity_label' => 'ノービス',
                'tradable' => true,
                'npc_tradable' => true,
                'max_level' => 10,
                'name' => '蓄える者のタリスマン',
                'flavor_text' => '先立つものはいくらあっても損はない。使わなければいつまでも先に進めないときはあるが。',
                'unique_per_secretary' => false,
            ],
            self::GOOD_PERSON_TREASURE => [
                'key' => self::GOOD_PERSON_TREASURE,
                'category' => 'accessory',
                'category_label' => self::CATEGORIES['accessory']['label'],
                'category_max_equipped' => self::CATEGORIES['accessory']['maximum_equipped'],
                'rarity' => self::RARITY_NOVICE,
                'rarity_label' => 'ノービス',
                'tradable' => true,
                'npc_tradable' => true,
                'max_level' => 20,
                'name' => '善人の秘宝',
                'flavor_text' => '★あなたは良い心を持っている',
                'unique_per_secretary' => false,
            ],
            self::VAULT_KEY => [
                'key' => self::VAULT_KEY,
                'category' => 'accessory',
                'category_label' => self::CATEGORIES['accessory']['label'],
                'category_max_equipped' => self::CATEGORIES['accessory']['maximum_equipped'],
                'rarity' => self::RARITY_NOVICE,
                'rarity_label' => 'ノービス',
                'tradable' => true,
                'npc_tradable' => true,
                'max_level' => 10,
                'name' => '金庫の鍵',
                'flavor_text' => '決して複製できない精巧な鍵。この金庫の鍵を作らせた富豪は、暗証番号が思い出せなくて開ける事ができなくなったという。',
                'unique_per_secretary' => false,
            ],
            self::MONSTER_REPELLENT_INCENSE => [
                'key' => self::MONSTER_REPELLENT_INCENSE,
                'category' => 'accessory',
                'category_label' => self::CATEGORIES['accessory']['label'],
                'category_max_equipped' => self::CATEGORIES['accessory']['maximum_equipped'],
                'rarity' => self::RARITY_NOVICE,
                'rarity_label' => 'ノービス',
                'tradable' => true,
                'npc_tradable' => true,
                'max_level' => 10,
                'name' => '怪獣避けのお香',
                'flavor_text' => '炊けばいのらが出現しなくなると信じられているお香。なんとも言えない香りがする…',
                'unique_per_secretary' => false,
            ],
            self::FULLNESS_HERB => [
                'key' => self::FULLNESS_HERB,
                'category' => 'accessory',
                'category_label' => self::CATEGORIES['accessory']['label'],
                'category_max_equipped' => self::CATEGORIES['accessory']['maximum_equipped'],
                'rarity' => self::RARITY_NOVICE,
                'rarity_label' => 'ノービス',
                'tradable' => true,
                'npc_tradable' => true,
                'max_level' => 10,
                'name' => '満腹草',
                'flavor_text' => '万病に効くとされていた薬草は、後の時代にて消化に良い酵素が含まれていると判明した。消化は免疫力、医食同源である。',
                'unique_per_secretary' => false,
            ],
        ];
    }

    public function maximumEquipped(string $category): int
    {
        $maximums = [];
        foreach ($this->definitions() as $definition) {
            if ($definition['category'] === $category) {
                $maximums[$definition['category_max_equipped']] = true;
            }
        }
        if (count($maximums) !== 1) {
            throw new DomainException("Unknown Secretary item category {$category}.");
        }

        return (int) array_key_first($maximums);
    }

    public function sameItemMaximum(string $itemKey): int
    {
        return $this->definition($itemKey)['same_item_max_equipped']
            ?? self::DEFAULT_SAME_ITEM_MAX_EQUIPPED;
    }

    /** @return list<array{category: string, label: string, maximum_equipped: int}> */
    public function categoryLimits(): array
    {
        $limits = [];
        foreach ($this->definitions() as $definition) {
            $category = $definition['category'];
            if ((self::CATEGORIES[$category]['display_limit'] ?? true) === false) {
                continue;
            }
            $limits[$category] = [
                'category' => $category,
                'label' => $definition['category_label'],
                'maximum_equipped' => $this->maximumEquipped($category),
            ];
        }

        ksort($limits);

        return array_values($limits);
    }
}
