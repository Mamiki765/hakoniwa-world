<?php

namespace App\Domain\Secretary;

use DomainException;

class SecretaryItemCatalog
{
    public const OLD_BOW = 'old_bow';

    public const ELF_BOW = 'elf_bow';

    public const LONGSHOT_BOW = 'longshot_bow';

    public const MECHANICAL_BOW = 'mechanical_bow';

    public const COLLAR = 'collar';

    public const RING = 'ring';

    public const SECRETARY_SUIT = 'secretary_suit';

    public const INORA_BRACELET = 'inora_bracelet';

    public const HOARDER_TALISMAN = 'hoarder_talisman';

    public const GOOD_PERSON_TREASURE = 'good_person_treasure';

    public const VAULT_KEY = 'vault_key';

    public const MONSTER_REPELLENT_INCENSE = 'monster_repellent_incense';

    public const FULLNESS_HERB = 'fullness_herb';

    public const WAKUWAKU_TICKET = 'wakuwaku_ticket';

    public const DOKIDOKI_TICKET = 'dokidoki_ticket';

    public const LOVE_EMBLEM = 'love_emblem';

    public const TWIN_STAR_EMBLEM = 'twin_star_emblem';

    public const CRESCENT_EMBLEM = 'crescent_emblem';

    public const NYOWAMIYA_RIBBON = 'nyowamiya_ribbon';

    public const RARITY_NOVICE = 'novice';

    public const RARITY_REGULAR = 'regular';

    public const RARITY_CURSED = 'cursed';

    public const RARITY_HIGH_QUALITY = 'high_quality';

    public const RARITY_ARTIFACT = 'artifact';

    public const RARITY_RELIC = 'relic';

    /** @var array<string, array{label: string, fixed_sale_price_money: int}> */
    private const RARITIES = [
        self::RARITY_NOVICE => ['label' => 'ノービス', 'fixed_sale_price_money' => 100],
        self::RARITY_REGULAR => ['label' => 'レギュラー', 'fixed_sale_price_money' => 500],
        self::RARITY_CURSED => ['label' => 'カースド', 'fixed_sale_price_money' => 1],
        self::RARITY_HIGH_QUALITY => ['label' => 'ハイクオリティ', 'fixed_sale_price_money' => 1500],
        self::RARITY_ARTIFACT => ['label' => 'アーティファクト', 'fixed_sale_price_money' => 3000],
        self::RARITY_RELIC => ['label' => 'レリック', 'fixed_sale_price_money' => 6000],
    ];

    private const DEFAULT_SAME_ITEM_MAX_EQUIPPED = 1;

