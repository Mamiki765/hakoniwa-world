// 管理ページの試作。項目は現行の管理ページにあるもの（ターン操作、配布、島整理、ログ整理、お知らせ管理、
// お問い合わせ一覧、案内人の会話、行商人の会話）。島名・人名・数値・文面は架空。APIは呼ばない。
(function () {
    const $ = (id) => document.getElementById(id);
    const page = $('page');
    const n = (v) => Number(v).toLocaleString('ja-JP');
    const h = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
    const box = (title, body, extra = '', cls = '') => `<section class="box ${cls}"><header><h2>${title}</h2>${extra}</header><div class="body">${body}</div></section>`;
    const ISLANDS = ['北風島', 'こはる島', '試験島', 'みなと島', '月見島', '石ころ島', 'はじまりの島', '雲の上島'].map((name, i) => ({ id: i + 1, name, state: i === 6 ? '休止中' : '活動中' }));
    const ASSETS = [['money', '資金', '億円'], ['wheat', '小麦', 'トン'], ['paradox', '輝石', 'Pd'], ['ticket', 'スキップチケット', '枚'], ['g', '地底のG', 'G']];
    const S = {
        view: 'turn', done: '', preview: null, late: true,
        dist: { kind: 'all', ids: new Set(), reason: '', assets: {} }, ab: { id: '', pub: '', note: '' },
        purge: [{ key: 'a', name: '島ログ', old: 480, cand: 412, oldest: '2026/08/28', stop: '集計がまだの記録に当たった' }, { key: 'b', name: '戦闘ログ', old: 500, cand: 0, oldest: '2026/09/02', stop: '先頭の記録が集計待ち' }],
        ann: [{ id: 3, at: '10/10', title: 'ver 4.18.3 ログの整理と不具合修正', body: 'ログの表示を整理しました。' }, { id: 2, at: '10/08', title: 'ver 4.18.0 海底消防署と島の収支明細', body: '海底消防署を建てられるようになりました。' }], annEdit: null,
        inq: [{ id: 'INQ-1042', cat: '不具合', subj: '交易場で入札額が戻らない', who: 'プレイヤー04（N4 みなと島）', at: '10/10 11:20', body: '上回られたあと、入札した120億円が戻っていないように見えます。', turn: 127, ver: '4.18.3' }, { id: 'INQ-1041', cat: '要望', subj: '保管庫の一括売却に条件を足したい', who: 'プレイヤー02（N2 こはる島）', at: '10/09 22:05', body: 'エンチャントで絞って売りたいです。', turn: 121, ver: '4.18.0' }], inqOpen: null,
        guide: [{ id: 1, line: 'いらっしゃい。今日はどこまで潜るつもり？', choices: [['何か教えて', '浅い洞窟を20回抜けたら、スキップチケットが使えるよ。'], ['あなたのことを聞く', 'ただの案内人さ。']], unlock: '最初から', on: true }, { id: 2, line: '黒い竜の噂を聞いたかい。', choices: [['くわしく', '封印の地の奥にいる。歪んだ輝石がないと近づけないよ。']], unlock: '試練2クリア後', on: true }],
        merch: [{ id: 1, q: 'このあたりで一番売れる物は？', a: '共鳴結晶だね。', on: true }, { id: 2, q: '地上の話を聞かせて', a: '台風が来ると、こっちまで水が染みてくるんだ。', on: false }], edit: null,
    };
    const MENU = [['turn', 'ターン', () => (S.late ? '遅れ' : '')], ['dist', '配布', () => ''], ['abandon', '島整理', () => ''], ['purge', 'ログ整理', () => String(S.purge.reduce((a, r) => a + r.cand, 0) || '')], ['ann', 'お知らせ管理', () => ''], ['inq', 'お問い合わせ', () => String(S.inq.length)], ['guide', '案内人の会話', () => ''], ['merch', '行商人の会話', () => '']];
    const confirmBox = (title, rows, note) => box(`${title}　内容を確かめてください`, `<dl class="sumlist">${rows.map(([k, v]) => `<dt>${k}</dt><dd>${v}</dd>`).join('')}</dl><p class="err" style="margin-top:8px">${note}</p><div class="act" style="display:flex;gap:8px;justify-content:flex-end;margin-top:8px"><button type="button" data-cancel>戻って直す</button><button type="button" class="danger" data-exec>この内容で実行する</button></div>`, '', 'confirm');

    const views = {
        turn: () => box('ターン', `<p class="turnbig">第127ターン</p><p style="font-size:13px;margin-top:4px">次の予定 10/10 12:00　${S.late ? '<span class="pill bad">予定を過ぎています</span>' : '<span class="pill good">予定どおり</span>'}</p>
            <p class="muted" style="margin-top:8px">自動の更新が予定を過ぎたときだけ、手動で1ターンぶん進められます。進めた結果は必ず確かめてください。</p>
            <div class="subnav" style="margin-top:8px"><button type="button" class="${S.late ? 'primary' : ''}" data-turn ${S.late ? '' : 'disabled'}>第128ターンを手動で進める</button><button type="button" data-reload>状態を読み直す</button></div>`),
        dist: () => {
            const d = S.dist;
            return box('配布', `<form class="f" id="dist-form"><p class="muted">1人につき、下の量を配ります。受け取れる期間は365日です。同じ人には1回だけ届きます。</p>
                <label>配る相手<select id="d-kind">${[['all', '全員（島なし・休止中を含む 現在24人）'], ['nation', '島を選ぶ'], ['user', '番号で指定']].map(([k, l]) => `<option value="${k}" ${d.kind === k ? 'selected' : ''}>${l}</option>`).join('')}</select></label>
                ${d.kind === 'nation' ? `<div class="checks">${ISLANDS.map((i) => `<label class="inline"><input type="checkbox" data-isl="${i.id}" ${d.ids.has(i.id) ? 'checked' : ''}> N${i.id} ${i.name}（${i.state}）</label>`).join('')}</div>` : ''}
                ${d.kind === 'user' ? '<label>番号（例: 1-4,10,14）<input id="d-ids" placeholder="1-4,10,14"></label>' : ''}
                <label>理由（受け取る人に見えます）<textarea id="d-reason" rows="2" maxlength="2000" required>${h(d.reason)}</textarea></label>
                <div class="assets">${ASSETS.map(([k, l, u]) => `<label>${l}（${u}）<input type="number" inputmode="numeric" min="0" data-asset="${k}" value="${d.assets[k] ?? ''}" placeholder="0"></label>`).join('')}</div>
                <div class="act"><button type="submit" class="primary">内容を確かめる</button></div></form>`);
        },
        abandon: () => box('島整理', `<form class="f" id="ab-form"><p class="muted">選んだ島を整理します。関係する競売を決着させ、島・地上の資産・船を片付けます。プレイヤー・秘書・地底の進み具合は残ります。</p>
            <label>対象の島<select id="ab-id" required><option value="">島を選ぶ</option>${ISLANDS.map((i) => `<option value="${i.id}" ${String(S.ab.id) === String(i.id) ? 'selected' : ''}>N${i.id} ${i.name}（${i.state}）</option>`).join('')}</select></label>
            <label>公開する理由（重大ニュースに載ります）<textarea id="ab-pub" rows="2" maxlength="2000" required>${h(S.ab.pub)}</textarea></label>
            <label>運営メモ（非公開・空でもよい）<textarea id="ab-note" rows="2" maxlength="4000">${h(S.ab.note)}</textarea></label>
            <div class="act"><button type="submit">内容を確かめる</button></div></form>`),
        purge: () => box('ログ整理', `<p class="muted">30日を過ぎた記録を、集計と検証が済んだ連続した範囲だけ消します。確かめるだけでは何も消えません。</p>
            <table class="t" style="margin-top:6px"><thead><tr><th>種類</th><th class="r">古い記録</th><th class="r">消せる数</th><th>止まった理由</th><th></th></tr></thead><tbody>${S.purge.map((r) => `<tr><td>${r.name}<small>一番古い ${r.oldest}</small></td><td class="r">${n(r.old)}</td><td class="r">${n(r.cand)}</td><td>${r.cand ? '—' : r.stop}</td><td class="r"><button type="button" data-purge="${r.key}" ${r.cand ? '' : 'disabled'}>範囲を確かめる</button></td></tr>`).join('')}</tbody></table>`),
        ann: () => S.annEdit ? box(S.annEdit.id ? 'お知らせを直す' : 'お知らせを書く', `<form class="f" id="ann-form"><label>題名<input id="ann-title" maxlength="100" value="${h(S.annEdit.title)}" required></label><label>本文（Markdownが使えます）<textarea id="ann-body" rows="8" maxlength="8000" required>${h(S.annEdit.body)}</textarea></label>
                <div class="act"><button type="button" data-annx>やめる</button><button type="button" data-annprev>見え方を確かめる</button><button type="submit" class="primary">保存する</button></div>
                ${S.annEdit.prev ? `<div class="state" style="text-align:left;place-items:start"><b>${h(S.annEdit.title) || '（題名なし）'}</b><span style="white-space:pre-line;color:var(--ink)">${h(S.annEdit.body)}</span></div>` : ''}</form>`)
            : box('お知らせ管理', `<table class="t"><tbody>${S.ann.map((a) => `<tr><td class="r" style="width:4em">${a.at}</td><td>${h(a.title)}</td><td class="r"><button type="button" data-anne="${a.id}">直す</button> <button type="button" class="danger" data-annd="${a.id}">消す</button></td></tr>`).join('')}</tbody></table>`, '<button type="button" class="primary" data-anne="0">新しく書く</button>'),
        inq: () => S.inqOpen ? (() => { const q = S.inq.find((x) => x.id === S.inqOpen); return box(h(q.subj), `<dl class="sumlist"><dt>受付番号</dt><dd class="num">${q.id}</dd><dt>種類</dt><dd>${q.cat}</dd><dt>送った人</dt><dd>${h(q.who)}</dd><dt>日時</dt><dd>${q.at}　第${q.turn}ターン　ver ${q.ver}</dd></dl><p style="white-space:pre-line;margin-top:8px;line-height:1.8">${h(q.body)}</p>`, '<button type="button" data-inq="">一覧へ</button>'); })()
            : box('お問い合わせ', `<table class="t"><thead><tr><th>番号</th><th>種類</th><th>件名</th><th class="hide-sp">送った人</th><th class="r">日時</th></tr></thead><tbody>${S.inq.map((q) => `<tr><td class="num">${q.id}</td><td><span class="pill ${q.cat === '不具合' ? 'bad' : ''}">${q.cat}</span></td><td><button type="button" class="quiet" data-inq="${q.id}" style="text-align:left">${h(q.subj)}</button></td><td class="hide-sp">${h(q.who)}</td><td class="r">${q.at}</td></tr>`).join('')}</tbody></table>`, '<small>1 / 1ページ</small>'),
        guide: () => S.edit ? box('案内人の会話を直す', `<form class="f" id="g-form"><label>最初のひとこと<input id="g-line" value="${h(S.edit.line)}" required></label>${[0, 1, 2].map((i) => `<div class="row"><label>選択肢${i + 1}${i ? '（空でもよい）' : ''}<input data-gc="${i}" value="${h(S.edit.choices[i]?.[0] ?? '')}" ${i ? '' : 'required'}></label><label style="flex:2 1 220px">返事${i + 1}<input data-gr="${i}" value="${h(S.edit.choices[i]?.[1] ?? '')}" ${i ? '' : 'required'}></label></div>`).join('')}
                <div class="row"><label>出る条件<select id="g-unlock">${['最初から', '試練1クリア後', '試練2クリア後', '試練3クリア後'].map((u) => `<option ${S.edit.unlock === u ? 'selected' : ''}>${u}</option>`).join('')}</select></label><label class="inline" style="flex:none"><input type="checkbox" id="g-on" ${S.edit.on ? 'checked' : ''}> 使う</label></div>
                <div class="act"><button type="button" data-editx>やめる</button><button type="submit" class="primary">保存する</button></div></form>`)
            : box('案内人の会話', `<table class="t"><tbody>${S.guide.map((g) => `<tr><td>${h(g.line)}<small>選択肢 ${g.choices.length}つ　${g.unlock}</small></td><td class="r"><span class="pill ${g.on ? 'good' : ''}">${g.on ? '使う' : '止めている'}</span></td><td class="r"><button type="button" data-ge="${g.id}">直す</button></td></tr>`).join('')}</tbody></table>`, '<button type="button" class="primary" data-ge="0">足す</button>'),
        merch: () => box('行商人の会話', `<table class="t"><tbody>${S.merch.map((m) => `<tr><td>${h(m.q)}<small>${h(m.a)}</small></td><td class="r"><label class="inline" style="justify-content:flex-end"><input type="checkbox" data-mon="${m.id}" ${m.on ? 'checked' : ''}> 使う</label></td><td class="r"><button type="button" class="danger" data-md="${m.id}">消す</button></td></tr>`).join('')}</tbody></table>
            <form class="f" id="m-form" style="margin-top:10px"><div class="row"><label>質問<input id="m-q" required></label><label style="flex:2 1 220px">答え<input id="m-a" required></label></div><div class="act"><button type="submit" class="primary">足す</button></div></form>`),
    };
    function render() {
        $('amenu').innerHTML = `<h1>管理ページ</h1>${MENU.map(([k, l, c]) => `<button type="button" data-view="${k}" class="${S.view === k ? 'on' : ''}">${l}${c() ? `<span class="n">${c()}</span>` : ''}</button>`).join('')}`;
        page.innerHTML = `<div class="wrap">${S.done ? `<p class="done">${h(S.done)}</p>` : ''}${S.preview ? confirmBox(...S.preview.show) : views[S.view]()}</div>`;
    }
    document.addEventListener('click', (e) => {
        const b = e.target.closest('button'); if (!b) return;
        const d = b.dataset;
        if (d.view) { S.view = d.view; S.preview = null; S.done = ''; S.edit = null; S.annEdit = null; S.inqOpen = null; }
        else if ('turn' in d) S.preview = { show: ['ターンを手動で進める', [['進めるターン', '第128ターン'], ['予定', '10/10 12:00（過ぎています）'], ['対象', '全8島の計画・災害・売却']], '一度進めたターンは戻せません。自動の更新と重ならないことを確かめてください。'], run: () => { S.late = false; return '第128ターンを進めました。結果を「ターン」で確かめてください。'; } };
        else if ('reload' in d) S.done = '状態を読み直しました。';
        else if (d.purge) { const r = S.purge.find((x) => x.key === d.purge); S.preview = { show: ['ログ整理', [['種類', r.name], ['消す数', `${n(r.cand)}件`], ['範囲', `${r.oldest} から連続した${n(r.cand)}件`]], '消した記録は戻せません。集計には残ります。'], run: () => { r.old -= r.cand; const c = r.cand; r.cand = 0; r.stop = '消せる範囲はもうありません'; return `${r.name}を${n(c)}件消しました。`; } }; }
        else if ('cancel' in d) S.preview = null;
        else if ('exec' in d) { S.done = S.preview.run(); S.preview = null; }
        else if (d.anne) { S.annEdit = +d.anne ? { ...S.ann.find((a) => a.id === +d.anne) } : { id: 0, title: '', body: '' }; }
        else if ('annx' in d) S.annEdit = null;
        else if ('annprev' in d) { S.annEdit.title = $('ann-title').value; S.annEdit.body = $('ann-body').value; S.annEdit.prev = true; }
        else if (d.annd) { const a = S.ann.find((x) => x.id === +d.annd); S.preview = { show: ['お知らせを消す', [['題名', h(a.title)], ['日付', a.at]], '消したお知らせは戻せません。'], run: () => { S.ann = S.ann.filter((x) => x !== a); return 'お知らせを消しました。'; } }; }
        else if ('inq' in d) S.inqOpen = d.inq || null;
        else if (d.ge) S.edit = +d.ge ? JSON.parse(JSON.stringify(S.guide.find((g) => g.id === +d.ge))) : { id: 0, line: '', choices: [], unlock: '最初から', on: true };
        else if ('editx' in d) S.edit = null;
        else if (d.md) { S.merch = S.merch.filter((m) => m.id !== +d.md); S.done = '会話を消しました。'; }
        else return;
        render(); page.scrollTop = 0;
    });
    document.addEventListener('submit', (e) => {
        e.preventDefault(); const id = e.target.id;
        if (id === 'dist-form') {
            const d = S.dist; d.reason = $('d-reason').value;
            const list = ASSETS.filter(([k]) => +d.assets[k] > 0).map(([k, l, u]) => `${l} ${n(d.assets[k])}${u}`);
            const who = d.kind === 'all' ? '全員（24人）' : d.kind === 'nation' ? `${d.ids.size}島: ${ISLANDS.filter((i) => d.ids.has(i.id)).map((i) => i.name).join('、')}` : `番号 ${$('d-ids').value}`;
            if (!list.length) { S.done = '配る物を1つ以上入れてください。'; return render(); }
            if (d.kind === 'nation' && !d.ids.size) { S.done = '島を1つ以上選んでください。'; return render(); }
            S.done = ''; S.preview = { show: ['配布', [['相手', h(who)], ['1人あたり', list.join('　')], ['理由', h(d.reason)], ['受け取れる期間', '365日']], '配ったあとの取り消しはできません。'], run: () => { S.dist = { kind: 'all', ids: new Set(), reason: '', assets: {} }; return '配布を登録しました。配布倉庫に届いています。'; } };
        } else if (id === 'ab-form') {
            S.ab = { id: $('ab-id').value, pub: $('ab-pub').value, note: $('ab-note').value }; const isl = ISLANDS.find((i) => String(i.id) === S.ab.id);
            S.done = ''; S.preview = { show: ['島整理', [['対象', `N${isl.id} ${isl.name}（${isl.state}）`], ['片付ける物', '島・地上の資産・船・関係する競売'], ['残る物', 'プレイヤー・秘書・地底の進み具合'], ['公開する理由', h(S.ab.pub)], ['運営メモ', h(S.ab.note) || '（なし）']], '整理した島は戻せません。公開する理由は重大ニュースに載ります。'], run: () => { S.ab = { id: '', pub: '', note: '' }; return `${isl.name}を整理しました。`; } };
        } else if (id === 'ann-form') { const a = { id: S.annEdit.id || Date.now(), at: S.annEdit.at ?? '10/10', title: $('ann-title').value, body: $('ann-body').value }; S.ann = S.annEdit.id ? S.ann.map((x) => (x.id === a.id ? a : x)) : [a, ...S.ann]; S.annEdit = null; S.done = 'お知らせを保存しました。'; }
        else if (id === 'g-form') { const g = { id: S.edit.id || Date.now(), line: $('g-line').value, choices: [0, 1, 2].map((i) => [page.querySelector(`[data-gc="${i}"]`).value, page.querySelector(`[data-gr="${i}"]`).value]).filter(([c]) => c), unlock: $('g-unlock').value, on: $('g-on').checked }; S.guide = S.edit.id ? S.guide.map((x) => (x.id === g.id ? g : x)) : [...S.guide, g]; S.edit = null; S.done = '会話を保存しました。'; }
        else if (id === 'm-form') { S.merch.push({ id: Date.now(), q: $('m-q').value, a: $('m-a').value, on: true }); S.done = '会話を足しました。'; }
        render(); page.scrollTop = 0;
    });
    document.addEventListener('change', (e) => {
        const t = e.target;
        if (t.id === 'd-kind') { S.dist.kind = t.value; S.dist.reason = $('d-reason').value; render(); }
        else if (t.dataset.isl) { if (t.checked) S.dist.ids.add(+t.dataset.isl); else S.dist.ids.delete(+t.dataset.isl); }
        else if (t.dataset.asset) S.dist.assets[t.dataset.asset] = t.value;
        else if (t.dataset.mon) S.merch.find((m) => m.id === +t.dataset.mon).on = t.checked;
    });
    $('opt-phone').onchange = (e) => document.body.classList.toggle('phone', e.target.checked);
    $('opt-theme').onchange = (e) => { if (e.target.value) document.documentElement.dataset.theme = e.target.value; else delete document.documentElement.dataset.theme; };
    const start = (location.hash || '').replace('#', '');
    if (views[start]) S.view = start;
    render();
})();
