// 地底ホームの試作。ホーム・冒険（行き先選び）・店の3画面で、RPG側の画面の組み方を確かめる。
// 地名と敵の名前は config/underground-alpha-v1.php の名称。レベル・所持金・商品・案内人の台詞は試作用の仮のもの。APIは呼ばない。
(function () {
    const $ = (id) => document.getElementById(id);
    const app = $('app'), view = $('view');
    const n = (v) => Number(v).toLocaleString('ja-JP');
    const h = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

    const MENU = [
        ['home', 'ホーム', ''], ['adventure', '冒険', '探索・試練'], ['character', '秘書', '能力・装備・戦法'],
        ['shop', '店', '装備・研磨・銀行'], ['exchange', '交流', 'パーティー・行商人'], ['villa', '別荘', '日誌・回想'],
    ];
    const AREAS = [
        { key: 'shallow_caves', tab: 'explore', name: '浅い洞窟', il: [1, 20], clears: 34, need: 20, ticket: 1, foes: '地底鼠、洞窟蟲、腐食スライム' },
        { key: 'black_crystal', tab: 'explore', name: '黒晶洞', il: [20, 60], clears: 12, need: 20, ticket: 1, foes: '黒晶蝙蝠、晶殻蟲、黒晶術師' },
        { key: 'shining_kingdom', tab: 'explore', name: '輝きの王国', il: [60, 120], clears: 3, need: 20, ticket: 2, foes: '城門の盾兵、王都の衛兵、宮廷の氷術師' },
        { key: 'yunagi', tab: 'explore', name: '夕凪の帰港地', il: [120, 223], locked: '試練3をクリアすると行けるようになります。' },
        { key: 'vault', tab: 'secret', name: '輝きの王国の宝物庫', il: [60, 120], clears: 1, key_cost: 1, keys: 2, foes: '宝物庫の番人' },
        { key: 'bahamul1', tab: 'trial', name: '黒竜バハムル・初級1', il: [200, 223], clears: 0, foes: '黒竜バハムル', rounds: 100 },
    ];
    const GOODS = [
        { key: 'knife', cat: 'weapon', name: '護身用ナイフ', il: 1, price: 0, text: '最初から持っている小さな刃物', own: true },
        { key: 'sword', cat: 'weapon', name: '洞窟鋼の剣', il: 12, price: 800, text: '力 +14' },
        { key: 'bow', cat: 'weapon', name: '狩人の弓', il: 14, price: 950, text: '技 +12、素早さ +4' },
        { key: 'staff', cat: 'weapon', name: '祈りの杖', il: 18, price: 1400, text: '精神 +18' },
        { key: 'leather', cat: 'armor', name: '革の胸当て', il: 10, price: 600, text: '体力 +10' },
        { key: 'mail', cat: 'armor', name: '鎖かたびら', il: 22, price: 2200, text: '体力 +24', lock: '黒晶洞を10回クリアすると買えます' },
        { key: 'ring', cat: 'accessory', name: '守りの指輪', il: 8, price: 500, text: '最大HP +60' },
        { key: 'charm', cat: 'accessory', name: '輝石のお守り', il: 16, price: 1200, text: '覚醒ゲージの初期値 +10%' },
    ];
    const TALK = {
        hello: 'いらっしゃい。今日はどこまで潜るつもり？',
        tip: '浅い洞窟を20回抜けたら、スキップチケットで戦闘を省けるようになるよ。',
        self: 'わたしのこと？　ただの案内人さ。名前はあんたがくれたんだろう。',
        bought: 'まいど。装備は「秘書」からつけ替えられるよ。',
        poor: 'それは手持ちが足りないね。預金は買い物に使えないから、銀行で下ろしておいで。',
    };
    const S = {
        view: 'home', g: 12480, bank: 50000, tickets: 7, lv: 42, name: 'ルチル', hp: 1180, max: 1480, aw: 620, awMax: 1000, xp: 3200, stp: 5,
        dest: 'shallow_caves', tab: 'explore', area: 'shallow_caves', skip: 1, cat: 'weapon', talk: 'hello', cool: 0, inn: 120, msg: '',
        mates: [{ name: 'ガーネット', hp: 2100, max: 2100 }, { name: 'セレス', hp: 640, max: 1050 }, { name: 'トパーズ', hp: 1200, max: 1200 }],
    };
    const area = (k) => AREAS.find((a) => a.key === k);
    const meter = (v, max, cls = '') => { const r = v / max; return `<div class="meter ${cls} ${cls ? '' : r <= 0.25 ? 'crit' : r <= 0.5 ? 'low' : ''}"><span style="width:${r * 100}%"></span></div>`; };
    const silhouette = (label) => `<div class="portrait"><svg viewBox="0 0 120 220" role="img" aria-label="${label}"><path class="body" d="M60 14c14 0 24 11 24 26s-10 27-24 27-24-12-24-27 10-26 24-26zM22 214c0-70 8-134 38-134s38 64 38 134z"/><text x="60" y="150">${label}</text></svg></div>`;

    function wallet() {
        $('wallet').innerHTML = `<h1>地底</h1><span><b>Lv</b>${S.lv} ${h(S.name)}</span><span class="${S.g < 500 ? 'low' : ''}"><b>手持ち</b>${n(S.g)}G</span><span><b>預金</b>${n(S.bank)}G</span><span><b>スキップチケット</b>${S.tickets}枚</span>`;
    }
    function menu() {
        $('menu').innerHTML = MENU.map(([k, label, sub]) => `<button type="button" data-view="${k}" class="${S.view === k ? 'on' : ''}">${label}${sub ? `<small>${sub}</small>` : ''}</button>`).join('');
    }
    function goButton() {
        const d = area(S.dest), hurt = S.hp / S.max < 0.25;
        return `<button type="button" class="primary big" data-go ${S.cool > 0 || hurt ? 'disabled' : ''}>${S.cool > 0 ? `あと${S.cool}秒` : `${h(d.name)}へ出発する`}</button>
            ${hurt ? '<p class="warn">HPが少なすぎて出発できません。宿で休んでください。</p>' : ''}${S.msg ? `<p class="note">${h(S.msg)}</p>` : ''}`;
    }

    const views = {
        home() {
            const d = area(S.dest), full = S.hp >= S.max;
            return `<div class="scene"><span class="tag-bg">背景の枠（クリアした場所の絵を選べる）</span>
                    ${silhouette('秘書の立ち絵')}
                    <section class="self win" aria-label="自分の秘書">
                        <p class="lv">Lv <strong>${S.lv}</strong>${S.stp ? `<button type="button" data-view="character">STP ${S.stp} を配分</button>` : ''}</p>
                        <h2>${h(S.name)}</h2><p class="path">戦技</p>
                        <div class="bar hp"><i>HP</i>${meter(S.hp, S.max)}<b>${n(S.hp)}/${n(S.max)}</b></div>
                        <div class="bar aw"><i>覚醒</i>${meter(S.aw, S.awMax, 'aw')}<b>${Math.round(S.aw / S.awMax * 100)}%</b></div>
                        <p class="rest"><span>次のLvまで ${n(S.xp)}</span><button type="button" data-rest ${full || S.g < S.inn ? 'disabled' : ''}>${full ? '全快' : `宿で休む ${S.inn}G`}</button></p>
                    </section>
                </div>
                <div class="talk win"><b>案内人</b>${TALK[S.talk === 'bought' || S.talk === 'poor' ? 'hello' : S.talk]}</div>
                <div class="cols">
                    <section class="pane win"><h2>パーティー<button type="button" data-view="exchange">編成する</button></h2>
                        <div class="mates"><div class="mate"><i>${S.name[0]}</i><span>${h(S.name)}<small>リーダー</small></span>${meter(S.hp, S.max)}</div>
                        ${S.mates.map((m) => `<div class="mate"><i>${m.name[0]}</i><span>${h(m.name)}</span>${meter(m.hp, m.max)}</div>`).join('')}</div></section>
                    <section class="pane win go"><h2>次の冒険<button type="button" data-view="adventure">行き先を変える</button></h2>
                        <h3>${h(d.name)}</h3><p>装備Lv ${d.il[0]}〜${d.il[1]}　出る敵: ${h(d.foes)}</p>${goButton()}</section>
                </div>`;
        },
        adventure() {
            const tabs = [['explore', '探索'], ['trial', '試練'], ['secret', '秘密の場所']];
            const list = AREAS.filter((a) => a.tab === S.tab);
            if (!list.some((a) => a.key === S.area)) S.area = list[0].key;
            const a = area(S.area), canSkip = !a.locked && a.need && a.clears >= a.need, cost = (a.ticket ?? 1) * S.skip;
            return `<div class="tabs">${tabs.map(([k, l]) => `<button type="button" data-tab="${k}" class="${S.tab === k ? 'on' : ''}">${l}</button>`).join('')}</div>
                <div class="cols">
                    <section class="pane win"><h2>行き先</h2><div class="areas">${list.map((x) => `<button type="button" class="area${x.key === S.area ? ' on' : ''}${x.locked ? ' locked' : ''}" data-area="${x.key}"><span class="cur">▶</span><span class="nm">${x.locked ? '？？？' : h(x.name)}<small>${x.locked ? h(x.locked) : `クリア ${x.clears}回${x.key === S.dest ? '　次の冒険に設定中' : ''}`}</small></span><span class="il">${x.locked ? '' : `装備Lv ${x.il[0]}〜${x.il[1]}`}</span></button>`).join('')}</div></section>
                    <section class="pane win detail"><h2>くわしく</h2>
                        ${a.locked ? `<h3>？？？</h3><p class="note">${h(a.locked)}</p>` : `<h3>${h(a.name)}</h3>
                        <dl><dt>手に入る装備</dt><dd>Lv ${a.il[0]}〜${a.il[1]}</dd><dt>出る敵</dt><dd>${h(a.foes)}</dd><dt>クリア</dt><dd>${a.clears}回</dd>${a.key_cost ? `<dt>入るのに必要</dt><dd>鍵 ${a.key_cost}本（所持 ${a.keys}本）</dd>` : ''}${a.rounds ? `<dt>制限</dt><dd>${a.rounds}ラウンド</dd>` : ''}</dl>
                        <button type="button" class="primary big" data-go="${a.key}" ${S.cool > 0 ? 'disabled' : ''}>${S.cool > 0 ? `あと${S.cool}秒` : a.tab === 'trial' ? '挑戦する' : 'ここを探索する'}</button>
                        ${a.need ? `<div class="skip">${canSkip ? `<span>戦闘を省いて結果だけ受け取る</span><span class="cnt">${[1, 5, 10].map((v) => `<button type="button" data-skipn="${v}" class="${S.skip === v ? 'primary' : ''}">×${v}</button>`).join('')}</span><button type="button" data-skip ${cost > S.tickets ? 'disabled' : ''}>チケット${cost}枚でスキップ</button>` : `<span class="note">あと${a.need - a.clears}回クリアすると、スキップチケットが使えるようになります（${a.clears}/${a.need}）。</span>`}</div>` : ''}
                        ${S.msg ? `<p class="note">${h(S.msg)}</p>` : ''}`}
                    </section>
                </div>`;
        },
        shop() {
            const cats = [['weapon', '武器'], ['armor', '防具'], ['accessory', '装飾']];
            return `<div class="scene shop-scene"><span class="tag-bg">店の背景の枠</span>${silhouette('案内人の立ち絵')}
                    <div class="talk win"><b>案内人</b>${TALK[S.talk]}<div class="choices"><button type="button" data-talk="tip">何か教えて</button><button type="button" data-talk="self">あなたのことを聞く</button></div></div></div>
                <section class="pane win"><h2>装備を買う<span class="tabs" style="margin-left:auto">${cats.map(([k, l]) => `<button type="button" data-cat="${k}" class="${S.cat === k ? 'on' : ''}">${l}</button>`).join('')}</span></h2>
                    <div class="tbl"><table class="goods"><thead><tr><th>名前</th><th class="r">装備Lv</th><th class="r">値段</th><th></th></tr></thead><tbody>
                    ${GOODS.filter((x) => x.cat === S.cat).map((x) => `<tr class="${x.own ? 'have' : ''}"><td>${h(x.name)}<small>${h(x.lock ?? x.text)}</small></td><td class="r">${x.il}</td><td class="r">${x.price ? `${n(x.price)}G` : '—'}</td>
                        <td class="r">${x.own ? '持っている' : x.lock ? '未解禁' : `<button type="button" data-buy="${x.key}" class="${x.price <= S.g ? 'primary' : ''}">${x.price <= S.g ? '買う' : 'G不足'}</button>`}</td></tr>`).join('')}
                    </tbody></table></div>
                    <p class="note">買い物に使えるのは手持ちのGだけです。預金は銀行で下ろしてから使います。</p></section>`;
        },
    };
    function render() {
        wallet(); menu();
        view.innerHTML = views[S.view]?.() ?? `<section class="pane win"><h2>${MENU.find((m) => m[0] === S.view)[1]}</h2><p class="note">この画面は今回の試作では作っていません。ホーム・冒険・店の3画面で、地底側の組み方を確かめるための試作です。</p></section>`;
    }
    let timer;
    function depart(key) {
        if (key) S.dest = key;
        const d = area(S.dest);
        if (d.key_cost) d.keys -= d.key_cost;
        S.msg = `${d.name}へ出発しました。本番ではここで戦闘ログに切り替わります（戦闘ログは別の試作にあります）。`;
        S.hp = Math.max(60, S.hp - 260); S.aw = Math.min(S.awMax, S.aw + 140); S.cool = 5; d.clears++;
        clearInterval(timer); timer = setInterval(() => { S.cool--; if (S.cool <= 0) clearInterval(timer); render(); }, 1000);
    }
    document.addEventListener('click', (e) => {
        const b = e.target.closest('button'); if (!b) return;
        const d = b.dataset;
        if (d.view) { S.view = d.view; S.msg = ''; app.dataset.view = S.view; }
        else if ('go' in d) depart(d.go || null);
        else if ('rest' in d) { S.g -= S.inn; S.hp = S.max; S.mates.forEach((m) => { m.hp = m.max; }); S.msg = '宿で休み、全員のHPが回復しました。'; }
        else if (d.tab) { S.tab = d.tab; S.msg = ''; }
        else if (d.area) { S.area = d.area; S.msg = ''; }
        else if (d.skipn) S.skip = +d.skipn;
        else if ('skip' in d) { const a = area(S.area), cost = (a.ticket ?? 1) * S.skip; S.tickets -= cost; a.clears += S.skip; S.g += 180 * S.skip; S.msg = `${a.name}を${S.skip}回スキップしました。${n(180 * S.skip)}Gと戦利品を受け取りました（数値は仮）。`; }
        else if (d.cat) S.cat = d.cat;
        else if (d.talk) S.talk = d.talk;
        else if (d.buy) { const x = GOODS.find((gd) => gd.key === d.buy); if (x.price > S.g) S.talk = 'poor'; else { S.g -= x.price; x.own = true; S.talk = 'bought'; } }
        else return;
        render();
        if (d.view) view.scrollTop = 0;
    });
    $('opt-state').onchange = (e) => { const v = e.target.value; S.hp = v === 'hurt' ? 240 : 1180; S.g = v === 'poor' ? 320 : 12480; S.msg = ''; render(); };
    $('opt-phone').onchange = (e) => document.body.classList.toggle('phone', e.target.checked);
    $('opt-theme').onchange = (e) => { if (e.target.value) document.documentElement.dataset.theme = e.target.value; else delete document.documentElement.dataset.theme; };
    const start = (location.hash || '').replace('#', '');
    if (MENU.some((m) => m[0] === start)) S.view = start;
    render();
})();
