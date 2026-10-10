// 試作用の固定データ。名称・費用・ターン消費はRuleset v36の定義から転記した。
// 島の配置、資源量、ログの文面は試作のために作った架空のもので、本番データではない。
// 形は product/resources/js/types.ts の Nation / MapCell / CommandDefinition / CommandQueueItem に寄せてある。
(function () {
    const TERRAIN = {
        sea: '海', shallow: '浅瀬', wasteland: '荒地', plain: '平地', forest: '森', mountain: '山', scorched: '焦土',
    };
    const FACILITY = {
        capital: '首都', village: '村', town: '町', city: '都市', farm: '農場', factory: '工場', mine: '採掘場',
        missile_base: 'ミサイル基地', defense: '防衛施設', port: '港', monument: '記念碑', wind_power: '風力発電所',
        seabed_oil_field: '海底油田',
    };

    const LAND = ['wasteland', 'plain', 'forest', 'mountain', 'scorched'];
    const ANY = ['sea', 'shallow', ...LAND];
    // group は試作での分類。実装時はcommand keyからフロント側で引く想定。
    const c = (key, name, cost, group, terrains, o = {}) => ({
        key, name, cost_money: cost, cost_paradox: 0, consumes_turn: true, target_type: 'cell',
        quantity_semantics: 'unused', group, terrains, own: true, ...o,
    });
    const COMMANDS = [
        c('land_clear', '整地', 5, 'land', ['wasteland', 'plain', 'forest', 'scorched']),
        c('land_level', '地ならし', 100, 'land', ['wasteland', 'plain', 'forest', 'scorched'], { consumes_turn: false }),
        c('reclaim', '埋め立て', 150, 'land', ['sea', 'shallow']),
        c('excavate', '掘削', 200, 'land', ['sea', 'shallow', 'wasteland', 'plain', 'forest', 'mountain', 'scorched'], { quantity_semantics: 'ordinary' }),
        c('logging', '伐採', 0, 'land', ['forest']),
        c('plant_forest', '植林', 50, 'land', ['plain'], { empty: true }),
        c('territory_expand', '領土拡張', 100, 'land', LAND, { own: false, unowned: true }),
        c('territory_abandon', '領土破棄', 0, 'land', ['sea', 'shallow', 'wasteland', 'plain'], { consumes_turn: false, danger: true }),

        c('build_farm', '農場建設', 20, 'build', ['plain'], { quantity_semantics: 'ordinary', repeats: true, facility: 'farm' }),
        c('build_factory', '工場建設', 100, 'build', ['plain'], { quantity_semantics: 'ordinary', repeats: true, facility: 'factory' }),
        c('build_mine', '採掘場建設', 300, 'build', ['mountain'], { quantity_semantics: 'ordinary', repeats: true, facility: 'mine' }),
        c('build_port', '港建設', 1000, 'build', ['shallow'], { empty: true }),
        c('build_wind_power', '風力発電所建設', 200, 'build', ['plain'], { empty: true }),
        c('build_condenser', 'コンデンサ建設', 300, 'build', ['plain'], { empty: true }),
        c('build_thermal_power', '火力発電所建設', 300, 'build', ['plain'], { empty: true }),
        c('build_pizzeria', 'ピザ屋建設', 100, 'build', ['plain'], { empty: true }),
        c('build_monument', '記念碑建設', 9999, 'build', ['plain'], { empty: true, quantity_semantics: 'selector', options: ['モノリス', '平和記念碑', '戦いの碑'] }),
        c('build_central_bank', '中央銀行建設', 9999, 'build', ['plain'], { empty: true }),
        c('build_central_granary', '中央穀倉建設', 9999, 'build', ['plain'], { empty: true }),
        c('build_undersea_city', '海底都市建設', 1000, 'build', ['sea'], { empty: true }),
        c('build_undersea_fire_station', '海底消防署建設', 1000, 'build', ['sea'], { empty: true }),
        c('relocate_capital', '首都遷都', 1000, 'build', ['plain'], { empty: true }),

        c('build_missile_base', 'ミサイル基地建設', 300, 'military', ['plain'], { empty: true }),
        c('build_defense_facility', '防衛施設建設', 800, 'military', ['plain'], { empty: true }),
        c('build_decoy', '防衛施設建設', 1, 'military', ['plain'], { empty: true, suffix: '（ハリボテ）' }),
        c('build_seabed_base', '海底基地建設', 8000, 'military', ['sea'], { empty: true }),
        c('missile', 'ミサイル発射', 20, 'military', ANY, { own: false, quantity_semantics: 'ordinary' }),
        c('pp_missile', 'PPミサイル発射', 50, 'military', ANY, { own: false, quantity_semantics: 'ordinary' }),
        c('land_destruction_missile', '陸地破壊弾発射', 100, 'military', ANY, { own: false, quantity_semantics: 'ordinary' }),
        c('spp_missile', 'SPPミサイル発射', 500, 'military', ANY, { own: false, quantity_semantics: 'ordinary' }),

        c('build_fast_farm', '高速農場建設', 100, 'paradox', ['plain'], { consumes_turn: false, cost_paradox: 20, quantity_semantics: 'ordinary', facility: 'farm' }),
        c('build_fast_factory', '高速工場建設', 300, 'paradox', ['plain'], { consumes_turn: false, cost_paradox: 20, quantity_semantics: 'ordinary', facility: 'factory' }),
        c('build_fast_mine', '高速採掘場建設', 1000, 'paradox', ['mountain'], { consumes_turn: false, cost_paradox: 20, quantity_semantics: 'ordinary', facility: 'mine' }),

        c('money_aid', '資金援助', 0, 'nation', ANY, { target_type: 'nation', consumes_turn: false, quantity_semantics: 'ordinary', unit: '×100億円', own: false }),
        c('food_aid', '食料援助', 0, 'nation', ANY, { target_type: 'nation', consumes_turn: false, quantity_semantics: 'ordinary', unit: '×1,000トン', own: false }),
        c('attraction', '誘致活動', 1000, 'nation', ANY, { target_type: 'nation', own: false }),
        c('build_ship', '船建造', 500, 'nation', ANY, { target_type: 'nation', own: false, quantity_semantics: 'selector', options: ['漁船', '観光船', '探索船', '軍艦'] }),
        c('monster_dispatch', '怪獣派遣', 3000, 'nation', ANY, { target_type: 'nation', own: false, quantity_semantics: 'selector', options: ['いのら', 'サンジラ', 'レッドいのら'] }),
    ];
    const GROUPS = [
        { key: 'land', name: '整備' }, { key: 'build', name: '建設' }, { key: 'military', name: '軍事' },
        { key: 'paradox', name: '輝石' }, { key: 'nation', name: '島全体' },
    ];

    // ---- 地図（even行が右へ半マスずれる。projection.ts と同じ並び） ----
    const cube = (x, y) => { const q = x - (y + (y & 1)) / 2; return { q, r: y, s: -q - y }; };
    const dist = (a, b) => { const A = cube(a.x, a.y), B = cube(b.x, b.y); return Math.max(Math.abs(A.q - B.q), Math.abs(A.r - B.r), Math.abs(A.s - B.s)); };
    const rnd = (x, y, k = 0) => { let h = (x * 374761393 + y * 668265263 + k * 2147483647) | 0; h = (h ^ (h >>> 13)) * 1274126177 | 0; return ((h ^ (h >>> 16)) >>> 0) / 4294967296; };

    const CAPITAL = { x: 0, y: 0 };
    const RIVAL = { x: 12, y: -5 };
    const BOUNDS = { min_x: -15, max_x: 18, min_y: -11, max_y: 11 };
    const OWN = { id: 7, nation_number: 7, name: '試験島' };
    const OTHER = { id: 3, nation_number: 3, name: '向かいの島' };

    // 自島の作り込み。「x,y」→ [地形, 施設, 詳細]
    const P = {
        '0,0': ['plain', 'capital', [['人口', '32,400人']]],
        '1,0': ['plain', 'city', [['人口', '18,200人']]], '-1,0': ['plain', 'town', [['人口', '6,400人']]],
        '0,-1': ['plain', 'farm', [['規模', '30,000人規模']]], '1,-1': ['plain', 'farm', [['規模', '20,000人規模']]],
        '-1,-1': ['plain', 'village', [['人口', '900人']]], '2,0': ['plain', 'factory', [['規模', '60,000人規模']]],
        '0,1': ['plain', 'town', [['人口', '4,100人']]], '1,1': ['plain', 'farm', [['規模', '10,000人規模']]],
        '-1,1': ['plain', null, []], '-2,0': ['forest', null, [['木', '800本']]], '-2,1': ['forest', null, [['木', '400本']]],
        '2,-1': ['plain', 'factory', [['規模', '30,000人規模']]], '0,-2': ['mountain', 'mine', [['規模', '15,000人規模']]],
        '1,-2': ['mountain', null, []], '-1,-2': ['forest', null, [['木', '1,200本']]], '2,1': ['plain', 'wind_power', []],
        '0,2': ['plain', null, []], '1,2': ['wasteland', null, []], '-1,2': ['forest', 'missile_base', [['経験値', '12'], ['見え方', '他島からは森']]],
        '-2,-1': ['plain', 'defense', []], '3,0': ['plain', null, []], '3,-1': ['wasteland', null, []], '-3,0': ['plain', 'monument', [['種類', 'モノリス']]],
        '2,-2': ['wasteland', null, []], '-2,2': ['scorched', null, []], '2,2': ['shallow', 'port', []], '3,1': ['shallow', null, []],
        '-3,1': ['shallow', null, []], '-2,-2': ['shallow', null, []], '0,3': ['shallow', null, []], '3,-2': ['shallow', null, []],
        '1,3': ['shallow', null, []], '-1,-3': ['shallow', null, []], '0,-3': ['shallow', null, []], '-3,-1': ['shallow', null, []],
        '4,0': ['shallow', null, []], '-1,3': ['shallow', null, []], '5,2': ['sea', 'seabed_oil_field', []],
    };

    const cells = [];
    for (let y = BOUNDS.min_y; y <= BOUNDS.max_y; y++) {
        for (let x = BOUNDS.min_x; x <= BOUNDS.max_x; x++) {
            const d = dist({ x, y }, CAPITAL), dr = dist({ x, y }, RIVAL);
            let terrain = 'sea', facility = null, details = [];
            const key = `${x},${y}`;
            if (P[key]) [terrain, facility, details] = P[key];
            else if (dr <= 3) {
                const r = rnd(x, y);
                if (dr === 0) { terrain = 'plain'; facility = 'capital'; details = [['人口', '21,000人']]; }
                else if (dr === 3) terrain = r < 0.55 ? 'shallow' : r < 0.8 ? 'wasteland' : 'sea';
                else {
                    terrain = r < 0.2 ? 'forest' : r < 0.3 ? 'mountain' : 'plain';
                    if (terrain === 'plain') facility = [null, 'farm', 'town', 'city', 'factory', 'village'][Math.floor(rnd(x, y, 2) * 6)];
                }
            }
            const owner = d <= 4 ? OWN : dr <= 4 ? OTHER : null;
            cells.push({
                x, y, terrain, terrain_name: TERRAIN[terrain], facility, facility_name: facility ? FACILITY[facility] : null,
                display_name: facility ? FACILITY[facility] : TERRAIN[terrain],
                owner_nation_id: owner?.id ?? null, owner_nation_number: owner?.nation_number ?? null, owner_name: owner?.name ?? null,
                details: details.map(([label, formatted]) => ({ label, formatted })),
                monster: x === 10 && y === -4 ? { name: 'いのら', current_hp: 2, spawned_max_hp: 3 } : null,
                ship: x === 3 && y === 3 ? { name: '漁船', current_hp: 3, max_hp: 3, own: true } : null,
            });
        }
    }

    const NATION = {
        id: 7, nation_number: 7, name: '試験島', owner_name: '試験プレイヤー', comment: '農場を増やしすぎた。',
        current_turn: 127, next_turn_at: '12:00', calendar: '箱庭暦11年7月',
        money: 1284, money_capacity: 5000, total_food_tons: 18600, food_capacity_tons: 50000,
        total_population: 62000, owned_land_cells: 27, safe_land_cells: 40,
        farm_capacity_people: 60000, factory_capacity_people: 90000, mine_capacity_people: 15000,
        paradox: 46, karma: 0, state_label: '',
        workforce: { label: '失業率', percent: '4.2' },
        forecast: [
            { name: '食料', unit: 'トン', production: 13800, consumption: 12400, delta: 1400, holding: 18600 },
            { name: '工業品', unit: 'ユニット', production: 620, consumption: 0, delta: 620, holding: 1240 },
            { name: '鉱物', unit: 'トン', production: 150, consumption: 40, delta: 110, holding: 380 },
            { name: '石油', unit: 'バレル', production: 20, consumption: 6, delta: 14, holding: 52 },
            { name: '電力', unit: 'MW', production: 18, consumption: 12, delta: 6, holding: 30 },
        ],
    };

    const QUEUE_LIMIT = 30;
    const QUEUE = [
        { command_key: 'land_level', target_x: 1, target_y: 2, quantity: 1 },
        { command_key: 'build_farm', target_x: 1, target_y: 2, quantity: 3 },
        { command_key: 'land_clear', target_x: 3, target_y: -1, quantity: 1 },
        { command_key: 'build_factory', target_x: 3, target_y: 0, quantity: 2 },
        { command_key: 'reclaim', target_x: 3, target_y: 1, quantity: 1 },
        { command_key: 'plant_forest', target_x: -1, target_y: 1, quantity: 1 },
    ];

    const e = (message, importance = 'info', extra = {}) => ({ message, importance, ...extra });
    const LOG = [
        { target_turn: 127, events: [
            e('ターン収支', 'info', { summary: { money: 184, population: 1200, food: 1400 }, contributions: [['工業品の売却', 150, '億円'], ['鉱物の売却', 34, '億円'], ['食料消費', -12400, 'トン']] }),
            e('試験島(1,1)で農場が整備されました。'),
            e('試験島(-2,1)で伐採し、40億円を得ました。'),
            e('試験島(5,2)で海底油田が見つかりました。', 'notable'),
        ] },
        { target_turn: 126, events: [
            e('ターン収支', 'info', { summary: { money: -212, population: 800, food: -900 }, contributions: [['工業品の売却', 138, '億円'], ['工場建設', -100, '億円'], ['港建設', -250, '億円'], ['食料消費', -12240, 'トン']] }),
            e('台風が発生しました。試験島(1,-1)の農場が被害を受けました。', 'warning'),
            e('試験島(2,-1)で工場が建設されました。'),
            e('資金が足りず、試験島(3,0)の風力発電所建設は実行されませんでした。', 'warning'),
            e('秘密通信を受け取りました。', 'info', { confidential: true }),
        ] },
        { target_turn: 125, events: [
            e('ターン収支', 'info', { summary: { money: 96, population: 400, food: 1100 }, contributions: [['工業品の売却', 96, '億円'], ['食料消費', -12160, 'トン']] }),
            e('向かいの島(10,-4)に怪獣いのらが出現しました。', 'notable'),
            e('試験島(2,2)で港が建設されました。'),
        ] },
    ];

    window.PROTO_DATA = { TERRAIN, FACILITY, COMMANDS, GROUPS, cells, CAPITAL, BOUNDS, OWN, NATION, QUEUE, QUEUE_LIMIT, LOG, dist };
})();
