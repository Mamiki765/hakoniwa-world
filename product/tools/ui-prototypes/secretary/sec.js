// 秘書ページの試作。項目は SecretaryProfile / Secretary（types.ts）にあるものを使い、
// スキル名はRuleset v36の名称。秘書の名前・経歴・数値・装備名・効果の説明文は試作用の仮のもの。APIは呼ばない。
(function () {
    const $ = (id) => document.getElementById(id);
    const n = (v) => Number(v).toLocaleString('ja-JP');
    const h = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
    const SKILLS = [
        ['農業政策', 14, 0.62, '小麦の生産量が増える', '農場で小麦を生産すると経験値が入る'], ['特産品開発', 11, 0.3, '工業品の生産量が増える', '工場で工業品を生産する'],
        ['金鉱脈調査', 6, 0.81, '鉱物の生産量が増える', '採掘場で鉱物を生産する'], ['油田開発', 2, 0.1, '海底油田の産出量が増える', '海底油田を見つける'],
        ['省エネ研究', 5, 0.44, '電力の消費が減る', '発電所を動かす'], ['森林管理', 9, 0.5, '木の育ちが良くなる', '伐採・植林をする'],
        ['最終防衛ライン', 3, 0.2, '首都へのミサイルを迎撃する', '防衛施設で迎撃する'], ['少子化対策', 7, 0.9, '町や都市の人口の上限が上がる', '人口が増える'],
        ['不屈', 4, 0.05, '人口の自然増加が増える', '災害の被害を受ける'], ['船舶運用', 8, 0.35, '船が持ち帰る収入が増える', '船が移動する'], ['海軍', 1, 0.0, '軍艦が攻撃を避けやすくなる', '軍艦で戦う'],
    ];
    const ITEMS = [
        { id: 1, name: '古い弓', cat: '弓', lv: 3, rar: 'novice', rl: 'ノービス', text: '怪獣へのミサイル命中 +3%' },
        { id: 2, name: '秘書のスーツ', cat: '服', lv: 5, rar: 'regular', rl: 'レギュラー', text: '資金の上限 +5%' },
        { id: 3, name: '金庫の鍵', cat: '装飾', lv: 4, rar: 'regular', rl: 'レギュラー', text: '資金の上限 +4%' },
        { id: 4, name: '怪獣よけのお香', cat: '装飾', lv: 2, rar: 'novice', rl: 'ノービス', text: '怪獣の出現率が下がる' },
        { id: 5, name: '満腹草', cat: '装飾', lv: 6, rar: 'high_quality', rl: 'ハイクオリティ', text: '食料の消費 −6%' },
        { id: 6, name: 'ため込みの護符', cat: '装飾', lv: 1, rar: 'cursed', rl: 'カースド', text: '食料の上限 +10%、資金繰り −2億円' },
        { id: 7, name: '機械仕掛けの弓', cat: '弓', lv: 8, rar: 'regular', rl: 'レギュラー', text: '怪獣へのミサイル命中 +8%' },
    ];
    const UG = [['武器', '洞窟鋼の剣', 'Lv12 品質104%'], ['防具', '革の胸当て', 'Lv10 品質98%'], ['装飾1', '守りの指輪', 'Lv8'], ['装飾2', '輝石のお守り', 'Lv16'], ['装飾3', null, ''], ['共鳴', null, '']];
    const BIO = '北の小さな港町の生まれ。帳簿をつけるのが得意で、島主に請われて秘書になった。\n地底の入口を見つけてからは、昼は島の帳簿、夜は洞窟という二重生活を送っている。\n好きなものは焼き魚。苦手なものは台風。';
    const ACH = [['島の秘書', '秘書に名前を付けた', '2026/08/26'], ['はじめての討伐', '怪獣を初めて倒した', '2026/09/02'], ['深きを知る者', '試練2をクリアした', '2026/09/20']];
    const IMG = [['icon', 'アイコン', true], ['bust', 'バスト', true], ['full', '全身', true], ['aicon', '覚醒アイコン', false], ['abust', '覚醒バスト', false], ['afull', '覚醒全身', false]];
    const S = { title: '島の秘書', name: 'ノエル・アーデン', nick: 'ノエル', note: '', tickets: 2, mode: 'owner', tab: 'intro', view: 'full', bio: BIO, edit: false, slots: [2, 3, 5, 1, null], pick: null };
    const item = (id) => ITEMS.find((x) => x.id === id);

    function render() {
        const owner = S.mode === 'owner', bare = S.mode === 'noimage';
        const tabs = [['intro', '紹介'], ['skills', 'スキル'], ['equip', '装備'], ...(owner ? [['store', '倉庫'], ['award', '実績'], ['set', '設定']] : [])];
        if (!tabs.some((t) => t[0] === S.tab)) S.tab = 'intro';
        const stat = [['体力', 86], ['力', 124], ['技', 71], ['精神', 48], ['素早さ', 93]];
        const max = Math.max(...stat.map((x) => x[1]));
        const body = {
            intro: () => `<h2 class="sec-h">経歴${owner && !S.edit ? '<button type="button" data-edit>書き直す</button>' : ''}</h2>
                ${S.edit ? `<form class="bio-edit" id="bio-form"><textarea id="bio-text" maxlength="1000" aria-label="経歴">${h(S.bio)}</textarea><div class="row"><span id="bio-count">${S.bio.length} / 1000文字</span><button type="button" data-cancel>やめる</button><button type="submit" class="primary">保存する</button></div></form>`
                    : S.bio ? `<p class="bio">${h(S.bio)}</p>` : '<p class="empty">経歴はまだ書かれていません。</p>'}
                <h2 class="sec-h">地上の装備</h2><div class="slots">${S.slots.map((id) => { const x = item(id); return x ? `<div class="slot rar-${x.rar}"><small>${x.cat}・${x.rl} Lv${x.lv}</small><b>${h(x.name)}</b><span>${h(x.text)}</span></div>` : ''; }).join('')}</div>`,
            skills: () => `<p class="note" style="font-size:13px;color:var(--ink-2)">島の運営で勝手に育つ力です。${owner ? '' : '効果だけを公開しています。'}</p><div class="skills">${SKILLS.map(([name, lv, xp, eff, how]) => `<div class="skill"><b>${name}<small>${eff}${owner ? `　／　育て方: ${how}` : ''}</small></b><span class="lv">Lv${lv}</span>${owner ? (() => { const req = 400 + lv * 120, exp = Math.round(req * xp); return `<span class="bar"><span class="xp"><span><i>EXP</i>${n(exp)}<small>/${n(req)}</small></span><span><i>NEXT</i>${n(req - exp)}</span></span><span class="sb"><i style="width:${xp * 100}%"></i></span></span>`; })() : ''}</div>`).join('')}</div>`,
            equip: () => `<h2 class="sec-h">地上の装備 <small style="font:12px var(--f-body);color:var(--ink-2)">5枠・同じ分類は2つまで</small></h2>
                <div class="slots">${S.slots.map((id, i) => { const x = item(id); return `<${owner ? 'button type="button"' : 'div'} class="slot ${x ? `rar-${x.rar}` : 'empty'}${S.pick === i ? ' pick' : ''}" data-slot="${i}"><small>${i + 1}枠目${x ? `・${x.cat}・${x.rl} Lv${x.lv}` : ''}</small><b>${x ? h(x.name) : '空き'}</b><span>${x ? h(x.text) : owner ? '押して装備を選ぶ' : ''}</span></${owner ? 'button' : 'div'}>`; }).join('')}</div>
                ${S.pick !== null ? `<div class="picker"><h3><span>${S.pick + 1}枠目に入れる装備</span><button type="button" data-pickclose class="quiet">閉じる</button></h3>${S.slots[S.pick] ? '<button type="button" class="it" data-put="0"><b>外す</b><span></span></button>' : ''}${ITEMS.filter((x) => !S.slots.includes(x.id)).map((x) => `<button type="button" class="it rar-${x.rar}" data-put="${x.id}"><span><b>${h(x.name)}</b>　${x.cat}・${x.rl} Lv${x.lv}</span><span>${h(x.text)}</span></button>`).join('') || '<p class="empty">入れられる装備が倉庫にありません。</p>'}</div>` : ''}
                ${bare ? '' : `<h2 class="sec-h">地底の装備${owner ? '<button type="button" data-dummy>地底で変える</button>' : ''}</h2><div class="slots">${UG.map(([slot, name, sub]) => `<div class="slot ${name ? '' : 'empty'}"><small>${slot}</small><b>${name ?? '空き'}</b><span>${sub}</span></div>`).join('')}</div>`}`,
            store: () => `<h2 class="sec-h">倉庫 <small style="font:12px var(--f-body);color:var(--ink-2)">${ITEMS.length + (S.tickets ? 1 : 0)} / 50</small></h2>
                <div class="rows">${S.tickets ? `<div class="rowi"><span><b>わくわくチケット</b><small>チケット・レギュラー　引くと装備が1つ手に入る　×${S.tickets}</small></span><button type="button" class="primary" data-ticket>使う</button></div>` : ''}
                ${ITEMS.map((x) => `<div class="rowi rar-${x.rar}"><span><b>${h(x.name)}</b><small>${x.cat}・${x.rl} Lv${x.lv}　${h(x.text)}${S.slots.includes(x.id) ? '　装備中' : ''}</small></span><button type="button" class="danger" data-sellitem="${x.id}" ${S.slots.includes(x.id) ? 'disabled' : ''}>${S.slots.includes(x.id) ? '装備中' : `${x.lv * 20}億円で売る`}</button></div>`).join('')}</div>
                ${S.note ? `<p class="okmsg">${h(S.note)}</p>` : ''}<p class="note" style="font-size:12px;color:var(--ink-2);margin-top:6px">装備中の物は、外してから売れます。売った物は戻せません。</p>`,
            award: () => `<h2 class="sec-h">肩書き</h2><div class="seg" style="flex-wrap:wrap">${['（なし）', ...ACH.map((a) => a[0])].map((t) => `<button type="button" data-title="${t}" class="${(S.title || '（なし）') === t ? 'on' : ''}">${t}</button>`).join('')}</div><p class="note" style="font-size:12px;color:var(--ink-2);margin-top:4px">名前の上に出ます。押すとすぐ変わります。</p>
                <h2 class="sec-h">取得した実績</h2><div class="rows">${ACH.map(([t, d, at]) => `<div class="rowi"><span><b>${t}</b><small>${d}</small></span><span style="font:12px var(--f-game);color:var(--ink-2)">${at}</span></div>`).join('')}</div>`,
            set: () => `<h2 class="sec-h">名前</h2><form class="setf" id="name-form"><label>フルネーム<input id="set-name" maxlength="40" value="${h(S.name)}" required></label><label>愛称（空でもよい）<input id="set-nick" maxlength="20" value="${h(S.nick)}"></label><button type="submit" class="primary">保存する</button></form>
                ${S.note ? `<p class="okmsg">${h(S.note)}</p>` : ''}<p class="note" style="font-size:12px;color:var(--ink-2)">戦闘ログやパーティーには愛称が出ます。愛称が空ならフルネームが出ます。</p>
                <h2 class="sec-h">画像</h2><div class="slots">${IMG.map(([k, l, has]) => `<div class="slot ${has ? '' : 'empty'}"><small>${l}</small><b>${has ? '登録済み' : '未登録'}</b><span>${has ? '制作方法: 自作' : '登録しないと通常の画像を使います'}</span><button type="button" data-dummy style="margin-top:4px">${has ? '替える' : '登録する'}</button></div>`).join('')}</div>
                <p class="note" style="font-size:12px;color:var(--ink-2);margin-top:6px">画像を登録するときに、制作方法（自作・AI生成・依頼や許諾・その他）と、作者・権利表記を入れます。</p>`,
        }[S.tab];

        $('sec').innerHTML = `<div class="sheet">
            <div class="art"><div class="frame ${S.view}">${bare ? '<span class="none">画像なし</span>' : `<svg viewBox="0 0 120 220" role="img" aria-label="秘書の立ち絵の枠"><path class="body" d="M60 14c14 0 24 11 24 26s-10 27-24 27-24-12-24-27 10-26 24-26zM22 214c0-70 8-134 38-134s38 64 38 134z"/><text x="60" y="${S.view === 'bust' ? 108 : 150}">立ち絵の枠</text></svg>`}</div>
                ${bare ? '' : `<div class="art-tools"><div class="seg small"><button type="button" data-art="full" class="${S.view === 'full' ? 'on' : ''}">全身</button><button type="button" data-art="bust" class="${S.view === 'bust' ? 'on' : ''}">バスト</button></div><details><summary aria-label="画像について">i</summary><div><b>画像について</b><br>制作方法: 自作<br>作者・権利表記: （ここに表記）</div></details></div>`}</div>
            <div class="info">
                <header class="who"><div><p class="ttl">${h(S.title)}</p><h1>${h(S.name)}</h1>${S.nick ? `<p class="nick">愛称「${h(S.nick)}」</p>` : ''}</div>
                    <p class="isle">${owner ? '' : 'プレイヤー03の秘書　'}<button type="button" data-dummy>N7 試験島</button></p>
                    ${owner && !bare ? '<button type="button" class="primary go" data-dummy>地底へ</button>' : ''}</header>
                <div class="nums">
                    <section class="blk"><h2>地上<small>島の運営</small></h2><dl>
                        <div><dt>内政Lv</dt><dd class="lvl">${SKILLS.reduce((s, x) => s + x[1], 0)}</dd></div>
                        <div><dt>資金・食料の上限</dt><dd class="lvl">+14%</dd></div>
                        <div><dt>討伐経験値</dt><dd class="lvl">${n(3820)}</dd></div></dl></section>
                    <section class="blk"><h2>地底<small>${bare ? '' : '戦技'}</small></h2>${bare ? '<p class="none">まだ地底に降りていません。</p>' : `<dl>
                        <div><dt>戦闘Lv</dt><dd class="lvl">42</dd></div><div><dt>最大HP</dt><dd class="lvl">${n(1480)}</dd></div>
                        ${stat.map(([k, v]) => `<div><dt>${k}</dt><span class="sb"><i style="width:${v / max * 100}%"></i></span><dd>${v}</dd></div>`).join('')}</dl>`}</section>
                </div>
                <nav class="stabs" aria-label="秘書ページの切り替え">${tabs.map(([k, l]) => `<button type="button" data-stab="${k}" class="${S.tab === k ? 'on' : ''}">${l}</button>`).join('')}</nav>
                <div>${body()}</div>
            </div></div>`;
    }
    document.addEventListener('click', (e) => {
        const b = e.target.closest('button'); if (!b) return;
        const d = b.dataset;
        if (d.stab) { S.tab = d.stab; S.pick = null; S.edit = false; S.note = ''; }
        else if ('ticket' in d) { S.tickets--; S.note = 'わくわくチケットを使い、「怪獣よけのお香」を手に入れました（結果は仮）。'; }
        else if (d.sellitem) { const i = ITEMS.findIndex((x) => x.id === +d.sellitem); S.note = `${ITEMS[i].name}を売りました。`; ITEMS.splice(i, 1); }
        else if (d.title) S.title = d.title === '（なし）' ? '' : d.title;
        else if (d.art) S.view = d.art;
        else if ('edit' in d) S.edit = true;
        else if ('cancel' in d) S.edit = false;
        else if (d.slot) S.pick = S.pick === +d.slot ? null : +d.slot;
        else if ('pickclose' in d) S.pick = null;
        else if (d.put) { S.slots[S.pick] = +d.put || null; S.pick = null; }
        else if ('dummy' in d) { b.textContent += '（試作では開きません）'; b.disabled = true; return; }
        else return;
        render();
    });
    document.addEventListener('input', (e) => { if (e.target.id === 'bio-text') $('bio-count').textContent = `${e.target.value.length} / 1000文字`; });
    document.addEventListener('submit', (e) => { if (e.target.id === 'name-form') { e.preventDefault(); S.name = $('set-name').value.trim() || S.name; S.nick = $('set-nick').value.trim(); S.note = '保存しました。'; render(); return; }
        if (e.target.id !== 'bio-form') return; e.preventDefault(); S.bio = $('bio-text').value.trim(); S.edit = false; render(); });
    $('opt-state').onchange = (e) => { S.mode = e.target.value; S.pick = null; S.edit = false; render(); };
    $('opt-phone').onchange = (e) => document.body.classList.toggle('phone', e.target.checked);
    $('opt-theme').onchange = (e) => { if (e.target.value) document.documentElement.dataset.theme = e.target.value; else delete document.documentElement.dataset.theme; };
    if (document.getElementById('stage').clientWidth <= 760) S.view = 'bust';
    render();
})();