    /** @var array<string, array{label: string, maximum_equipped: int, display_limit: bool}> */
    private const CATEGORIES = [
        'accessory' => ['label' => 'アクセサリー', 'maximum_equipped' => 99, 'display_limit' => false],
        'bow' => ['label' => '弓', 'maximum_equipped' => 1, 'display_limit' => true],
        'clothing' => ['label' => '衣服', 'maximum_equipped' => 1, 'display_limit' => true],
        'ticket' => ['label' => 'チケット', 'maximum_equipped' => 0, 'display_limit' => false],
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
     *   fixed_sale_price_money: int,
     *   same_item_max_equipped?: int,
     *   introduced_version?: int
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
     * @return array<string, mixed>
     */
    public function definitionWithResolvedEconomics(
        string $itemKey,
        ?string $resolvedRarity,
        ?int $resolvedFixedSalePriceMoney,
    ): array {
        $definition = $this->definition($itemKey);
        if ($resolvedRarity === null && $resolvedFixedSalePriceMoney === null) {
            return $definition;
        }
        if ($resolvedRarity === null || $resolvedRarity === ''
            || $resolvedFixedSalePriceMoney === null || $resolvedFixedSalePriceMoney < 0
            || ! isset(self::RARITIES[$resolvedRarity])) {
            throw new DomainException("Secretary item {$itemKey} has invalid resolved economics.");
        }

        return [
            ...$definition,
            'rarity' => $resolvedRarity,
            'rarity_label' => self::RARITIES[$resolvedRarity]['label'],
            'fixed_sale_price_money' => $resolvedFixedSalePriceMoney,
        ];
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
     *   fixed_sale_price_money: int,
     *   same_item_max_equipped?: int,
     *   introduced_version?: int
     * }>
     */
    public function definitions(): array
    {
        $definitions = [
            self::OLD_BOW => [
                'key' => self::OLD_BOW,
                'category' => 'bow',
                'category_label' => self::CATEGORIES['bow']['label'],
                'category_max_equipped' => self::CATEGORIES['bow']['maximum_equipped'],
                'rarity' => self::RARITY_NOVICE,
                'rarity_label' => self::RARITIES[self::RARITY_NOVICE]['label'],
                'tradable' => false,
                'npc_tradable' => false,
                'max_level' => 1,
                'name' => '古びた弓',
                'flavor_text' => '秘書が捕らえられていた施設の最奥から見つかった、大きく古ぼけた弓。宝石があしらわれており、どこか不思議な力を感じさせる。',
                'unique_per_secretary' => true,
                'fixed_sale_price_money' => self::RARITIES[self::RARITY_NOVICE]['fixed_sale_price_money'],
            ],
            self::ELF_BOW => [
                'key' => self::ELF_BOW,
                'category' => 'bow',
                'category_label' => self::CATEGORIES['bow']['label'],
                'category_max_equipped' => self::CATEGORIES['bow']['maximum_equipped'],
                'rarity' => self::RARITY_REGULAR,
                'rarity_label' => self::RARITIES[self::RARITY_REGULAR]['label'],
                'tradable' => true,
                'npc_tradable' => false,
                'max_level' => 10,
                'name' => 'エルフの弓',
                'flavor_text' => '永い時を生きた古木の材で継ぎ直された弓。エルフの魔力との親和性が高い。',
                'unique_per_secretary' => false,
                'fixed_sale_price_money' => self::RARITIES[self::RARITY_REGULAR]['fixed_sale_price_money'],
            ],
            self::LONGSHOT_BOW => [
                'key' => self::LONGSHOT_BOW,
                'category' => 'bow',
                'category_label' => self::CATEGORIES['bow']['label'],
                'category_max_equipped' => self::CATEGORIES['bow']['maximum_equipped'],
                'rarity' => self::RARITY_REGULAR,
                'rarity_label' => self::RARITIES[self::RARITY_REGULAR]['label'],
                'tradable' => true,
                'npc_tradable' => false,
                'max_level' => 10,
                'name' => '遠当ての弓',
                'flavor_text' => 'アクアマリンの飾りがついた木製の弓。海の怪獣に効果がありそうだ。',
                'unique_per_secretary' => false,
                'fixed_sale_price_money' => self::RARITIES[self::RARITY_REGULAR]['fixed_sale_price_money'],
            ],
            self::MECHANICAL_BOW => [
                'key' => self::MECHANICAL_BOW,
                'category' => 'bow',
                'category_label' => self::CATEGORIES['bow']['label'],
                'category_max_equipped' => self::CATEGORIES['bow']['maximum_equipped'],
                'rarity' => self::RARITY_REGULAR,
                'rarity_label' => self::RARITIES[self::RARITY_REGULAR]['label'],
                'tradable' => true,
                'npc_tradable' => false,
                'max_level' => 10,
                'name' => '機械弓',
                'flavor_text' => '特殊なからくりによって、通常より強く弦を引き絞れるよう補強された弓。扱いは難しいが、力を込めた強烈な一撃を放てる。',
                'unique_per_secretary' => false,
                'fixed_sale_price_money' => self::RARITIES[self::RARITY_REGULAR]['fixed_sale_price_money'],
            ],
            self::COLLAR => [
                'key' => self::COLLAR,
                'category' => 'accessory',
                'category_label' => self::CATEGORIES['accessory']['label'],
                'category_max_equipped' => self::CATEGORIES['accessory']['maximum_equipped'],
                'rarity' => self::RARITY_CURSED,
                'rarity_label' => self::RARITIES[self::RARITY_CURSED]['label'],
                'tradable' => true,
                'npc_tradable' => false,
                'max_level' => 11,
                'name' => '首輪',
                'flavor_text' => 'それをつけさせるのは狂った愛か支配欲か、はたまた自分の傍から離したくないと思わせるエルフの魔性なのか。',
                'unique_per_secretary' => false,
                'fixed_sale_price_money' => self::RARITIES[self::RARITY_CURSED]['fixed_sale_price_money'],
            ],
            self::RING => [
                'key' => self::RING,
                'category' => 'accessory',
                'category_label' => self::CATEGORIES['accessory']['label'],
                'category_max_equipped' => self::CATEGORIES['accessory']['maximum_equipped'],
                'rarity' => self::RARITY_NOVICE,
                'rarity_label' => self::RARITIES[self::RARITY_NOVICE]['label'],
                'tradable' => true,
                'npc_tradable' => true,
                'max_level' => 10,
                'name' => '指輪',
                'flavor_text' => '貴金属が使われた豪華な指輪。魔法の道具ではないが、贈り物にはぴったりだ。',
                'unique_per_secretary' => false,
                'fixed_sale_price_money' => self::RARITIES[self::RARITY_NOVICE]['fixed_sale_price_money'],
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
            self::WAKUWAKU_TICKET => [
                'key' => self::WAKUWAKU_TICKET,
                'category' => 'ticket',
                'category_label' => self::CATEGORIES['ticket']['label'],
                'category_max_equipped' => self::CATEGORIES['ticket']['maximum_equipped'],
                'rarity' => self::RARITY_REGULAR,
                'rarity_label' => 'レギュラー',
                'tradable' => true,
                'npc_tradable' => false,
                'max_level' => 1,
                'name' => 'わくわくチケット',
                'flavor_text' => '海や空から届いた、小さな宝箱を開けるためのチケット。',
                'unique_per_secretary' => false,
            ],
            self::DOKIDOKI_TICKET => [
                'key' => self::DOKIDOKI_TICKET,
                'category' => 'ticket',
                'category_label' => self::CATEGORIES['ticket']['label'],
                'category_max_equipped' => self::CATEGORIES['ticket']['maximum_equipped'],
                'rarity' => self::RARITY_HIGH_QUALITY,
                'rarity_label' => 'ハイクオリティ',
                'tradable' => true,
                'npc_tradable' => false,
                'max_level' => 1,
                'name' => 'ドキドキチケット',
                'flavor_text' => '宝船や巨大隕石から見つかる、特別な宝箱を開けるためのチケット。',
                'unique_per_secretary' => false,
            ],
        ];
        $newItems = [
            'fire_charm' => ['accessory', self::RARITY_REGULAR, 3, '防火のお守り', 'マッチ一本火事の元。いくらお金がかかっても火災保険は入っておくように。絶対というものはこの世に存在しないのだから。'],
            'wave_charm' => ['accessory', self::RARITY_REGULAR, 3, '防波のお守り', 'お守りの輝石は、波から領域を護るバリアを生み出すのだとか。'],
            'wind_charm' => ['accessory', self::RARITY_REGULAR, 3, '防風のお守り', '「台風がそんなに怖いの？　農場しか被害受けないから安いモンじゃない」'],
            'quake_charm' => ['accessory', self::RARITY_REGULAR, 3, '耐震のお守り', 'あと1回……1回だけでいいから地ならしさせて……'],
            'star_charm' => ['accessory', self::RARITY_HIGH_QUALITY, 3, '防星のお守り', 'あの星たちは、いったいどこから来るのだろう？　落ちた星は、この惑星の一部にどうなっていくのだろう？'],
            'gem_bow' => ['bow', self::RARITY_HIGH_QUALITY, 15, '輝石の弓', 'エルフの真髄、宝石魔法を引き出しやすく調整された弓。秘書と特に相性の良い輝石が選び抜かれている。'],
            'elven_bow' => ['bow', self::RARITY_ARTIFACT, 50, 'エルヴンボウ', '数千年を生きた大樹と地底から掘り出した輝石を贅沢に用いて作られた逸品。その一矢は、天災を齎す。'],
            'aquamarine_bow' => ['bow', self::RARITY_HIGH_QUALITY, 15, 'アクアマリンの弓', '透き通るような蒼い輝石がつけられた弓。海の怪獣に効果がありそうだ。'],
            'artemis_bow' => ['bow', self::RARITY_ARTIFACT, 50, 'アルテミスの弓', '美しい狩りの女神のものとされる黄金の弓。おそらくは、異界から流れ着いたものであろう。'],
            'bullseye_bow' => ['bow', self::RARITY_HIGH_QUALITY, 15, 'ブルズアイの弓', '怪獣の急所に魔力矢を確実に当てる事を第一に考え、魔力と相性の悪い合金を敢えて用いた一品。間違っても人に向けてはいけない。'],
            'shiva_bow' => ['bow', self::RARITY_ARTIFACT, 50, 'シヴァの弓', '科学の力によって、矢に核兵器にも匹敵する威力を込める事ができると恐れられる最強の弓。'],
            'experienced_suit' => ['clothing', self::RARITY_REGULAR, 10, '熟練秘書のスーツ', '輝石のあしらわれた、オーダーメイドの一品。今日も私は、島を見る。'],
            'eternal_suit' => ['clothing', self::RARITY_HIGH_QUALITY, 11, '永久秘書の礼装', '年季を感じさせる上質な布で作られた礼装。'],
            'star_reader_suit' => ['clothing', self::RARITY_ARTIFACT, 12, '星詠みの正装', '流星の軌道を予測できる知識は、この世界では重要な地位につながる。'],
            'military_suit' => ['clothing', self::RARITY_REGULAR, 10, '軍務卿の礼装', '海の警戒と怪獣討伐のために仕立てられた礼装。'],
            'marshal_suit' => ['clothing', self::RARITY_HIGH_QUALITY, 11, '大元帥の礼装', '幾度もの戦いをくぐり抜けた秘書のための礼装。'],
            'war_suit' => ['clothing', self::RARITY_ARTIFACT, 12, '戦神の礼装', '決戦の場に立つ秘書のための礼装。'],
            'chancellor_suit' => ['clothing', self::RARITY_REGULAR, 10, '宰相の式服', '島の人々の暮らしを見守るための式服。'],
            'grand_chancellor_suit' => ['clothing', self::RARITY_HIGH_QUALITY, 11, '大宰相の式服', '長い歳月を島の発展に捧げた秘書の式服。'],
            'star_chancellor_suit' => ['clothing', self::RARITY_ARTIFACT, 12, '星詠みの宰相服', '星と大地の巡りを読む秘書の正装。'],
            'magic_white_flag' => ['accessory', self::RARITY_HIGH_QUALITY, 1, '魔法の白旗', '「……この世界の白旗はちゃんと降参の意味ですよね？」秘書はそう、不安そうに呟いた。'],
            self::NYOWAMIYA_RIBBON => ['accessory', self::RARITY_HIGH_QUALITY, 1, 'ニョワミヤリボン', '怪獣を愛でたいあなたに！ニョワミヤリボン！'],
            self::LOVE_EMBLEM => ['accessory', self::RARITY_ARTIFACT, 1, '愛の紋章', '星瞬く夜に誰が祈る？'],
            self::TWIN_STAR_EMBLEM => ['accessory', self::RARITY_ARTIFACT, 1, '双子星の紋章', '星降る夜に誰が願う？'],
            self::CRESCENT_EMBLEM => ['accessory', self::RARITY_ARTIFACT, 1, '三日月の紋章', '月満ちる夜に誰が笑う？'],
        ];
        foreach ($newItems as $key => [$category, $rarity, $maximumLevel, $name, $flavor]) {
            $definitions[$key] = [
                'key' => $key,
                'category' => $category,
                'category_label' => self::CATEGORIES[$category]['label'],
                'category_max_equipped' => self::CATEGORIES[$category]['maximum_equipped'],
                'rarity' => $rarity,
                'rarity_label' => self::RARITIES[$rarity]['label'],
                'tradable' => true,
                'npc_tradable' => false,
                'max_level' => $maximumLevel,
                'name' => $name,
                'flavor_text' => $flavor,
                'unique_per_secretary' => false,
                'fixed_sale_price_money' => self::RARITIES[$rarity]['fixed_sale_price_money'],
                'introduced_version' => 27,
            ];
        }
        foreach ($definitions as $key => $definition) {
            $rarity = $definition['rarity'];
            $definitions[$key] = [
                ...$definition,
                'rarity_label' => self::RARITIES[$rarity]['label'],
                'fixed_sale_price_money' => self::RARITIES[$rarity]['fixed_sale_price_money'],
            ];
        }

        return $definitions;
    }

    /** @return array<string, array{key: string, name: string, fixed_sale_price_money: int}> */
    public function rarities(): array
    {
        $rarities = [];
        foreach (self::RARITIES as $key => $definition) {
            $rarities[$key] = [
                'key' => $key,
                'name' => $definition['label'],
                'fixed_sale_price_money' => $definition['fixed_sale_price_money'],
            ];
        }

        return $rarities;
    }

    public function fixedSalePrice(string $itemKey): int
    {
        return $this->definition($itemKey)['fixed_sale_price_money'];
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
