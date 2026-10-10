// TOPと島一覧の試作。島名・島主名・秘書名・数値・ニュースの文面はすべて架空。
// 列の構成は現行の島一覧（人口・面積・資金・食料・農場・工場・採掘場・生存ターン）に合わせてある。APIは呼ばない。
(function () {
    const $ = (id) => document.getElementById(id);
    const n = (v) => Number(v).toLocaleString('ja-JP');
    const h = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
    const names = ['北風島', 'こはる島', '試験島', 'みなと島', '月見島', '石ころ島', 'はじまりの島', '雲の上島', '南十字島', 'ねこやなぎ島', '鉄火島', '静かの島', '七色島', '風待ち島', 'どんぐり島', '朝凪島', '夕立島', '新島'];
    let seed = 11; const r = () => { seed = (seed * 1664525 + 1013904223) >>> 0; return seed / 4294967296; };
    const ISLANDS = names.map((name, i) => {
        const scale = Math.pow(0.86, i) * (0.85 + r() * 0.3);
        return {
            id: i + 1, name, owner: `プレイヤー${String(i + 1).padStart(2, '0')}`, secretary: i % 5 === 4 ? null : `秘書${String(i + 1).padStart(2, '0')}`,
            pop: Math.round(184000 * scale / 100) * 100, area: Math.round(20 + 70 * scale), money: Math.round(9000 * scale * (0.5 + r())),
            food: Math.round(90000 * scale * (0.4 + r()) / 100) * 100, farm: Math.round(160 * scale) * 1000, factory: Math.round(220 * scale * r()) * 1000, mine: Math.round(60 * scale * r()) * 1000,
            turns: Math.round(900 * Math.pow(0.9, i) + r() * 40), state: i === 12 ? 'dormant' : 'active', fin: i === 15 ? 4 : 0, karma: i === 6 ? 12 : 0, awards: i === 0 ? 3 : i === 3 ? 1 : 0, kills: i === 1 ? 7 : 0,
        };
    });
    const ME = 3;
    const COLS = [['pop', '人口', '人'], ['area', '面積', 'マス'], ['money', '資金', '億円'], ['food', '食料', 'トン'], ['farm', '農場', '人'], ['factory', '工場', '人'], ['mine', '採掘場', '人'], ['turns', '生存', 'ターン']];
    const NEWS = [
        [127, [['notable', '北風島が繁栄賞を受賞しました。'], ['warning', 'こはる島(4,-12)に怪獣いのらが出現しました。'], ['info', '新島が発見されました。']]],
        [126, [['warning', 'みなと島で大規模な台風被害が出ました。'], ['notable', '月見島が人口10万人を達成しました。']]],
    ];
    const LOGS = [
        [127, [['info', '試験島(1,1)で農場が整備されました。'], ['info', '石ころ島(-20,8)で採掘場が建設されました。'], ['notable', '試験島(0,1)の村が町に発展しました。'], ['info', '鉄火島がミサイルを3発発射しました。']]],
        [126, [['warning', '台風が発生しました。試験島(1,-1)の農場が被害を受けました。'], ['info', '朝凪島(33,2)で港が建設されました。']]],
    ];
    const S = { mode: 'member', sort: 'pop', q: '' };

    const badges = (x) => `${x.id === ME && S.mode === 'member' ? '<span class="st you">自分の島</span>' : ''}${x.state === 'dormant' ? '<span class="st sleep">休止中</span>' : ''}${x.fin ? `<span class="st fin">資金繰り${x.fin}</span>` : ''}${x.karma ? `<span class="st karma">カルマ${x.karma}</span>` : ''}${x.awards ? `<span class="st award">賞×${x.awards}</span>` : ''}${x.kills ? `<span class="st award">討伐×${x.kills}</span>` : ''}`;
    const news = (groups) => groups.map(([t, evs]) => `<h3>第${t}ターン</h3>${evs.map(([imp, m]) => `<p class="${imp}"><i>${imp === 'warning' ? '！' : imp === 'notable' ? '◆' : '・'}</i><span>${h(m)}</span></p>`).join('')}`).join('');

    function render() {
        const late = S.mode === 'delayed', guest = S.mode === 'guest';
        const list = S.mode === 'empty' ? [] : ISLANDS.filter((x) => !S.q || x.name.includes(S.q) || x.owner.includes(S.q)).slice().sort((a, b) => b[S.sort] - a[S.sort]);
        const rankOf = new Map(ISLANDS.slice().sort((a, b) => b.pop - a.pop).map((x, i) => [x.id, i + 1]));
        const col = COLS.find((c) => c[0] === S.sort);
        const newsBox = (cls) => `<section class="box news ${cls}"><header><h2>世界の出来事</h2><div class="seg small" id="news-tab"><button type="button" data-news="major" class="${S.news !== 'log' ? 'on' : ''}">重大ニュース</button><button type="button" data-news="log" class="${S.news === 'log' ? 'on' : ''}">公開ログ</button></div></header>
            <div class="body">${S.mode === 'empty' ? '<p class="empty">まだ出来事はありません。</p>' : news(S.news === 'log' ? LOGS : NEWS)}${S.news === 'log' && S.mode !== 'empty' ? '<p class="pager"><button type="button" disabled>新しい2ターン</button><button type="button">過去の2ターン</button></p>' : ''}</div></section>`;
        $('top').innerHTML = `<div class="wrap">
            <header class="title"><div><h1>箱庭諸島２S＋</h1><p>島と秘書とダンジョンと</p></div>
                <div class="enter">${guest ? '<span>島を運営するにはログインしてください。</span><button type="button" class="primary">Discordでログイン</button><button type="button">Googleでログイン</button>' : S.mode === 'empty' ? '<button type="button" class="primary">最初の島を作る</button>' : '<button type="button" class="primary">自分の島へ（試験島）</button><button type="button">秘書</button><button type="button">地底</button>'}</div></header>
            <aside class="side">
                <section class="box"><header><h2>ターン</h2><small>2時間ごとに更新</small></header><div class="body">
                    <div class="turn${late ? ' late' : ''}"><div class="now">${S.mode === 'empty' ? 1 : 127}<small>${S.mode === 'empty' ? '箱庭暦1年1月' : '箱庭暦11年7月'}</small></div>
                        <div class="next">${late ? '<b>更新が遅れています</b>' : '次の更新まで <b id="count">0:42:18</b>'}</div>
                        <div class="sub">${late ? '予定の12:00を過ぎても更新が終わっていません。管理者が確認中です。計画はそのまま残ります。' : '予定 10/10 12:00　前回 10/10 10:00'}</div></div>
                    <p class="world"><span><b>島</b>${list.length ? ISLANDS.length : 0}</span><span><b>総人口</b>${n(S.mode === 'empty' ? 0 : ISLANDS.reduce((s, x) => s + x.pop, 0))}人</span></p></div></section>
                <section class="box"><header><h2>お知らせ</h2><button type="button" class="quiet">すべて見る</button></header><div class="body"><ul class="notice">
                    <li><time>10/10</time><button type="button">ver 4.18.3 ログの整理と不具合修正</button></li><li><time>10/08</time><button type="button">ver 4.18.0 海底消防署と島の収支明細</button></li><li><time>10/07</time><button type="button">ver 4.17.0 秘書の愛称</button></li></ul></div></section>
                ${newsBox('')}
            </aside>
            <div class="main">
                <section class="box"><header><h2>島一覧</h2>
                    <div class="rank-tools"><label>並べ替え <select id="sort">${COLS.map(([k, l]) => `<option value="${k}" ${k === S.sort ? 'selected' : ''}>${l}</option>`).join('')}</select></label><input id="q" type="search" placeholder="島名・島主名でさがす" value="${h(S.q)}" aria-label="島名・島主名でさがす"></div></header>
                    ${list.length === 0 ? `<p class="empty">${S.mode === 'empty' ? 'まだ島がありません。最初の島を作ると、ここに並びます。' : '条件に合う島がありません。'}</p>` : `
                    <div class="rank-scroll"><table class="rank"><thead><tr><th>順位</th><th class="l">島と島主</th>${COLS.map(([k, l]) => `<th><button type="button" data-sort="${k}" class="${k === S.sort ? 'on' : ''}">${l}${k === S.sort ? ' ▼' : ''}</button></th>`).join('')}</tr></thead><tbody>
                    ${list.map((x) => `<tr class="${x.id === ME && S.mode === 'member' ? 'me' : ''}"><td class="no">${rankOf.get(x.id)}</td><td class="l isl ${x.state}"><button type="button" data-open="${x.id}">${h(x.name)}</button>${badges(x)}<small>${h(x.owner)}${x.secretary ? ` ＋ ${h(x.secretary)}` : ''}</small></td>${COLS.map(([k, , u]) => `<td class="${k === S.sort ? 'on' : ''}">${k === 'farm' || k === 'factory' || k === 'mine' ? (x[k] ? `${n(x[k] / 1000)}千${u}` : '—') : `${n(x[k])}${k === 'turns' ? '' : u}`}</td>`).join('')}</tr>`).join('')}
                    </tbody></table></div>
                    <div class="cards">${list.map((x) => `<div class="card ${x.id === ME && S.mode === 'member' ? 'me' : ''}"><span class="no">${rankOf.get(x.id)}</span><span class="isl ${x.state}"><button type="button" data-open="${x.id}">${h(x.name)}</button>${badges(x)}<small>${h(x.owner)}${x.secretary ? ` ＋ ${h(x.secretary)}` : ''}</small></span>
                        <span class="key">${n(x[S.sort])}<small>${col[1]}（${col[2]}）</small></span>
                        <span class="more">${COLS.filter(([k]) => k !== S.sort && ['pop', 'area', 'money', 'food'].includes(k)).map(([k, l, u]) => `<span><b>${l}</b>${n(x[k])}${u}</span>`).join('')}</span></div>`).join('')}</div>`}
                </section>
                ${newsBox('news-m')}
            </div></div>`;
    }
    document.addEventListener('click', (e) => {
        const b = e.target.closest('button'); if (!b) return;
        if (b.dataset.sort) { S.sort = b.dataset.sort; render(); }
        else if (b.dataset.news) { S.news = b.dataset.news; render(); }
        else if (b.dataset.open) { const x = ISLANDS.find((i) => i.id === +b.dataset.open); b.textContent = `${x.name}（観光画面へ。試作では開きません）`; }
    });
    document.addEventListener('change', (e) => { if (e.target.id === 'sort') { S.sort = e.target.value; render(); } });
    document.addEventListener('input', (e) => { if (e.target.id === 'q') { S.q = e.target.value; const pos = e.target.selectionStart; render(); const q = $('q'); q.focus(); q.setSelectionRange(pos, pos); } });
    $('opt-state').onchange = (e) => { S.mode = e.target.value; render(); };
    $('opt-phone').onchange = (e) => document.body.classList.toggle('phone', e.target.checked);
    $('opt-theme').onchange = (e) => { if (e.target.value) document.documentElement.dataset.theme = e.target.value; else delete document.documentElement.dataset.theme; };
    let left = 42 * 60 + 18;
    setInterval(() => { const c = $('count'); if (!c) return; left = Math.max(0, left - 1); c.textContent = `${Math.floor(left / 3600)}:${String(Math.floor(left / 60) % 60).padStart(2, '0')}:${String(left % 60).padStart(2, '0')}`; }, 1000);
    render();
})();
