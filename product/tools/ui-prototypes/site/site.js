// 全体ナビと、地上の周辺画面（観光・交易場・島の道具・お知らせ・オプション・アカウント・お問い合わせ・マニュアル目次）の試作。
// 画面の項目は現行の画面にあるものを使い、島名・人名・数値・文面は架空。APIは呼ばない。
(function () {
    const D = window.PROTO_DATA, T = window.PROTO_TILES;
    const $ = (id) => document.getElementById(id);
    const page = $('page');
    const n = (v) => Number(v).toLocaleString('ja-JP');
    const h = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
    const box = (title, body, extra = '', cls = '') => `<section class="box ${cls}"><header><h2>${title}</h2>${extra}</header><div class="body">${body}</div></section>`;

    const S = {
        view: 'visit', mode: 'member', tool: 'sale', money: 1284, sel: null, msg: {},
        board: [
            { kind: 'public', who: '島主', name: '試験島', at: '10/10 11:42', body: '農場を増やしすぎました。小麦が余っています。' },
            { kind: 'public', who: '他島', name: '向かいの島', at: '10/10 10:15', body: '小麦、交易場に出してもらえたら買います。' },
            { kind: 'secret', dir: '受信', name: '北風島', at: '10/10 09:02', body: '怪獣が出たら声をかけてください。ミサイルを回せます。' },
            { kind: 'public', who: '観光客', name: 'たびびと', at: '10/09 22:30', body: 'きれいな島ですね。' },
        ],
        draft: '', secret: false,
        listings: [
            { id: 1, name: '小麦 5,000トン', seller: '向かいの島', start: 40, cur: 52, top: '北風島', left: 3, end: 130, st: 'outbid', min: 53 },
            { id: 2, name: '工業品 300ユニット', seller: '箱庭連合', start: 120, cur: null, top: null, left: 5, end: 132, st: 'none', min: 120 },
            { id: 3, name: '機械仕掛けの弓 Lv8（レギュラー）', seller: '月見島', start: 300, cur: 340, top: '試験島', left: 1, end: 128, st: 'highest', min: 341, note: '怪獣へのミサイル命中 +8%' },
            { id: 4, name: '石油 20バレル', seller: '試験島', start: 60, cur: 75, top: 'こはる島', left: 2, end: 129, st: 'seller', min: 76 },
        ],
        mine: [{ id: 4, name: '石油 20バレル', end: 129, cur: 75, top: 'こはる島', bids: 2 }, { id: 5, name: '鉱物 100トン', end: 133, cur: null, top: null, bids: 0 }],
        sell: { type: 'resource', res: '小麦', qty: 1000, price: 10, turns: 6, relist: false },
        policies: [
            { key: 'wheat', name: '小麦', unit: 'トン', have: 18600, cap: 50000, pol: 'keep', keep: 14000, min: 12400, price: '1,000トンで約8億円' },
            { key: 'goods', name: '工業品', unit: 'ユニット', have: 1240, cap: 3000, pol: 'sell', keep: 0, min: 0, price: '100ユニットで約25億円' },
            { key: 'minerals', name: '鉱物', unit: 'トン', have: 380, cap: 1000, pol: 'stock', keep: 0, min: 0, price: '100トンで約30億円' },
            { key: 'oil', name: '石油', unit: 'バレル', have: 52, cap: 200, pol: 'keep', keep: 40, min: 0, price: '10バレルで約35億円' },
        ],
        ships: [{ id: 1, name: '漁船', x: 3, y: 3, hp: 3, max: 3, head: '' }, { id: 2, name: '探索船', x: -6, y: 4, hp: 2, max: 4, head: '2' }],
        slots: [['地下都市', '地下農場', null, null], [null, null, null, null]], slot: null,
        quests: [['開発画面を開く', 1, 1, 2], ['地底で5回戦う', 3, 5, 5], ['計画を1つ登録する', 0, 1, 3]],
        grants: [{ id: 1, reason: 'ver 4.18.0 の不具合のお詫び', days: 358, items: [['資金', '500億円'], ['輝石', '30 Pd'], ['スキップチケット', '5枚']] }, { id: 2, reason: 'ログイン100日記念', days: 12, items: [['輝石', '100 Pd']] }],
        ann: null, inquiry: { cat: 'bug', done: false }, theme: 'system', dorm: 7, abandon: '',
    };
    const NAVS = [['top', 'TOP'], ['island', '自島'], ['visit', '観光'], ['trade', '交易場'], ['secretary', 'ノエル'], ['underground', '地底']];
    const LINK = { top: '../top/index.html', island: '../surface/index.html', secretary: '../secretary/index.html', underground: '../underground/index.html' };
    const ANN = [['10/10', 'ver 4.18.3 ログの整理と不具合修正', 'ログの表示を整理しました。\n・自分の島のログと公開ログで、同じ出来事が二重に出ないようにしました。\n・制裁カルマの計算を見直しました。'], ['10/08', 'ver 4.18.0 海底消防署と島の収支明細', '海底消防署を建てられるようになりました。島ログに収支の内訳が出ます。'], ['10/07', 'ver 4.17.0 秘書の愛称', '秘書にフルネームとは別の愛称を付けられます。']];
    const MANUAL = [['はじめの一歩', '島を作って最初の数ターン'], ['土地と施設', '建てられる物と条件'], ['人口と資源', '食料・売却・収支'], ['ミサイルと怪獣', '攻撃と防衛'], ['災害', '台風・地震・噴火など'], ['船', '漁船・探索船・軍艦'], ['交易場', '出品と入札のルール'], ['秘書', '内政スキルと装備'], ['地底', '探索の始め方'], ['戦闘', '能力と行動'], ['装備', '装備Lv・品質・レア度'], ['よくある質問', '島の状態・休止・破棄']];

    // ---------- 全体ナビ ----------
    function gnav() {
        const g = $('gnav'), guest = S.mode === 'guest', under = S.mode === 'under';
        g.className = under ? 'thin' : '';
        if (under) { g.innerHTML = `<span class="logo">箱庭諸島２S＋</span><nav><button type="button" data-mode="member">← 地上へ戻る</button></nav><div class="right"><button type="button" data-pop="bell">通知<span class="n">3</span></button><button type="button" data-pop="acct">≡</button></div>`; return; }
        const items = NAVS.filter(([k]) => guest ? ['top', 'visit'].includes(k) : S.mode === 'noisland' ? !['island', 'trade'].includes(k) : true);
        g.innerHTML = `<span class="logo">箱庭諸島２S＋<small>ver 4.18.3</small></span><nav aria-label="主な行き先">${items.map(([k, l]) => `<button type="button" data-nav="${k}" class="${S.view === k ? 'on' : ''}">${l}</button>`).join('')}</nav>
            <div class="right">${guest ? '<button type="button" data-view="account">ログイン</button>' : `<button type="button" data-pop="bell" aria-label="通知">通知<span class="n">3</span></button>`}<button type="button" data-view="manual">マニュアル</button><button type="button" data-pop="acct" aria-label="メニュー">≡</button></div>`;
    }
    function pop(kind) {
        const p = $('pop');
        if (!kind || (!p.hidden && p.dataset.kind === kind)) { p.hidden = true; return; }
        p.dataset.kind = kind; p.hidden = false;
        p.innerHTML = kind === 'bell'
            ? `<h3>通知</h3><button type="button" data-go="tools:daily">デイリークエスト<span class="pill">未達成 2</span></button><button type="button" data-go="tools:grant">配布倉庫<span class="pill good">受取 2件</span></button><button type="button" data-view="news">お知らせ<span class="pill">新着 1</span></button>`
            : `<h3>${S.mode === 'guest' ? 'メニュー' : '試験プレイヤー'}</h3><button type="button" data-view="tools">島の道具</button><button type="button" data-view="news">お知らせ</button><button type="button" data-view="options">オプション</button><button type="button" data-view="account">アカウント</button><button type="button" data-view="inquiry">お問い合わせ</button><button type="button" data-view="states">読み込み中・エラーの見本</button>`;
    }

    // ---------- 観光 ----------
    // 他の島からは見えない情報を落とす: ミサイル基地は森に見え、持ち主だけの詳細は出さない
    const pub = (c) => (c.facility === 'missile_base' ? { ...c, facility: null, facility_name: null, display_name: '森', terrain: 'forest', terrain_name: '森', details: [['木', '600本']].map(([label, formatted]) => ({ label, formatted })) } : { ...c, details: c.details.filter((d) => !['経験値', '見え方'].includes(d.label)) });
    const TILE = 32, px = (c) => ({ x: c.x * TILE + ((c.y & 1) === 0 ? 16 : 0), y: c.y * TILE });
    function visit() {
        const N = D.NATION, c = S.sel;
        return `<div class="wrap"><h1>N7 試験島<small>島主：試験プレイヤー　「農場を増やしすぎた。」</small><button type="button" data-nav="secretary">秘書を見る</button></h1>
            <dl class="vstats">${[['人口', `${n(N.total_population)}人`], ['面積', `${N.owned_land_cells}マス`], ['推定資金', '1,000億円以上'], ['食料', `${n(N.total_food_tons)}トン`], ['農場', `${n(N.farm_capacity_people)}人`], ['工場', `${n(N.factory_capacity_people)}人`], ['採掘場', `${n(N.mine_capacity_people)}人`], ['怪獣討伐', '4体'], ['カルマ', '0']].map(([k, v]) => `<div><dt>${k}</dt><dd>${v}</dd></div>`).join('')}</dl>
            <div class="visit"><div><div class="vmap" id="vmap"><div class="plane" id="vplane"></div></div><p class="muted" style="margin-top:4px">観光では計画を入れられません。見えるのは公開されている情報だけです。資金は大まかな額で表示します。</p></div>
                <div style="display:grid;gap:12px">${box('選んだマス', c ? `<h3 style="font:400 16px var(--f-game)">${h(c.display_name)} <small class="muted num">(${c.x}, ${c.y})</small></h3><dl class="facts2"><dt>地形</dt><dd>${c.terrain_name}</dd>${c.facility ? `<dt>施設</dt><dd>${c.facility_name}</dd>` : ''}<dt>所有</dt><dd>${c.owner_name ?? 'なし'}</dd>${c.details.map((d) => `<dt>${d.label}</dt><dd>${d.formatted}</dd>`).join('')}</dl>` : '<p class="muted">地図のマスを押すと、ここに情報が出ます。</p>')}
                ${board(true)}</div></div>
            ${box('公開島ログ', D.LOG.slice(0, 2).map((g) => `<h3 style="font:400 12px var(--f-game);color:var(--ink-2);margin-top:4px">第${g.target_turn}ターン</h3>${g.events.filter((e) => !e.summary && !e.confidential).map((e) => `<p style="font-size:13px" class="${e.importance === 'warning' ? 'err' : ''}">${e.importance === 'warning' ? '！' : e.importance === 'notable' ? '◆' : '・'} ${h(e.message)}</p>`).join('')}`).join(''), '<small>収支と秘密通信は出ません</small>')}</div>`;
    }
    function drawMap() {
        const m = $('vmap'), plane = $('vplane'); if (!m) return;
        const o = px(D.CAPITAL);
        plane.innerHTML = '';
        D.cells.map(pub).forEach((c) => {
            const b = document.createElement('button'); b.type = 'button'; b.className = `cell${c.owner_nation_id === 7 ? ' own' : c.owner_nation_id ? ' other' : ''}${S.sel && S.sel.x === c.x && S.sel.y === c.y ? ' sel' : ''}`;
            const p = px(c); b.style.left = `${p.x - o.x}px`; b.style.top = `${p.y - o.y}px`; b.style.backgroundImage = `url(${T.tile(c)})`; b.setAttribute('aria-label', `${c.display_name} (${c.x}, ${c.y})`);
            if (c.ship) b.innerHTML = `<img src="${T.sprite('ship')}" alt="">`; if (c.monster) b.innerHTML = `<img src="${T.sprite('monster')}" alt="">`;
            b.cell = c; plane.appendChild(b);
        });
        S.pan ??= { x: m.clientWidth / 2 - 16, y: m.clientHeight / 2 - 16 };
        plane.style.transform = `translate(${S.pan.x}px, ${S.pan.y}px)`;
        let ptr = null;
        m.onpointerdown = (e) => { ptr = { x: e.clientX, y: e.clientY, sx: e.clientX, sy: e.clientY, moved: false, t: e.target.closest('.cell') }; };
        m.onpointermove = (e) => { if (!ptr) return; if (!ptr.moved && Math.hypot(e.clientX - ptr.sx, e.clientY - ptr.sy) < 6) return; ptr.moved = true; S.pan.x += e.clientX - ptr.x; S.pan.y += e.clientY - ptr.y; ptr.x = e.clientX; ptr.y = e.clientY; plane.style.transform = `translate(${S.pan.x}px, ${S.pan.y}px)`; };
        m.onpointerup = () => { const p = ptr; ptr = null; if (p && !p.moved && p.t) { S.sel = p.t.cell; render(); } };
    }
    function board(visitor) {
        const guest = S.mode === 'guest', len = S.draft.length;
        const list = S.board.filter((e) => !visitor || e.kind === 'public' || true).map((e) => e.kind === 'secret' && visitor
            ? '<li class="secret"><div class="meta"><b>秘密通信</b></div>--秘密通信あり--</li>'
            : `<li class="${e.kind === 'secret' ? 'secret' : ''}"><div class="meta"><b>${e.kind === 'secret' ? `秘密通信（${e.dir}）${h(e.name)}` : `${e.who} ${h(e.name)}`}</b><time>${e.at}</time></div>${h(e.body)}</li>`).join('');
        return box('伝言板', `<ol class="msgs">${list}</ol>
            <form class="f" id="board-form" style="margin-top:8px"><label>${visitor && guest ? '観光客として書く' : '伝言を書く'}<textarea id="board-text" rows="2" maxlength="200" placeholder="140文字まで">${h(S.draft)}</textarea></label>
                <div class="act" style="align-items:center"><span class="muted count ${len > 140 ? 'over' : ''}" id="board-count" style="margin-right:auto">${len} / 140</span>${guest ? '' : `<label class="inline muted"><input type="checkbox" id="board-secret" ${S.secret ? 'checked' : ''}> 秘密通信にする（100億円）</label>`}<button type="submit" class="primary">書き込む</button></div>
                ${S.msg.board ? `<p class="${S.msg.board[0]}">${S.msg.board[1]}</p>` : ''}</form>`, '<small>最新16件</small>');
    }

    // ---------- 交易場 ----------
    function trade() {
        const ST = { seller: ['自分の出品', 'you'], none: ['未入札', ''], highest: ['最高額で入札中', 'good'], outbid: ['上回られた', 'bad'] };
        return `<div class="wrap"><h1>交易場<small>所持資金 ${n(S.money)}億円</small><button type="button" data-view="manual">交易場のルール</button></h1>
            ${box('出品中の商品', `<div class="tw"><table class="t lst"><thead><tr><th>商品</th><th class="hide-sp">出品者</th><th class="r">いまの値段</th><th class="r">残り</th><th class="r">入札</th></tr></thead><tbody>
                ${S.listings.map((l) => `<tr><td>${h(l.name)}<small>${l.note ? `${h(l.note)}　` : ''}<span class="pill ${ST[l.st][1]}">${ST[l.st][0]}</span></small></td><td class="hide-sp">${h(l.seller)}</td>
                    <td class="r">${l.cur === null ? `${n(l.start)}億円<small>入札なし</small>` : `${n(l.cur)}億円<small>${h(l.top)}</small>`}</td><td class="r">${l.left}ターン<small>第${l.end}まで</small></td>
                    <td class="r">${l.st === 'seller' ? '—' : `<span class="bid"><input type="number" inputmode="numeric" min="${l.min}" value="${l.min}" data-bidin="${l.id}" aria-label="${h(l.name)}の入札額"><button type="button" class="${l.st === 'highest' ? '' : 'primary'}" data-bid="${l.id}">${l.st === 'highest' ? '上乗せ' : '入札'}</button></span>`}</td></tr>`).join('')}
                </tbody></table></div>${S.msg.trade ? `<p class="${S.msg.trade[0]}" style="margin-top:6px">${S.msg.trade[1]}</p>` : ''}
                <p class="muted" style="margin-top:6px">入札した額は落札か上回られるまで預かります。預けている分も資金の上限に数えます。</p>`)}
            <div class="cols">${box('自分の出品', S.mine.length ? `<table class="t"><tbody>${S.mine.map((m) => `<tr><td>${h(m.name)}<small>第${m.end}ターンまで　${m.cur === null ? '入札なし' : `${n(m.cur)}億円（${h(m.top)}）`}</small></td><td class="r">${m.bids ? '<span class="muted">入札があるため取り下げ不可</span>' : `<button type="button" data-unlist="${m.id}">取り下げる</button>`}</td></tr>`).join('')}</tbody></table>` : '<p class="muted">出品していません。</p>', `<small>${S.mine.length} / 3件</small>`)}
                ${box('出品する', `<form class="f" id="sell-form"><div class="row"><label>種類<select id="sell-type"><option value="resource" ${S.sell.type === 'resource' ? 'selected' : ''}>資源</option><option value="item" ${S.sell.type === 'item' ? 'selected' : ''}>秘書のアイテム</option></select></label>
                    <label>商品<select id="sell-res">${(S.sell.type === 'resource' ? ['小麦（18,600トン）', '工業品（1,240ユニット）', '鉱物（380トン）', '石油（52バレル）'] : ['古い弓 Lv3（ノービス）', 'ため込みの護符 Lv1（カースド）']).map((o) => `<option>${o}</option>`).join('')}</select></label></div>
                    <div class="row">${S.sell.type === 'resource' ? `<label>数量<input type="number" inputmode="numeric" min="1" value="${S.sell.qty}"></label>` : ''}<label>はじめの値段（億円）<input type="number" inputmode="numeric" min="1" value="${S.sell.price}"></label><label>期間（ターン）<input type="number" inputmode="numeric" min="2" max="12" value="${S.sell.turns}"></label></div>
                    <label class="inline"><input type="checkbox"> 入札がないまま終わったら、同じ条件でもう一度出す</label>
                    <div class="act">${S.mine.length >= 3 ? '<span class="err">出品は3件までです。</span>' : ''}<button type="submit" class="primary" ${S.mine.length >= 3 ? 'disabled' : ''}>出品する</button></div></form>`)}</div></div>`;
    }

    // ---------- 島の道具 ----------
    function tools() {
        const tabs = [['sale', '資源の売り方'], ['ship', '船'], ['board', '伝言板'], ['under', '地下の施設'], ['daily', 'デイリークエスト'], ['grant', '配布倉庫'], ['new', '島を作る']];
        const body = {
            sale: () => box('資源の売り方', S.policies.map((p) => `<div class="pol"><b>${p.name}</b><div><span class="have">${n(p.have)} / ${n(p.cap)}${p.unit}</span><div class="gbar"><i style="width:${p.have / p.cap * 100}%"></i></div></div>
                <div class="seg">${[['sell', '全部売る'], ['keep', 'この量を残す'], ['stock', '上限まで貯める']].map(([k, l]) => `<button type="button" data-pol="${p.key}:${k}" class="${p.pol === k ? 'on' : ''}" ${p.key === 'wheat' && k === 'sell' ? 'disabled title="小麦は全部は売れません"' : ''}>${l}</button>`).join('')}</div>
                ${p.pol === 'keep' ? `<div class="keep"><input type="number" inputmode="numeric" min="${p.min}" value="${p.keep}" data-keep="${p.key}" aria-label="${p.name}を残す量">${p.unit}を残して、超えた分を売る${p.min ? `<span class="muted">（人口ぶんの最低 ${n(p.min)}）</span>` : ''}</div>` : ''}
                <p class="res">${p.pol === 'sell' ? `毎ターン、持っている分を全部売ります。` : p.pol === 'stock' ? `上限の${n(p.cap)}${p.unit}まで貯め、あふれた分だけ売ります。` : `次のターンは約${n(Math.max(0, p.have - p.keep))}${p.unit}を売ります。`}　相場: ${p.price}</p></div>`).join('') + '<p class="muted" style="margin-top:6px">売るのは食料を消費したあとです。変更はすぐ保存されます。</p>', '<small>変更はその場で保存</small>'),
            ship: () => box('船', `<p class="muted">漁船 1/2　観光船 0/1　探索船 1/1　軍艦 0/1</p><table class="t"><thead><tr><th>船</th><th class="r">HP</th><th>進む向き</th></tr></thead><tbody>${S.ships.map((s) => `<tr><td>${s.name}<small class="num">(${s.x}, ${s.y})</small></td><td class="r">${s.hp}/${s.max}</td><td><select data-head="${s.id}">${[['', 'おまかせ'], ['0', '東'], ['1', '北東'], ['2', '北西'], ['3', '西'], ['4', '南西'], ['5', '南東']].map(([v, l]) => `<option value="${v}" ${s.head === v ? 'selected' : ''}>${l}</option>`).join('')}</select></td></tr>`).join('')}</tbody></table><p class="muted" style="margin-top:6px">船を造るのは、開発計画の「船建造」です。休止中は向きを変えられません。</p>`, '<small>2隻</small>'),
            board: () => board(false),
            under: () => box('地下の施設', `<div class="layers"><div class="layer"><span>地上</span><div class="uslot lad" style="grid-column:4">入口</div></div>${S.slots.map((row, li) => `<div class="layer"><span>地下${li + 1}</span>${[0, 1].map((i) => slotBtn(li, i, row[i])).join('')}<div class="uslot lad">はしご</div>${[2, 3].map((i) => slotBtn(li, i, row[i])).join('')}</div>`).join('')}</div>
                ${S.slot ? `<div style="margin-top:8px"><p style="font-size:13px">地下${S.slot[0] + 1}層の${S.slot[1] + 1}番目　${S.slots[S.slot[0]][S.slot[1]] ?? '空き'}</p><div class="subnav" style="margin-top:4px">${S.slots[S.slot[0]][S.slot[1]] ? '<span class="muted">建築済みの枠に入れられる計画はありません。</span>' : ['地下都市', '地下農場', '地下工場', '地下ミサイル基地'].map((f) => `<button type="button" data-ubuild="${f}">${f}を建てる計画を入れる</button>`).join('')}</div></div>` : '<p class="muted" style="margin-top:8px">枠を押すと、入れられる計画が出ます。</p>'}
                ${S.msg.under ? `<p class="ok">${S.msg.under}</p>` : ''}`, '<small>首都の真下・1層に4枠</small>'),
            daily: () => box('デイリークエスト', S.quests.map(([l, p, t, pd]) => `<div class="q"><span>${l}${p >= t ? ' <span class="pill good">達成</span>' : ''}</span><span class="num">${p} / ${t}　<span style="color:var(--paradox)">+${pd} Pd</span></span><div class="gbar"><i style="width:${p / t * 100}%"></i></div></div>`).join('') + '<p class="muted" style="margin-top:6px">毎日0時（日本時間）に戻ります。達成した分の輝石はその場で入ります。</p>', '<small>10/10 の分</small>'),
            grant: () => box('配布倉庫', (S.grants.length ? S.grants.map((g) => `<div class="grant"><b>${h(g.reason)}</b><div class="items">${g.items.map(([k, v]) => `<span><span class="muted">${k}</span> ${v}</span>`).join('')}</div><div style="display:flex;gap:10px;align-items:center"><span class="muted ${g.days < 30 ? 'err' : ''}" style="margin-right:auto">あと${g.days}日</span><button type="button" class="primary" data-claim="${g.id}">受け取る</button></div></div>`).join('') : '<p class="muted">受け取れる配布はありません。</p>') + `${S.msg.grant ? `<p class="ok">${S.msg.grant}</p>` : ''}<p class="muted" style="margin-top:6px">受け取れる期間は365日です。上限を超える分は、期限まで倉庫に残ります。</p>`, '<button type="button" class="quiet">受け取り済みを見る</button>'),
            new: () => box('島を作る', `<form class="f" id="new-form"><label>島の名前<input maxlength="20" placeholder="20文字まで。あとから変えられません" required></label><label>島主の名前<input maxlength="30" placeholder="30文字まで" required></label><label>ひとこと<input maxlength="100" placeholder="100文字まで（空でもよい）"></label>
                <p class="muted">最初の資金は100億円、小麦は10,000トンです。島の場所は自動で決まります。</p><div class="act"><button type="submit" class="primary">島を作る</button></div>${S.msg.new ? `<p class="ok">${S.msg.new}</p>` : ''}</form>`),
        }[S.tool]();
        return `<div class="wrap"><h1>島の道具<small>開発画面から開く小さな画面をまとめて置いています</small></h1><div class="subnav">${tabs.map(([k, l]) => `<button type="button" data-tool="${k}" class="${S.tool === k ? 'on' : ''}">${l}</button>`).join('')}</div>${body}</div>`;
    }
    const slotBtn = (li, i, f) => `<button type="button" class="uslot ${f ? 'built' : ''} ${S.slot && S.slot[0] === li && S.slot[1] === i ? 'on' : ''}" data-uslot="${li}:${i}">${f ?? '空き'}</button>`;

    // ---------- そのほか ----------
    const views = {
        visit, trade, tools,
        news: () => `<div class="wrap"><h1>お知らせ</h1>${S.ann === null ? box('一覧', `<table class="t"><tbody>${ANN.map(([d, t], i) => `<tr><td class="r" style="width:4em">${d}</td><td><button type="button" class="quiet" data-ann="${i}" style="text-align:left">${h(t)}</button></td></tr>`).join('')}</tbody></table>`, '<small>1 / 3ページ</small>') : box(h(ANN[S.ann][1]), `<p class="muted">${ANN[S.ann][0]}</p><p style="white-space:pre-line;line-height:1.9;max-width:42em">${h(ANN[S.ann][2])}</p>`, '<button type="button" data-ann="-1">一覧へ</button>')}</div>`,
        options: () => `<div class="wrap"><h1>オプション</h1><div class="cols"><div style="display:grid;gap:12px">
            ${box('見た目', `<form class="f"><fieldset><legend>配色</legend>${[['system', '端末に合わせる'], ['light', 'ライト'], ['dark', 'ダーク'], ['black', 'ブラック'], ['skyblue', 'SkyBlue（箱庭諸島2 for PHP）'], ['autumn', 'Autumn（箱庭諸島2 for PHP）']].map(([k, l]) => `<label class="inline"><input type="radio" name="theme" value="${k}" ${S.theme === k ? 'checked' : ''}> ${l}</label>`).join('')}</fieldset>
                <fieldset><legend>AIで作られた画像</legend><label class="inline"><input type="radio" name="ai" checked> 表示する</label><label class="inline"><input type="radio" name="ai"> 表示しない</label></fieldset>
                <fieldset><legend>画像のない秘書の見せ方</legend><label class="inline"><input type="radio" name="fb" checked> シルエット</label><label class="inline"><input type="radio" name="fb"> 既定のイラスト</label></fieldset><p class="muted">選ぶとすぐ切り替わります。</p></form>`)}
            ${box('記念碑のデザイン', '<p class="muted">この欄は今回の試作では作っていません。</p>')}</div>
            <div style="display:grid;gap:12px">${box('プロフィール', `<form class="f" id="prof-form"><p class="muted">N7 試験島の公開プロフィールです。島の名前は変えられません。</p><label>島主の名前<input maxlength="30" value="試験プレイヤー" required></label><label>ひとこと<input maxlength="100" value="農場を増やしすぎた。"></label><div class="act"><button type="submit" class="primary">保存する</button></div>${S.msg.prof ? `<p class="ok">${S.msg.prof}</p>` : ''}</form>`)}
            ${box('島を休ませる・手放す', `<form class="f" id="dorm-form"><label>休ませる日数（1〜30日）<input type="number" inputmode="numeric" min="1" max="30" value="${S.dorm}" id="dorm-days"></label><p class="muted">休んでいる間は計画が実行されず、ミサイルの被害も受けません。途中で戻すことはできません。</p><div class="act"><button type="submit">この島を${S.dorm}日休ませる</button></div></form>
                <form class="f" id="ab-form" style="margin-top:12px;border-top:1px solid var(--line);padding-top:10px"><label>島を手放すには、島の名前「試験島」を入れてください<input id="ab-name" value="${h(S.abandon)}" placeholder="試験島"></label><p class="err">手放した島は元に戻せません。秘書と地底の進み具合は残ります。</p><div class="act"><button type="submit" class="danger" ${S.abandon === '試験島' ? '' : 'disabled'}>この島を手放す</button></div></form>${S.msg.dorm ? `<p class="ok">${S.msg.dorm}</p>` : ''}`, '', 'danger')}</div></div></div>`,
        account: () => `<div class="wrap"><h1>アカウント</h1>${S.mode === 'guest' ? box('ログイン', `<p style="font-size:13px">島を運営するにはログインしてください。観光と島一覧はログインなしで見られます。</p><div class="subnav" style="margin-top:8px"><button type="button" class="primary">Discordでログイン</button><button type="button">Googleでログイン</button></div>`) : box('試験プレイヤー', `<table class="t"><tbody><tr><td>Discord<small>連携済み　2026/08/26から</small></td><td class="r"><span class="pill good">連携中</span></td></tr><tr><td>Google<small>連携していません</small></td><td class="r"><button type="button">連携する</button></td></tr></tbody></table><div class="act" style="display:flex;justify-content:flex-end;margin-top:10px"><button type="button">ログアウト</button></div>`)}</div>`,
        inquiry: () => `<div class="wrap"><h1>お問い合わせ</h1>${S.inquiry.done ? box('送りました', `<p style="font-size:13px">受付番号 <b class="num">INQ-1042</b>　返事はお知らせかDiscordで行います。</p><div class="subnav" style="margin-top:8px"><button type="button" data-inq="reset">もう1件送る</button><button type="button" data-nav="visit">戻る</button></div>`) : box('内容', `<form class="f" id="inq-form"><div class="row"><label>種類<select>${['不具合', '要望', 'アイデア', '秘書のファンアート', 'その他'].map((o) => `<option>${o}</option>`).join('')}</select></label><label style="flex:3 1 220px">件名<input maxlength="80" required></label></div><label>本文<textarea rows="6" maxlength="2000" required placeholder="いつ・どの画面で・何をしたら・どうなったか"></textarea></label><label>画像（1枚まで）<input type="file" accept="image/*"></label><p class="muted">島の番号、いまのターン、版数は自動で付きます。</p><div class="act"><button type="submit" class="primary">送る</button></div></form>`)}</div>`,
        manual: () => `<div class="wrap"><h1>マニュアル<small>目次だけの見本です。本文は別のページにあります</small></h1><div class="toc">${MANUAL.map(([t, d]) => `<button type="button"><b>${t}</b><small>${d}</small></button>`).join('')}</div></div>`,
        states: () => `<div class="wrap"><h1>読み込み中・エラーの見本<small>どの画面でも同じ形で出す</small></h1><div class="cols"><div class="state"><b>読み込み中…</b>交易場の出品を取りに行っています。</div><div class="state"><b>読み込めませんでした</b>通信状態を確認して、もう一度お試しください。<button type="button">再読み込み</button></div><div class="state"><b>ここを見るにはログインが必要です</b><button type="button" class="primary" data-view="account">ログインする</button></div><div class="state"><b>いま世界を更新しています</b>ターンの更新が終わるまで、数十秒お待ちください。計画はそのまま残ります。</div></div></div>`,
    };
    function render() {
        gnav();
        page.innerHTML = S.mode === 'under' ? '<div class="wrap"><div class="state"><b>ここから下は地底の画面</b>地底では全体ナビを細い帯に縮め、下端の地底メニューと二重にしません。イベントと戦闘の間は、この帯も隠します。</div></div>' : views[S.view]();
        if (S.view === 'visit' && S.mode !== 'under') drawMap();
    }
    document.addEventListener('click', (e) => {
        const b = e.target.closest('button');
        if (!b) { if (!e.target.closest('#pop')) $('pop').hidden = true; return; }
        const d = b.dataset, keep = page.scrollTop; let top = false;
        if (d.pop) return pop(d.pop);
        $('pop').hidden = true;
        if (d.nav) { if (LINK[d.nav]) { S.msg.nav = d.nav; page.innerHTML = `<div class="wrap"><div class="state"><b>${NAVS.find((x) => x[0] === d.nav)[1]}</b>この行き先は別の試作にあります（${LINK[d.nav].replace('../', '').replace('/index.html', '')}）。<button type="button" data-nav="visit">観光に戻る</button></div></div>`; S.view = d.nav; gnav(); return; } S.view = d.nav; top = true; }
        else if (d.view) { S.view = d.view; top = true; }
        else if (d.go) { const [v, t] = d.go.split(':'); S.view = v; S.tool = t; top = true; }
        else if (d.mode) { S.mode = d.mode; $('opt-state').value = d.mode; }
        else if (d.tool) { S.tool = d.tool; S.msg = {}; }
        else if (d.bid) { const l = S.listings.find((x) => x.id === +d.bid), v = +page.querySelector(`[data-bidin="${l.id}"]`).value; if (v < l.min) S.msg.trade = ['err', `${l.name}は${n(l.min)}億円以上で入札してください。`]; else if (v > S.money) S.msg.trade = ['err', '資金が足りません。']; else { l.cur = v; l.top = '試験島'; l.st = 'highest'; l.min = v + 1; S.msg.trade = ['ok', `${l.name}に${n(v)}億円で入札しました。`]; } }
        else if (d.unlist) { S.mine = S.mine.filter((m) => m.id !== +d.unlist); S.msg.trade = ['ok', '出品を取り下げました。']; }
        else if (d.pol) { const [k, v] = d.pol.split(':'); S.policies.find((p) => p.key === k).pol = v; }
        else if (d.uslot) { const [a, c] = d.uslot.split(':').map(Number); S.slot = S.slot && S.slot[0] === a && S.slot[1] === c ? null : [a, c]; S.msg.under = ''; }
        else if (d.ubuild) { S.msg.under = `${d.ubuild}を建てる計画を、開発計画の末尾に入れました。`; }
        else if (d.claim) { const g = S.grants.find((x) => x.id === +d.claim); S.grants = S.grants.filter((x) => x !== g); S.msg.grant = `「${g.reason}」を受け取りました。`; }
        else if (d.ann) S.ann = +d.ann < 0 ? null : +d.ann;
        else if (d.inq) S.inquiry.done = false;
        else return;
        render(); page.scrollTop = top ? 0 : keep;
    });
    document.addEventListener('submit', (e) => {
        e.preventDefault(); const id = e.target.id, keep = page.scrollTop;
        if (id === 'board-form') { const t = $('board-text').value.trim(), secret = $('board-secret')?.checked; if (!t) S.msg.board = ['err', '本文を入れてください。']; else if (t.length > 140) S.msg.board = ['err', '140文字までです。']; else if (secret && S.view === 'tools') S.msg.board = ['err', '秘密通信は、相手の島の伝言板から送ります。']; else { S.board.unshift({ kind: secret ? 'secret' : 'public', dir: '送信', who: S.mode === 'guest' ? '観光客' : S.view === 'visit' ? '他島' : '島主', name: S.mode === 'guest' ? 'たびびと' : S.view === 'visit' ? '向かいの島' : '試験島', at: 'いま', body: t }); if (secret) S.money -= 100; S.draft = ''; S.msg.board = ['ok', secret ? '秘密通信を送りました（100億円）。' : '書き込みました。']; } }
        else if (id === 'sell-form') { S.mine.push({ id: Date.now(), name: `${$('sell-res').value.split('（')[0]}`, end: 127 + 6, cur: null, top: null, bids: 0 }); S.msg.trade = ['ok', '出品しました。']; }
        else if (id === 'new-form') S.msg.new = '島を作りました（試作では画面は変わりません）。';
        else if (id === 'prof-form') S.msg.prof = '保存しました。';
        else if (id === 'dorm-form') S.msg.dorm = `島を${S.dorm}日休ませました（試作では状態は変わりません）。`;
        else if (id === 'ab-form') S.msg.dorm = '島を手放しました（試作では状態は変わりません）。';
        else if (id === 'inq-form') S.inquiry.done = true;
        render(); page.scrollTop = keep;
    });
    document.addEventListener('input', (e) => {
        const t = e.target;
        if (t.id === 'board-text') { S.draft = t.value; const c = $('board-count'); c.textContent = `${t.value.length} / 140`; c.classList.toggle('over', t.value.length > 140); }
        else if (t.id === 'ab-name') { S.abandon = t.value; page.querySelector('#ab-form button').disabled = t.value !== '試験島'; }
        else if (t.id === 'dorm-days') { S.dorm = Math.min(30, Math.max(1, +t.value || 1)); page.querySelector('#dorm-form button').textContent = `この島を${S.dorm}日休ませる`; }
    });
    document.addEventListener('change', (e) => {
        const t = e.target, keep = page.scrollTop;
        if (t.id === 'sell-type') S.sell.type = t.value;
        else if (t.dataset.keep) { const p = S.policies.find((x) => x.key === t.dataset.keep); p.keep = Math.max(p.min, +t.value || 0); }
        else if (t.dataset.head) { S.ships.find((s) => s.id === +t.dataset.head).head = t.value; return; }
        else if (t.name === 'theme') { S.theme = t.value; const v = t.value === 'light' ? 'light' : ['dark', 'black'].includes(t.value) ? 'dark' : ''; if (v) document.documentElement.dataset.theme = v; else delete document.documentElement.dataset.theme; return; }
        else if (t.id === 'board-secret') { S.secret = t.checked; return; }
        else return;
        render(); page.scrollTop = keep;
    });
    $('opt-state').onchange = (e) => { S.mode = e.target.value; if (S.mode === 'guest' && !['visit', 'news', 'account', 'manual', 'states'].includes(S.view)) S.view = 'visit'; render(); };
    $('opt-phone').onchange = (e) => { document.body.classList.toggle('phone', e.target.checked); S.pan = null; requestAnimationFrame(render); };
    $('opt-theme').onchange = (e) => { if (e.target.value) document.documentElement.dataset.theme = e.target.value; else delete document.documentElement.dataset.theme; };
    const start = (location.hash || '').replace('#', '').split('-');
    if (views[start[0]]) { S.view = start[0]; if (start[1]) S.tool = start[1]; }
    render();
})();
