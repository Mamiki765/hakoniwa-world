// 地底の試作。拠点の画面（ホーム・冒険・キャラクター・ショップ・交流場・別荘）は上に所持金、下にメニューを出し、
// イベントシーンと戦闘の入口は枠を消して全面に出す。
// 地名・敵・能力名・装備の分類とレア度の呼び名は本体の名称。人名・数値・装備名・台詞は試作用の仮のもの。APIは呼ばない。
(function () {
    const $ = (id) => document.getElementById(id);
    const app = $('app'), view = $('view');
    const n = (v) => Number(v).toLocaleString('ja-JP');
    const h = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
    let seed = 5; const rnd = () => { seed = (seed * 1664525 + 1013904223) >>> 0; return seed / 4294967296; };

    const ICON = {
        home: '<path d="M3 11l9-7 9 7v9h-6v-6H9v6H3z"/>', adventure: '<path d="M5 21V4m0 1h12l-3 4 3 4H5"/>', character: '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-5 4-8 8-8s8 3 8 8"/>',
        shop: '<path d="M5 8h14l-1 12H6zM9 8V6a3 3 0 016 0v2"/>', exchange: '<circle cx="8" cy="9" r="3"/><circle cx="16" cy="9" r="3"/><path d="M2 20c0-4 3-6 6-6s6 2 6 6m0-6c3 0 8 1 8 6"/>', villa: '<path d="M5 4h12v16H5zM9 8h4M9 12h4M19 6v14"/>',
    };
    const NAV = [['home', 'ホーム'], ['adventure', '冒険'], ['character', 'キャラクター'], ['shop', 'ショップ'], ['exchange', '交流場'], ['villa', '別荘']];
    const SUB = {
        adventure: [['explore', '探索'], ['trial', '試練'], ['secret', '秘密の場所'], ['history', '戦闘履歴']],
        character: [['status', '能力'], ['stp', 'STP配分'], ['vault', '装備・保管庫'], ['skills', 'スキル・覚醒'], ['ai', '戦法']],
        shop: [['buy', '装備を買う'], ['polish', '魔石研磨'], ['bank', '銀行'], ['guide', '案内人と話す']],
        exchange: [['party', 'パーティー'], ['property', '不動産'], ['merchant', '行商人と話す']],
        villa: [['diary', '冒険日誌'], ['recollect', '回想'], ['trophy', 'トロフィー棚']],
    };
    const AREAS = [
        { key: 'caves', tab: 'explore', name: '浅い洞窟', il: [1, 20], clears: 412, need: 20, ticket: 1, foes: '地底鼠、洞窟蟲、腐食スライム' },
        { key: 'crystal', tab: 'explore', name: '黒晶洞', il: [20, 60], clears: 233, need: 20, ticket: 1, foes: '黒晶蝙蝠、晶殻蟲、黒晶術師' },
        { key: 'kingdom', tab: 'explore', name: '輝きの王国', il: [60, 120], clears: 96, need: 20, ticket: 1, foes: '城門の盾兵、王都の衛兵、宮廷の氷術師' },
        { key: 'yunagi', tab: 'explore', name: '夕凪の帰港地', il: [120, 223], clears: 12, need: 20, ticket: 2, foes: 'クリスタル・ドルフィン、オルカ・ハウンド、アビサル・プリースト' },
        { key: 'next', tab: 'explore', name: '？？？', il: [0, 0], locked: '夕凪の帰港地を20回クリアすると行けるようになります。' },
        { key: 'bahamul', tab: 'trial', name: '黒竜バハムル・初級1', il: [200, 223], clears: 0, foes: '黒竜バハムル', rounds: 100, stone: 1 },
        { key: 'vault1', tab: 'secret', name: '輝きの王国の宝物庫', il: [60, 120], clears: 4, key_cost: 1, keys: 2, foes: '宝物庫の番人' },
    ];
    const CAT = { weapon: '武器', armor: '防具', accessory: 'アクセサリー', resonance: '共鳴結晶' };
    const STYLE = { dagger: '短剣', rapier: '細身剣', longsword: '長剣', crystal_staff: '輝石杖' };
    const RAR = { regular: 'レギュラー', high_quality: 'ハイクオリティ', artifact: 'アーティファクト', relic: 'レリック' };
    const RAR_MULT = { regular: 1, high_quality: 1.6, artifact: 2.6, relic: 4.2 };
    const STAT = [['vitality', '生命'], ['might', '武力'], ['finesse', '技巧'], ['spirit', '精神'], ['agility', '敏捷']];

    // 保管庫の中身（仮）。装備中の6つを先頭に置く
    const ITEMS = [];
    const mk = (cat, style, il, q, rar, o = {}) => {
        const base = cat === 'weapon' ? STYLE[style] : cat === 'armor' ? ['胸当て', '外套', '鎧'][Math.floor(rnd() * 3)] : cat === 'accessory' ? ['指輪', '首飾り', '耳飾り'][Math.floor(rnd() * 3)] : '共鳴結晶';
        const pre = il >= 200 ? '夕凪の' : il >= 150 ? '王国の' : il >= 100 ? '黒晶の' : '洞窟の';
        ITEMS.push({ id: ITEMS.length + 1, cat, style: cat === 'weapon' ? style : null, il, q, rar, name: o.name ?? pre + base, price: Math.round(il * il * (q / 100) * RAR_MULT[rar] / 10) * 10, age: o.age ?? Math.floor(rnd() * 40), eq: o.eq ?? null, lock: !!o.lock });
    };
    mk('weapon', 'longsword', 218, 96, 'artifact', { eq: '武器', age: 9 }); mk('armor', null, 210, 91, 'high_quality', { eq: '防具', age: 14 });
    mk('accessory', null, 205, 88, 'artifact', { eq: 'アクセサリー1', age: 20 }); mk('accessory', null, 198, 94, 'high_quality', { eq: 'アクセサリー2', age: 22 }); mk('accessory', null, 190, 80, 'regular', { eq: 'アクセサリー3', age: 31 });
    mk('resonance', null, 200, 100, 'relic', { eq: '共鳴', age: 5, name: '黒竜の共鳴結晶' });
    for (let i = 0; i < 74; i++) {
        const roll = rnd(), cat = roll < 0.4 ? 'weapon' : roll < 0.68 ? 'armor' : roll < 0.95 ? 'accessory' : 'resonance';
        const rr = rnd(), rar = rr < 0.55 ? 'regular' : rr < 0.84 ? 'high_quality' : rr < 0.97 ? 'artifact' : 'relic';
        mk(cat, Object.keys(STYLE)[Math.floor(rnd() * 4)], 120 + Math.floor(rnd() * 104), 60 + Math.floor(rnd() * 41), rar, { lock: rar === 'relic' });
    }
    const GOODS = [
        { key: 'g1', cat: 'weapon', name: '夕凪の長剣', il: 200, price: 480000, text: '武力 +210' }, { key: 'g2', cat: 'weapon', name: '夕凪の輝石杖', il: 200, price: 480000, text: '精神 +210' },
        { key: 'g3', cat: 'armor', name: '夕凪の外套', il: 200, price: 420000, text: '生命 +190' }, { key: 'g4', cat: 'armor', name: '王城の鎧', il: 223, price: 900000, text: '生命 +260', lock: '試練3をクリアすると買えます' },
        { key: 'g5', cat: 'accessory', name: '潮騒の耳飾り', il: 190, price: 300000, text: '敏捷 +120' },
    ];
    const TALKS = [['案内人', 'おや、買い物じゃなくてお喋りかい。珍しいね。'], ['案内人', 'この店の品は、あんたが潜った深さに合わせて仕入れてるんだ。浅いところで止まってると、棚も変わらないよ。'], ['案内人', '……まあ、無理はしないことさ。帰ってこない客ほど寂しいものはないからね。']];
    const CANDS = [['ワッフル', 1104, 22687, 'プレイヤー02'], ['レイ', 980, 15094, 'プレイヤー05'], ['アリエフ', 1210, 28347, 'プレイヤー09'], ['ミモザ', 870, 14200, 'プレイヤー11'], ['クロウ', 1002, 19950, 'プレイヤー14']];

    const S = {
        view: 'home', sub: { adventure: 'explore', character: 'status', shop: 'buy', exchange: 'party', villa: 'diary' },
        g: 184200, bank: 8962483, tickets: 872, stones: 3, stoneToday: 1, lv: 1129, name: 'ノエル', hp: 25067, max: 25067, aw: 0, awMax: 1000, xp: 38753, inn: 10,
        stp: 12, stat: { vitality: 412, might: 688, finesse: 240, spirit: 120, agility: 355 }, add: { vitality: 0, might: 0, finesse: 0, spirit: 0, agility: 0 },
        dest: 'yunagi', area: 'yunagi', skip: 1, cool: 0, msg: '', art: true, awake: false,
        party: [0, 1, 2], pslot: null, talk: 0, shopcat: 'weapon', amount: '',
        f: { cats: new Set(), rars: new Set(), styles: new Set(), ilMax: '', qMax: '', weaker: false, dup: false, old: false }, sort: 'newest', sel: new Set(), shown: 40, confirm: false, sold: '',
    };
    const area = (k) => AREAS.find((a) => a.key === k);
    const meter = (v, max, cls = '') => { const r = max ? v / max : 0; return `<span class="meter ${cls || (r <= 0.25 ? 'crit' : r <= 0.5 ? 'low' : '')}"><span style="width:${r * 100}%"></span></span>`; };
    const figure = (label) => S.art ? `<div class="figure"><svg viewBox="0 0 120 220" role="img" aria-label="${label}"><path class="body" d="M60 14c14 0 24 11 24 26s-10 27-24 27-24-12-24-27 10-26 24-26zM22 220c0-74 8-140 38-140s38 66 38 140z"/><text x="60" y="150">${label}</text></svg></div>` : '';
    const subtabs = (key) => `<div class="subtabs">${SUB[key].map(([k, l]) => `<button type="button" data-sub="${k}" class="${S.sub[key] === k ? 'on' : ''}">${l}</button>`).join('')}</div>`;
    const stub = (label) => `<div class="page"><section class="box pn"><h2>${label}</h2><p class="muted">この画面は今回の試作では作っていません。</p></section></div>`;
    const goLabel = () => (S.cool > 0 ? `あと${S.cool}秒` : '冒険に出る');
    const hurt = () => S.hp / S.max < 0.25;

    // ---------- 保管庫 ----------
    const equippedIl = (cat) => { const e = ITEMS.filter((x) => x.eq && x.cat === cat).map((x) => x.il); return e.length ? Math.min(...e) : 0; };
    function filtered() {
        const f = S.f, best = new Map();
        ITEMS.forEach((x) => { const b = best.get(x.name); if (!b || x.il > b.il || (x.il === b.il && x.q > b.q)) best.set(x.name, x); });
        const list = ITEMS.filter((x) => (!f.cats.size || f.cats.has(x.cat)) && (!f.rars.size || f.rars.has(x.rar)) && (!f.styles.size || (x.style && f.styles.has(x.style)))
            && (f.ilMax === '' || x.il <= +f.ilMax) && (f.qMax === '' || x.q <= +f.qMax)
            && (!f.weaker || (!x.eq && x.il < equippedIl(x.cat))) && (!f.dup || best.get(x.name) !== x) && (!f.old || x.age >= 14));
        const order = { newest: (a, b) => a.age - b.age, oldest: (a, b) => b.age - a.age, il: (a, b) => b.il - a.il, rarity: (a, b) => RAR_MULT[b.rar] - RAR_MULT[a.rar] || b.il - a.il, quality: (a, b) => b.q - a.q, price: (a, b) => b.price - a.price }[S.sort];
        return list.sort(order);
    }
    const sellable = (x) => !x.eq && !x.lock;
    function vault() {
        const f = S.f, list = filtered(), can = list.filter(sellable);
        const active = f.cats.size + f.rars.size + f.styles.size + (f.ilMax !== '' ? 1 : 0) + (f.qMax !== '' ? 1 : 0) + (f.weaker ? 1 : 0) + (f.dup ? 1 : 0) + (f.old ? 1 : 0);
        const chips = (set, map, key) => Object.entries(map).map(([k, l]) => `<button type="button" class="chip ${set.has(k) ? 'on' : ''}" data-f="${key}" data-v="${k}">${l}</button>`).join('');
        const tog = (k, l) => `<button type="button" class="chip ${f[k] ? 'on' : ''}" data-ft="${k}">${l}</button>`;
        return `<div class="page">
            <details class="box pn fbox" id="fbox" ${S.fopen ? 'open' : ''}><summary>絞り込み${active ? `<span style="color:var(--gold)">${active}条件</span>` : ''}<button type="button" class="chip" data-fclear ${active ? '' : 'hidden'}>すべて外す</button></summary><div class="filters">
                <div class="frow"><b>分類</b>${chips(f.cats, CAT, 'cats')}</div>
                ${f.cats.has('weapon') || !f.cats.size ? `<div class="frow"><b>武器の種類</b>${chips(f.styles, STYLE, 'styles')}</div>` : ''}
                <div class="frow"><b>レア度</b>${chips(f.rars, RAR, 'rars')}</div>
                <div class="frow"><b>数値</b><label>装備Lv <input type="number" id="f-il" inputmode="numeric" min="1" max="223" value="${f.ilMax}" placeholder="上限"> 以下</label><label>品質 <input type="number" id="f-q" inputmode="numeric" min="0" max="100" value="${f.qMax}" placeholder="上限"> %以下</label></div>
                <div class="frow"><b>まとめて</b>${tog('weaker', 'いま装備している物より装備Lvが低い')}${tog('dup', '同じ名前の中で一番良い物以外')}${tog('old', '入手から2週間以上')}</div>
            </div></details>
            <section class="box pn"><div class="vhead"><span class="cnt">${list.length}件<span class="muted">　保管 ${ITEMS.length}/500</span></span>
                <label class="muted">並び順 <select id="v-sort">${[['newest', '入手が新しい順'], ['oldest', '入手が古い順'], ['il', '装備Lvが高い順'], ['rarity', 'レア度が高い順'], ['quality', '品質が高い順'], ['price', '売値が高い順']].map(([k, l]) => `<option value="${k}" ${S.sort === k ? 'selected' : ''}>${l}</option>`).join('')}</select></label>
                <button type="button" data-selall ${can.length ? '' : 'disabled'}>この${can.length}件を全部選ぶ</button></div>
                <div class="items"><div class="it head"><span></span><span>名前</span><span class="n">装備Lv</span><span class="n">品質</span><span class="n">売値</span><span>保護</span></div>
                ${list.slice(0, S.shown).map((x) => { const d = x.eq ? 0 : x.il - equippedIl(x.cat); return `<div class="it${S.sel.has(x.id) ? ' sel' : ''}${sellable(x) ? '' : ' fixed'}"><input type="checkbox" data-pick="${x.id}" ${S.sel.has(x.id) ? 'checked' : ''} ${sellable(x) ? '' : 'disabled'} aria-label="${h(x.name)}を選ぶ">
                    <span class="nm"><b class="r-${x.rar}">${h(x.name)}</b>${x.eq ? `<span class="tag">装備中</span>` : ''}<small>${CAT[x.cat]}${x.style ? `・${STYLE[x.style]}` : ''}・${RAR[x.rar]}</small></span>
                    <span class="n il" data-l="装備Lv">${x.il}${x.eq ? '' : `<em class="${d > 0 ? 'up' : 'dn'}">${d > 0 ? `▲${d}` : d < 0 ? `▼${-d}` : '＝'}</em>`}</span><span class="n q" data-l="品質">${x.q}%</span><span class="n price" data-l="売値">${n(x.price)}G</span>
                    <button type="button" class="lock ${x.lock ? 'on' : ''}" data-lock="${x.id}" aria-label="${x.lock ? '保護を外す' : '保護する'}" ${x.eq ? 'disabled' : ''}>${x.lock ? '★' : '☆'}</button></div>`; }).join('') || '<p class="muted" style="padding:14px 4px">条件に合う装備がありません。</p>'}</div>
                ${list.length > S.shown ? `<p style="text-align:center;margin-top:8px"><button type="button" data-more>あと${list.length - S.shown}件を表示</button></p>` : ''}
                <p class="muted" style="margin-top:8px">★で保護した装備と装備中の装備は、選択にも一括売却にも入りません。装備Lvの横の▲▼は、同じ分類でいま装備している物との差です。</p></section></div>`;
    }
    function sellbar() {
        const bar = $('sellbar'), on = S.view === 'character' && S.sub.character === 'vault';
        const picked = ITEMS.filter((x) => S.sel.has(x.id)), total = picked.reduce((s, x) => s + x.price, 0);
        bar.hidden = !on || (!picked.length && !S.sold);
        bar.className = S.sold && !picked.length ? 'done' : '';
        if (bar.hidden) return;
        if (!picked.length) { bar.innerHTML = `<span class="sum">${h(S.sold)}</span><button type="button" data-soldok>閉じる</button>`; return; }
        const top = picked.slice().sort((a, b) => b.price - a.price)[0];
        bar.innerHTML = S.confirm
            ? `<span class="sum">${picked.length}件を<b>${n(total)}G</b>で売ります。元には戻せません。<span class="muted">一番高いのは ${h(top.name)}（Lv${top.il}・${RAR[top.rar]}）</span></span><button type="button" data-sellno>やめる</button><button type="button" class="primary" data-sellyes>売る</button>`
            : `<span class="sum">${picked.length}件を選択中　合計<b>${n(total)}G</b></span><button type="button" data-selnone>選択を外す</button><button type="button" class="primary" data-sell>まとめて売る</button>`;
    }

    // ---------- 各画面 ----------
    const views = {
        home() {
            const d = area(S.dest);
            return `<div class="home"><div class="scene"><span class="ph">背景の枠</span>${figure(S.awake ? '覚醒の立ち絵' : '秘書の立ち絵')}</div>
                <div class="left"><section class="plate pn" aria-label="自分の秘書"><p class="lv">Lv<strong>${n(S.lv)}</strong></p><h2>${h(S.name)}</h2><p class="path">戦技</p>
                    <div class="gauge"><i>HP</i>${meter(S.hp, S.max)}<b>${n(S.hp)}/${n(S.max)}</b></div>
                    <div class="gauge"><i>覚醒</i>${meter(S.aw, S.awMax, 'aw')}<b>${Math.round(S.aw / S.awMax * 100)}%</b></div>
                    <p class="xp">次のLvまで ${n(S.xp)}${S.hp < S.max ? `　<button type="button" class="rest" data-rest ${S.g < S.inn ? 'disabled' : ''}>宿で休む ${S.inn}G</button>` : ''}</p></section>
                <div class="alerts">${S.stp ? `<button type="button" data-jump="character:stp">STPが${S.stp}残っています</button>` : ''}${S.stoneToday < 3 ? '<button type="button" data-jump="shop:buy">歪んだ輝石を受け取れます</button>' : ''}${ITEMS.length >= 450 ? '<button type="button" data-jump="character:vault">保管庫がいっぱいに近い</button>' : ''}</div></div>
                <div class="side-btns"><button type="button" data-awake title="通常と覚醒の姿を切り替える" aria-label="通常と覚醒の姿を切り替える">↻</button><button type="button" data-dummy="背景を選ぶ画面を開きます（試作では開きません）" title="背景を変える" aria-label="背景を変える">▦</button><button type="button" data-dummy="画像の制作方法と権利表記を出します（試作では出ません）" title="画像について" aria-label="画像について">i</button></div>
                <div class="dock"><button type="button" class="party pn" data-jump="exchange:party" aria-label="パーティーを編成する"><span class="m"><i>${S.name[0]}</i><span>${h(S.name)}</span>${meter(S.hp, S.max)}</span>${S.party.map((i) => `<span class="m"><i>${CANDS[i][0][0]}</i><span>${h(CANDS[i][0])}</span>${meter(CANDS[i][2] * (i === 0 ? 0.84 : 1), CANDS[i][2])}</span>`).join('')}</button>
                    <section class="depart pn"><p class="to"><b>${h(d.name)}</b><button type="button" data-jump="adventure:explore">行き先</button></p>
                        <button type="button" class="primary big" data-go ${S.cool > 0 || hurt() ? 'disabled' : ''}>${goLabel()}</button>
                        ${hurt() ? '<p class="warn note">HPが少なすぎます。宿で休んでください。</p>' : S.msg ? `<p class="muted note">${h(S.msg)}</p>` : ''}</section></div></div>`;
        },
        adventure() {
            const tab = S.sub.adventure;
            if (tab === 'history') return subtabs('adventure') + stub('戦闘履歴');
            const list = AREAS.filter((a) => a.tab === tab);
            if (!list.some((a) => a.key === S.area)) S.area = list[0].key;
            const a = area(S.area), canSkip = !a.locked && a.need && a.clears >= a.need, cost = (a.ticket ?? 1) * S.skip;
            return subtabs('adventure') + `<div class="page"><div class="two">
                <section class="box pn"><h2>行き先</h2>${list.map((x) => `<button type="button" class="area${x.key === S.area ? ' on' : ''}" data-area="${x.key}"><span class="cur">▶</span><span class="nm">${h(x.name)}<small>${x.locked ? h(x.locked) : `クリア ${x.clears}回${x.key === S.dest ? '　ホームの行き先' : ''}`}</small></span><span class="il">${x.locked ? '' : `装備Lv ${x.il[0]}〜${x.il[1]}`}</span></button>`).join('')}</section>
                <section class="box pn detail"><h2>くわしく</h2>${a.locked ? `<h3>${h(a.name)}</h3><p class="muted">${h(a.locked)}</p>` : `<h3>${h(a.name)}</h3>
                    <dl class="kv"><dt>手に入る装備</dt><dd>Lv ${a.il[0]}〜${a.il[1]}</dd><dt>出る敵</dt><dd>${h(a.foes)}</dd><dt>クリア</dt><dd>${a.clears}回</dd>${a.key_cost ? `<dt>入るのに必要</dt><dd>鍵 ${a.key_cost}本（所持 ${a.keys}本）</dd>` : ''}${a.stone ? `<dt>入るのに必要</dt><dd>歪んだ輝石 ${a.stone}個（所持 ${S.stones}個・勝ったときだけ減る）</dd>` : ''}${a.rounds ? `<dt>制限</dt><dd>${a.rounds}ラウンド</dd>` : ''}</dl>
                    <button type="button" class="primary big" data-go="${a.key}" ${S.cool > 0 || hurt() ? 'disabled' : ''}>${S.cool > 0 ? `あと${S.cool}秒` : a.tab === 'trial' ? '挑戦する' : 'ここを探索する'}</button>
                    ${a.need ? `<div class="skip">${canSkip ? `<span>戦闘を省いて結果だけ受け取る</span><span class="pick">${[1, 5, 10, 50].map((v) => `<button type="button" data-skipn="${v}" class="${S.skip === v ? 'on' : ''}">×${v}</button>`).join('')}</span><button type="button" data-skip ${cost > S.tickets ? 'disabled' : ''}>チケット${cost}枚で受け取る</button>` : `<span class="muted">あと${a.need - a.clears}回クリアすると、スキップチケットが使えるようになります（${a.clears}/${a.need}）。</span>`}</div>` : ''}
                    ${S.msg ? `<p class="muted">${h(S.msg)}</p>` : ''}`}</section></div></div>`;
        },
        character() {
            const tab = S.sub.character, used = Object.values(S.add).reduce((s, v) => s + v, 0), max = Math.max(...STAT.map(([k]) => S.stat[k] + S.add[k]));
            if (tab === 'vault') return subtabs('character') + vault();
            if (tab === 'status') return subtabs('character') + `<div class="page"><div class="two">
                <section class="box pn"><h2>能力<span>Lv ${n(S.lv)}　戦技</span></h2><div class="stats"><div class="stat"><span>最大HP</span><span></span><span class="v">${n(S.max)}</span></div>${STAT.map(([k, l]) => `<div class="stat"><span>${l}</span>${meter(S.stat[k], max, 'aw')}<span class="v">${n(S.stat[k])}</span></div>`).join('')}</div><p class="muted" style="margin-top:6px">装備の分を含めた数値です。</p></section>
                <section class="box pn"><h2>装備中<button type="button" class="chip" data-sub="vault">保管庫で替える</button></h2><div class="slots">${ITEMS.filter((x) => x.eq).map((x) => `<div class="slot"><small>${x.eq}</small><b class="r-${x.rar}">${h(x.name)}</b><span>Lv${x.il}　品質${x.q}%</span></div>`).join('')}</div></section></div></div>`;
            if (tab === 'stp') return subtabs('character') + `<div class="page"><section class="box pn stp"><h2>STP配分<span>残り <b style="color:var(--gold);font:400 18px var(--f-game)">${S.stp - used}</b></span></h2>
                <div class="stats">${STAT.map(([k, l]) => `<div class="stat"><span>${l}</span>${meter(S.stat[k] + S.add[k], max, 'aw')}<span class="v">${n(S.stat[k] + S.add[k])}${S.add[k] ? `<em> +${S.add[k]}</em>` : ''}</span><span class="step"><button type="button" data-stp="${k}" data-d="-1" ${S.add[k] ? '' : 'disabled'} aria-label="${l}を減らす">−</button><button type="button" data-stp="${k}" data-d="1" ${used < S.stp ? '' : 'disabled'} aria-label="${l}を増やす">＋</button></span></div>`).join('')}</div>
                <p style="display:flex;gap:8px;justify-content:flex-end;margin-top:10px"><button type="button" data-stpreset ${used ? '' : 'disabled'}>戻す</button><button type="button" class="primary" data-stpok ${used ? '' : 'disabled'}>この配分で決める</button></p>
                <p class="muted">決めるまでは何度でもやり直せます。決めたあとの振り直しは別の手段になります。</p></section></div>`;
            return subtabs('character') + stub(SUB.character.find((x) => x[0] === tab)[1]);
        },
        shop() {
            const tab = S.sub.shop;
            if (tab === 'polish') return subtabs('shop') + stub('魔石研磨');
            if (tab === 'bank') return subtabs('shop') + `<div class="page"><section class="box pn"><h2>銀行</h2><div class="bank">
                <dl class="kv"><dt>手持ち</dt><dd>${n(S.g)} G</dd><dt>預金</dt><dd>${n(S.bank)} G</dd></dl>
                <div class="amt"><input id="amt" type="text" inputmode="numeric" placeholder="金額" value="${h(S.amount)}" aria-label="金額"><span>G</span></div>
                <div class="amt">${[['10000', '1万'], ['100000', '10万'], ['1000000', '100万']].map(([v, l]) => `<button type="button" class="chip" data-amt="${v}">+${l}</button>`).join('')}<button type="button" class="chip" data-amt="g">手持ち全部</button><button type="button" class="chip" data-amt="bank">預金全部</button></div>
                <div class="amt"><button type="button" class="primary big" data-bank="in">預ける</button><button type="button" class="big" data-bank="out">下ろす</button></div>
                ${S.msg ? `<p class="muted">${h(S.msg)}</p>` : '<p class="muted">買い物と宿に使えるのは手持ちだけです。戦闘に負けても預金は減りません。</p>'}</div></section></div>`;
            const left = 3 - S.stoneToday, price = S.stoneToday === 0 ? 0 : 50000 * S.stoneToday;
            return subtabs('shop') + `<div class="page">
                <section class="box pn"><h2>歪んだ輝石<span>所持 ${S.stones}個</span></h2><p class="muted">試練「黒竜バハムル」に挑むのに使います。勝ったときだけ1個減ります。</p>
                    <p style="display:flex;gap:10px;align-items:center;margin-top:6px;flex-wrap:wrap"><span class="muted">今日はあと${left}個（0時に戻る）</span>${left > 0 ? `<button type="button" class="${price <= S.g ? 'primary' : ''}" data-stone="${price}" ${price > S.g ? 'disabled' : ''}>${price === 0 ? '1個受け取る（無料）' : `1個買う ${n(price)}G`}</button>` : '<b>今日の分は売り切れ</b>'}</p></section>
                <section class="box pn"><h2>装備を買う<span class="pick">${[['weapon', '武器'], ['armor', '防具'], ['accessory', 'アクセサリー']].map(([k, l]) => `<button type="button" data-shopcat="${k}" class="${S.shopcat === k ? 'on' : ''}">${l}</button>`).join('')}</span></h2>
                    <table class="goods"><thead><tr><th>名前</th><th class="r">装備Lv</th><th class="r">値段</th><th></th></tr></thead><tbody>${GOODS.filter((x) => x.cat === S.shopcat).map((x) => `<tr><td>${h(x.name)}<small>${h(x.lock ?? x.text)}</small></td><td class="r">${x.il}</td><td class="r">${n(x.price)}G</td><td class="r">${x.own ? '買った' : x.lock ? '未解禁' : `<button type="button" data-buy="${x.key}" class="${x.price <= S.g ? 'primary' : ''}" ${x.price > S.g ? 'disabled' : ''}>${x.price <= S.g ? '買う' : '手持ち不足'}</button>`}</td></tr>`).join('')}</tbody></table>
                    ${S.msg ? `<p class="muted" style="margin-top:6px">${h(S.msg)}</p>` : ''}</section></div>`;
        },
        exchange() {
            const tab = S.sub.exchange;
            if (tab !== 'party') return subtabs('exchange') + stub(SUB.exchange.find((x) => x[0] === tab)[1]);
            return subtabs('exchange') + `<div class="page"><section class="box pn"><h2>パーティー<span>${S.pslot === null ? '入れ替える枠を押してください' : `${S.pslot + 2}番目に入れる相手を下から選んでください`}</span></h2>
                <div class="pslots"><div class="pslot"><small>リーダー</small><b>${h(S.name)}</b><span>Lv${n(S.lv)}　HP ${n(S.max)}</span></div>${S.party.map((ci, i) => `<button type="button" class="pslot ${S.pslot === i ? 'on' : ''}" data-pslot="${i}"><small>${i + 2}番目</small><b>${ci === null ? '空き' : h(CANDS[ci][0])}</b><span>${ci === null ? '' : `Lv${n(CANDS[ci][1])}　HP ${n(CANDS[ci][2])}`}</span></button>`).join('')}</div></section>
                <section class="box pn"><h2>借りられる秘書</h2>${CANDS.map((c, i) => `<div class="cand"><span><b>${h(c[0])}</b> <span class="muted">${h(c[3])}の秘書</span></span><span class="n">Lv${n(c[1])}　HP ${n(c[2])}</span>${S.party.includes(i) ? '<span class="muted">編成中</span>' : `<button type="button" data-cand="${i}" ${S.pslot === null ? 'disabled' : ''}>入れる</button>`}</div>`).join('')}
                ${S.pslot !== null && S.party[S.pslot] !== null ? '<p style="margin-top:8px"><button type="button" data-cand="-1">この枠を空ける</button></p>' : ''}</section></div>`;
        },
        villa() { return subtabs('villa') + stub(SUB.villa.find((x) => x[0] === S.sub.villa)[1]); },
        // ---- 枠なしの全面表示 ----
        event() {
            const [who, text] = TALKS[S.talk], last = S.talk === TALKS.length - 1;
            return `<div class="full"><div class="scene"><span class="ph">店の背景の枠</span>${figure('案内人の立ち絵')}</div><button type="button" class="skipx" data-back="shop">飛ばす</button>
                <div class="msg pn"><span class="who">${who}</span>${h(text)}<div class="act"><button type="button" class="primary" ${last ? 'data-back="shop"' : 'data-talk'}>${last ? '店に戻る' : '次へ'}</button></div></div></div>`;
        },
        battle() {
            const d = area(S.dest);
            return `<div class="full"><div class="scene"><span class="ph">${h(d.name)}の背景の枠</span></div><div class="enc pn"><small>遭遇</small><b>${h(d.foes.split('、')[0])}</b></div>
                <div class="msg pn">ここから戦闘ログに切り替わります。メニューと所持金は出しません。<span class="muted">（戦闘ログは別の試作「地底の戦闘ログ」にあります）</span>
                    <div class="act"><button type="button" class="primary" data-back="home" data-win>結果を受け取って戻る</button></div></div></div>`;
        },
    };

    function render() {
        const chrome = !['event', 'battle'].includes(S.view);
        app.dataset.chrome = chrome ? 'on' : 'off'; app.dataset.view = S.view;
        $('wallet').innerHTML = `<h1>地底</h1><span class="cur ${S.g === 0 ? 'zero' : ''}"><b>手持ち</b>${n(S.g)}G</span><span class="cur"><b>預金</b>${n(S.bank)}G</span><span class="cur"><b>チケット</b>${n(S.tickets)}</span><span class="cur"><b>歪んだ輝石</b>${S.stones}</span>`;
        $('nav').innerHTML = NAV.map(([k, l]) => `<button type="button" data-view="${k}" class="${S.view === k ? 'on' : ''}"><svg viewBox="0 0 24 24" aria-hidden="true">${ICON[k]}</svg>${l}${(k === 'character' && S.stp) || (k === 'shop' && S.stoneToday < 3) ? '<span class="dot"></span>' : ''}</button>`).join('');
        view.innerHTML = views[S.view]();
        sellbar();
    }
    let timer;
    function depart(key) {
        if (key) S.dest = key;
        S.view = 'battle'; S.msg = '';
    }
    function win() {
        const d = area(S.dest);
        if (d.key_cost) d.keys -= d.key_cost; if (d.stone) S.stones -= d.stone;
        d.clears++; S.hp = Math.max(Math.round(S.max * 0.1), S.hp - Math.round(S.max * 0.22)); S.aw = Math.min(S.awMax, S.aw + 180); S.g += 4200; S.cool = 5;
        S.msg = `${d.name}をクリア。4,200Gを手に入れました（数値は仮）。`;
        clearInterval(timer); timer = setInterval(() => { S.cool--; if (S.cool <= 0) clearInterval(timer); if (['home', 'adventure'].includes(S.view)) { const keep = view.scrollTop; render(); view.scrollTop = keep; } }, 1000);
    }
    document.addEventListener('click', (e) => {
        const b = e.target.closest('button'); if (!b) return;
        const d = b.dataset, keep = view.scrollTop;
        let top = false;
        if (d.view) { S.view = d.view; S.msg = ''; top = true; }
        else if (d.sub) { S.sub[S.view] = d.sub; S.msg = ''; top = true; if (S.view === 'shop' && d.sub === 'guide') { S.view = 'event'; S.talk = 0; S.sub.shop = 'buy'; } }
        else if (d.jump) { const [v, s] = d.jump.split(':'); S.view = v; S.sub[v] = s; S.msg = ''; top = true; }
        else if ('go' in d) depart(d.go || null);
        else if (d.back) { if ('win' in d) win(); S.view = d.back; top = true; }
        else if ('talk' in d) S.talk++;
        else if ('rest' in d) { S.g -= S.inn; S.hp = S.max; S.msg = '宿で休みました。'; }
        else if ('awake' in d) S.awake = !S.awake;
        else if (d.dummy) { S.msg = d.dummy; }
        else if (d.area) { S.area = d.area; S.msg = ''; }
        else if (d.skipn) S.skip = +d.skipn;
        else if ('skip' in d) { const a = area(S.area), cost = (a.ticket ?? 1) * S.skip; S.tickets -= cost; a.clears += S.skip; S.g += 4200 * S.skip; S.msg = `${a.name}を${S.skip}回ぶん受け取りました。${n(4200 * S.skip)}Gと装備が保管庫に入りました（数値は仮）。`; }
        else if (d.stp) S.add[d.stp] += +d.d;
        else if ('stpreset' in d) STAT.forEach(([k]) => { S.add[k] = 0; });
        else if ('stpok' in d) STAT.forEach(([k]) => { S.stat[k] += S.add[k]; S.stp -= S.add[k]; S.add[k] = 0; });
        else if (d.f) { const set = S.f[d.f]; if (set.has(d.v)) set.delete(d.v); else set.add(d.v); S.shown = 40; }
        else if (d.ft) { S.f[d.ft] = !S.f[d.ft]; S.shown = 40; }
        else if ('fclear' in d) { S.f = { cats: new Set(), rars: new Set(), styles: new Set(), ilMax: '', qMax: '', weaker: false, dup: false, old: false }; }
        else if ('selall' in d) filtered().filter(sellable).forEach((x) => S.sel.add(x.id));
        else if ('selnone' in d) { S.sel.clear(); S.confirm = false; }
        else if ('sell' in d) S.confirm = true;
        else if ('sellno' in d) S.confirm = false;
        else if ('sellyes' in d) { const picked = ITEMS.filter((x) => S.sel.has(x.id)), total = picked.reduce((s, x) => s + x.price, 0); picked.forEach((x) => ITEMS.splice(ITEMS.indexOf(x), 1)); S.g += total; S.sel.clear(); S.confirm = false; S.sold = `${picked.length}件を売って ${n(total)}G を受け取りました。`; }
        else if ('soldok' in d) S.sold = '';
        else if (d.lock) { const x = ITEMS.find((i) => i.id === +d.lock); x.lock = !x.lock; if (x.lock) S.sel.delete(x.id); }
        else if ('more' in d) S.shown += 40;
        else if (d.shopcat) S.shopcat = d.shopcat;
        else if (d.buy) { const x = GOODS.find((g) => g.key === d.buy); S.g -= x.price; x.own = true; S.msg = `${x.name}を買いました。保管庫に入っています。`; }
        else if (d.stone) { S.g -= +d.stone; S.stones++; S.stoneToday++; }
        else if (d.amt) { const cur = parseInt(String(S.amount).replace(/\D/g, ''), 10) || 0; S.amount = String(d.amt === 'g' ? S.g : d.amt === 'bank' ? S.bank : cur + +d.amt); }
        else if (d.bank) { const v = parseInt(String(S.amount).replace(/\D/g, ''), 10) || 0, have = d.bank === 'in' ? S.g : S.bank; if (!v) S.msg = '金額を入れてください。'; else if (v > have) S.msg = d.bank === 'in' ? '手持ちが足りません。' : '預金が足りません。'; else { S.g += d.bank === 'in' ? -v : v; S.bank += d.bank === 'in' ? v : -v; S.msg = `${n(v)}Gを${d.bank === 'in' ? '預けました' : '下ろしました'}。`; S.amount = ''; } }
        else if (d.pslot) S.pslot = S.pslot === +d.pslot ? null : +d.pslot;
        else if (d.cand) { S.party[S.pslot] = +d.cand < 0 ? null : +d.cand; S.pslot = null; }
        else return;
        render();
        view.scrollTop = top ? 0 : keep;
    });
    document.addEventListener('change', (e) => {
        const t = e.target, keep = view.scrollTop;
        if (t.dataset.pick) { const id = +t.dataset.pick; if (t.checked) S.sel.add(id); else S.sel.delete(id); S.confirm = false; }
        else if (t.id === 'v-sort') S.sort = t.value;
        else if (t.id === 'f-il') { S.f.ilMax = t.value; S.shown = 40; }
        else if (t.id === 'f-q') { S.f.qMax = t.value; S.shown = 40; }
        else if (t.id === 'amt') { S.amount = t.value; return; }
        else return;
        render(); view.scrollTop = keep;
    });
    $('opt-state').onchange = (e) => { const v = e.target.value; S.hp = v === 'hurt' ? Math.round(S.max * 0.15) : S.max; S.g = v === 'poor' ? 0 : 184200; S.art = v !== 'noart'; S.msg = ''; render(); };
    $('opt-phone').onchange = (e) => document.body.classList.toggle('phone', e.target.checked);
    $('opt-theme').onchange = (e) => { if (e.target.value) document.documentElement.dataset.theme = e.target.value; else delete document.documentElement.dataset.theme; };
    const start = (location.hash || '').replace('#', '').split('-');
    if (views[start[0]]) { S.view = start[0]; if (start[1] && S.sub[start[0]]) S.sub[start[0]] = start[1]; }
    S.fopen = $('stage').clientWidth > 760;
    document.addEventListener('toggle', (e) => { if (e.target.id === 'fbox') S.fopen = e.target.open; }, true);
    render();
})();
