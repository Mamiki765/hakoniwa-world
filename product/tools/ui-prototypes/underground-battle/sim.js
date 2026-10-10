// 試作用の戦闘データ。本物の戦闘計算ではなく、ログの見せ方を試すための簡易シミュレーション。
// 出力の形は party-presentation の rounds[].actions（type / actor_id / target_id / amount / label / critical など）に寄せ、
// 各行に「その行の直後の全員の状態」を持たせている。敵と地名は config/underground-alpha-v1.php の名称、
// 味方の名前と技名は試作用の仮のもの。
(function () {
    function rng(seed) { let s = seed >>> 0; return () => { s = (s * 1664525 + 1013904223) >>> 0; return s / 4294967296; }; }
    const A = (id, name, o) => ({ id, team: 'player', name, max_hp: 1000, mp: 3000, atk: 120, spd: 10, gauge: 0, gauge_max: 1000, unlocked: true, skills: [], look: 0, ...o });
    const E = (id, name, o) => ({ id, team: 'enemy', name, max_hp: 800, mp: 0, atk: 90, spd: 8, skills: [], look: 0, ...o });
    const S = {
        slash: { label: '斬撃', kind: 'damage', pow: 1.0, mp: 0 },
        heavy: { label: '渾身の一撃', kind: 'damage', pow: 1.9, mp: 400 },
        bolt: { label: 'ホーリーボルト', kind: 'damage', pow: 1.5, mp: 350 },
        heal: { label: '癒しの祈り', kind: 'heal', pow: 2.2, mp: 450 },
        ward: { label: '守りの障壁', kind: 'barrier', pow: 1.6, mp: 300 },
        taunt: { label: '挑発', kind: 'taunt', mp: 200 },
        arrow: { label: '連ね射ち', kind: 'damage', pow: 0.7, hits: 3, mp: 300 },
        bite: { label: 'かみつき', kind: 'damage', pow: 1.0, mp: 0 },
        acid: { label: '腐食液', kind: 'damage', pow: 0.8, mp: 0, status: '腐食' },
        claw: { label: '黒爪', kind: 'damage', pow: 1.2, mp: 0 },
        wing: { label: '竜翼', kind: 'damage', pow: 0.7, mp: 0, all: true },
        breath: { label: '黒炎の息吹', kind: 'damage', pow: 1.3, mp: 0, all: true, status: '火傷' },
        roar: { label: '深淵の咆哮', kind: 'warning', text: '黒竜が大きく息を吸い込んだ。次のラウンド、黒炎の息吹が来る。' },
        regen: { label: '再生', kind: 'selfheal', pow: 1.4, mp: 0 },
        chant: { label: '狂信の祈り', kind: 'heal', pow: 1.5, mp: 0 },
    };
    const SCENARIOS = {
        duel: {
            title: '1対1', place: '浅い洞窟', encounter: '腐食スライム', seed: 7, context: '探索',
            actors: [
                A('p1', 'ノエル', { max_hp: 1180, atk: 135, spd: 12, skills: ['slash', 'heavy'], look: 1, gauge: 620 }),
                E('e1', '腐食スライム', { max_hp: 1500, atk: 80, spd: 7, skills: ['bite', 'acid'], look: 1 }),
            ],
        },
        boss: {
            title: '4対1（ボス）', place: '封印の地', encounter: '黒竜バハムル・初級1', seed: 21, context: '試練',
            actors: [
                A('p1', 'ノエル', { max_hp: 1480, atk: 150, spd: 12, skills: ['slash', 'heavy'], look: 1, gauge: 760 }),
                A('p2', 'ブラム', { max_hp: 2100, atk: 95, spd: 8, skills: ['slash', 'taunt', 'ward'], look: 2, gauge: 300 }),
                A('p3', 'セレス', { max_hp: 1050, atk: 110, spd: 11, skills: ['bolt', 'heal'], look: 3, gauge: 480 }),
                A('p4', 'カイ', { max_hp: 1200, atk: 125, spd: 14, skills: ['arrow', 'slash'], look: 4, gauge: 150 }),
                E('e1', '黒竜バハムル', { max_hp: 8600, atk: 170, spd: 9, skills: ['claw', 'wing', 'roar'], look: 9, boss: true }),
            ],
        },
        party: {
            title: '4対3', place: '浅い洞窟', encounter: '狂信者の一団', seed: 42, context: '探索',
            actors: [
                A('p1', 'ノエル', { max_hp: 1180, atk: 135, spd: 12, skills: ['slash', 'heavy'], look: 1, gauge: 900 }),
                A('p2', 'ブラム', { max_hp: 1700, atk: 90, spd: 8, skills: ['slash', 'taunt', 'ward'], look: 2, gauge: 200 }),
                A('p3', 'セレス', { max_hp: 900, atk: 100, spd: 11, skills: ['bolt', 'heal'], look: 3, gauge: 350 }),
                A('p4', 'カイ', { max_hp: 1000, atk: 115, spd: 14, skills: ['arrow', 'slash'], look: 4, gauge: 500 }),
                E('e1', '狂信者', { max_hp: 1300, atk: 85, spd: 9, skills: ['bite', 'chant'], look: 2 }),
                E('e2', '再生肉塊', { max_hp: 2200, atk: 70, spd: 5, skills: ['bite', 'regen'], look: 3 }),
                E('e3', '洞窟蟲', { max_hp: 700, atk: 100, spd: 13, skills: ['bite', 'acid'], look: 4 }),
            ],
        },
    };

    function run(key) {
        const sc = SCENARIOS[key], r = rng(sc.seed);
        const st = {};
        sc.actors.forEach((a) => { st[a.id] = { hp: a.max_hp, max_hp: a.max_hp, mp: a.mp, barrier: 0, statuses: [], taunt: 0, awakened: false, gauge: a.gauge ?? 0, gauge_max: a.gauge_max ?? 0 }; });
        const stat = {}; sc.actors.forEach((a) => { stat[a.id] = { dealt: 0, taken: 0, healed: 0 }; });
        const snap = () => JSON.parse(JSON.stringify(st));
        const alive = (team) => sc.actors.filter((a) => a.team === team && st[a.id].hp > 0);
        const rounds = [];
        let result = null, telegraph = false;
        for (let round = 1; round <= 12 && !result; round++) {
            const acts = [];
            const push = (o) => acts.push({ round, ...o, state: snap() });
            const order = sc.actors.filter((a) => st[a.id].hp > 0).sort((x, y) => y.spd - x.spd || (x.id < y.id ? -1 : 1));
            // 継続ダメージ
            for (const a of order) for (const s of st[a.id].statuses) {
                if (st[a.id].hp <= 0) break;
                const d = Math.round(a.max_hp * 0.03); st[a.id].hp = Math.max(1, st[a.id].hp - d);
                push({ type: 'status_damage', actor_id: a.id, label: s.label, amount: d });
            }
            for (const a of order) {
                if (st[a.id].hp <= 0 || result) continue;
                const me = st[a.id], foes = alive(a.team === 'player' ? 'enemy' : 'player'), friends = alive(a.team);
                if (!foes.length) break;
                // 覚醒
                if (a.team === 'player' && !me.awakened && me.gauge >= me.gauge_max) {
                    me.awakened = true; me.max_hp = Math.round(me.max_hp * 1.2); me.hp = Math.min(me.max_hp, me.hp + Math.round(a.max_hp * 0.2));
                    push({ type: 'awakening', actor_id: a.id, label: '覚醒', important: true });
                }
                let sk;
                if (a.boss && telegraph) { sk = S.breath; telegraph = false; }
                else {
                    const hurt = friends.filter((f) => st[f.id].hp / st[f.id].max_hp < 0.55);
                    const pool = a.skills.map((k) => S[k]).filter((x) => (x.mp ?? 0) <= me.mp)
                        .filter((x) => !((x.kind === 'heal' || x.kind === 'selfheal') && !hurt.length) && !(x.kind === 'selfheal' && me.hp / me.max_hp > 0.6))
                        .filter((x) => !(x.kind === 'taunt' && me.taunt > 0) && !(x.kind === 'barrier' && round % 3 !== 1) && !(x.kind === 'warning' && round % 4 !== 2));
                    const pref = pool.find((x) => x.kind === 'warning') ?? pool.find((x) => x.kind === 'heal' || x.kind === 'selfheal') ?? pool.find((x) => x.kind === 'taunt') ?? pool.find((x) => x.kind === 'barrier');
                    sk = pref ?? pool[Math.floor(r() * pool.length)] ?? S.slash;
                }
                if (sk.mp) me.mp -= sk.mp;
                const atk = a.atk * (me.awakened ? 1.35 : 1);
                if (sk.kind === 'warning') { telegraph = true; push({ type: 'warning', actor_id: a.id, label: sk.label, text: sk.text, important: true }); continue; }
                if (sk.kind === 'taunt') { me.taunt = 2; push({ type: 'status_applied', actor_id: a.id, target_id: a.id, label: sk.label, text: '敵の攻撃を引きつける', mp_cost: sk.mp }); continue; }
                if (sk.kind === 'barrier') {
                    const amt = Math.round(atk * sk.pow);
                    friends.forEach((f) => { st[f.id].barrier += amt; });
                    push({ type: 'barrier', actor_id: a.id, target_ids: friends.map((f) => f.id), label: sk.label, amount: amt, mp_cost: sk.mp }); continue;
                }
                if (sk.kind === 'heal' || sk.kind === 'selfheal') {
                    const t = sk.kind === 'selfheal' ? a : friends.slice().sort((x, y) => st[x.id].hp / st[x.id].max_hp - st[y.id].hp / st[y.id].max_hp)[0];
                    const amt = Math.min(st[t.id].max_hp - st[t.id].hp, Math.round(atk * sk.pow));
                    st[t.id].hp += amt; stat[a.id].healed += amt;
                    push({ type: 'recovery', actor_id: a.id, target_id: t.id, label: sk.label, amount: amt, mp_cost: sk.mp }); continue;
                }
                const taunter = foes.find((f) => st[f.id].taunt > 0);
                const targets = sk.all ? foes : [taunter ?? foes[Math.floor(r() * foes.length)]];
                for (const t of targets) for (let h = 0; h < (sk.hits ?? 1); h++) {
                    const ts = st[t.id]; if (ts.hp <= 0) break;
                    const evaded = r() < 0.06, critical = !evaded && r() < 0.14;
                    let dmg = evaded ? 0 : Math.round(atk * sk.pow * (0.85 + r() * 0.3) * (critical ? 1.8 : 1));
                    const absorbed = Math.min(ts.barrier, dmg); ts.barrier -= absorbed; dmg -= absorbed;
                    ts.hp = Math.max(0, ts.hp - dmg); stat[a.id].dealt += dmg; stat[t.id].taken += dmg;
                    if (t.team === 'player' && !ts.awakened) ts.gauge = Math.min(ts.gauge_max, ts.gauge + Math.round(dmg * 0.6));
                    if (a.team === 'player' && !me.awakened) me.gauge = Math.min(me.gauge_max, me.gauge + 60);
                    const applied = !evaded && sk.status && ts.hp > 0 && !ts.statuses.some((x) => x.label === sk.status) && r() < 0.5;
                    if (applied) ts.statuses.push({ label: sk.status, remaining: 3 });
                    push({ type: 'damage', actor_id: a.id, target_id: t.id, label: sk.label, amount: dmg, critical, evaded, barrier_absorbed: absorbed || undefined, hit: sk.hits ? h + 1 : undefined, hits: sk.hits, mp_cost: h === 0 && t === targets[0] ? sk.mp || undefined : undefined, status: applied ? sk.status : undefined, important: critical });
                    if (ts.hp <= 0) push({ type: 'defeat', actor_id: t.id, important: true });
                }
                if (!alive('enemy').length) result = 'victory';
                else if (!alive('player').length) result = 'defeat';
            }
            sc.actors.forEach((a) => { const s = st[a.id]; if (s.taunt > 0) s.taunt--; s.statuses = s.statuses.map((x) => ({ ...x, remaining: x.remaining - 1 })).filter((x) => x.remaining > 0); if (a.team === 'player' && s.hp > 0) s.mp = Math.min(a.mp, s.mp + 150); });
            rounds.push({ round, actions: acts });
        }
        result ??= 'stalemate';
        const initial = {}; sc.actors.forEach((a) => { initial[a.id] = { hp: a.max_hp, max_hp: a.max_hp, mp: a.mp, barrier: 0, statuses: [], taunt: 0, awakened: false, gauge: a.gauge ?? 0, gauge_max: a.gauge_max ?? 0 }; });
        const rewards = result === 'victory' ? { xp: key === 'boss' ? 12000 : key === 'party' ? 1840 : 420, g: key === 'boss' ? 8000 : key === 'party' ? 960 : 210, drops: key === 'boss' ? ['黒竜の鱗 ×1'] : key === 'party' ? ['輝石の欠片 ×2'] : [] } : { xp: 0, g: 0, drops: [] };
        return { key, ...sc, rounds, result, initial, final: snap(), stat, rewards };
    }
    window.PROTO_BATTLE = { SCENARIOS, run };
})();
