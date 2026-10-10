// 地上開発画面の試作。3案とも同じデータと同じ部品を使い、配置と操作の流れだけを変えている。
// 計画の追加・並べ替え・取消はこのページ内の配列を書き換えるだけで、APIは呼ばない。
(function () {
    const D = window.PROTO_DATA, T = window.PROTO_TILES;
    const $ = (id) => document.getElementById(id);
    const app = $('app');
    const h = (s) => String(s).replace(/[&<>"]/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ch]));
    const n = (v) => Number(v).toLocaleString('ja-JP');
    const signed = (v) => (v > 0 ? '+' : '') + n(v);
    const def = (key) => D.COMMANDS.find((c) => c.key === key);
    const cellAt = (x, y) => D.cells.find((c) => c.x === x && c.y === y);
    const TILE = 32;
    const px = (c) => ({ x: c.x * TILE + ((c.y & 1) === 0 ? TILE / 2 : 0), y: c.y * TILE });

    let seq = 1;
    const S = {
        variant: 'a', sel: null, queue: D.QUEUE.map((q) => ({ id: seq++, ...q })), planSel: null,
        group: 'land', zoom: 1, pan: { x: 0, y: 0 }, logFilter: 'all', mode: 'normal', allCmds: false,
    };
    const narrow = () => $('stage').clientWidth <= 760;
    const money = () => (S.mode === 'poor' ? 60 : D.NATION.money);

    // ---------- 島の状況 ----------
    function renderHud() {
        const N = D.NATION, m = money();
        $('hud-title').textContent = `N${N.nation_number} ${N.name}`;
        $('hud-turn').textContent = `第${N.current_turn}ターン　次の更新 ${N.next_turn_at}`;
        const stat = (lv, label, value, unit, gauge, low) => `<div class="stat lv${lv}${low ? ' low' : ''}"><dt>${label}</dt><dd>${value}<small>${unit}</small></dd>${gauge == null ? '' : `<span class="gauge" aria-hidden="true"><i style="width:${Math.min(100, gauge * 100)}%"></i></span>`}</div>`;
        $('hud-stats').innerHTML = [
            stat(1, '資金', n(m), '億円', m / N.money_capacity, m < 100),
            stat(1, '食料', n(N.total_food_tons), 'トン', N.total_food_tons / N.food_capacity_tons),
            stat(1, '人口', n(N.total_population), '人'),
            stat(2, '面積', `${N.owned_land_cells}/${N.safe_land_cells}`, 'マス', N.owned_land_cells / N.safe_land_cells),
            stat(2, '輝石', n(N.paradox), 'Pd'),
            stat(3, '農場', n(N.farm_capacity_people / 1000), '千人'),
            stat(3, '工場', n(N.factory_capacity_people / 1000), '千人'),
            stat(3, '採掘場', n(N.mine_capacity_people / 1000), '千人'),
            stat(3, N.workforce.label, N.workforce.percent, '%'),
        ].join('');
        const warn = D.LOG.slice(0, 2).flatMap((g) => g.events).filter((e) => e.importance === 'warning').length;
        $('log-badge').textContent = S.mode === 'empty' || !warn ? '' : warn;
    }

    function renderIsland() {
        const N = D.NATION;
        $('island-body').innerHTML = `
            <div class="tbl-wrap"><table>
                <thead><tr><th>資源</th><th>生産</th><th>消費</th><th>次ターン</th><th>所持</th></tr></thead>
                <tbody>${N.forecast.map((r) => `<tr><th>${r.name}<small>（${r.unit}）</small></th><td>${n(r.production)}</td><td>${n(r.consumption)}</td><td class="${r.delta < 0 ? 'down' : 'up'}">${signed(r.delta)}</td><td>${n(r.holding)}</td></tr>`).join('')}</tbody>
            </table></div>
            <dl class="kv">
                <div><dt>島主</dt><dd>${h(N.owner_name)}</dd></div>
                <div><dt>${N.calendar.slice(0, 3)}</dt><dd>${N.calendar.slice(3)}</dd></div>
                <div><dt>資金の上限</dt><dd>${n(N.money_capacity)}億円</dd></div>
                <div><dt>食料の上限</dt><dd>${n(N.food_capacity_tons)}トン</dd></div>
                <div><dt>面積（安全な広さ）</dt><dd>${N.owned_land_cells}/${N.safe_land_cells}マス</dd></div>
                <div><dt>輝石</dt><dd>${N.paradox} Pd</dd></div>
                <div><dt>農場の規模</dt><dd>${n(N.farm_capacity_people)}人</dd></div>
                <div><dt>工場の規模</dt><dd>${n(N.factory_capacity_people)}人</dd></div>
                <div><dt>採掘場の規模</dt><dd>${n(N.mine_capacity_people)}人</dd></div>
                <div><dt>${N.workforce.label}</dt><dd>${N.workforce.percent}%</dd></div>
            </dl>`;
    }

    // ---------- 地図 ----------
    const cellEls = new Map();
    function buildMap() {
        const plane = $('map-plane');
        const origin = px(D.CAPITAL);
        for (const c of D.cells) {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'cell' + (c.owner_nation_id === D.OWN.id ? ' own' : c.owner_nation_id !== null ? ' other' : '');
            const p = px(c);
            b.style.left = `${p.x - origin.x}px`; b.style.top = `${p.y - origin.y}px`;
            b.style.backgroundImage = `url(${T.tile(c)})`;
            b.setAttribute('aria-label', `${c.display_name} (${c.x}, ${c.y})`);
            if (c.monster) b.innerHTML += `<img src="${T.sprite('monster')}" alt="">`;
            if (c.ship) b.innerHTML += `<img src="${T.sprite('ship')}" alt="">`;
            b.cell = c;
            plane.appendChild(b);
            cellEls.set(`${c.x},${c.y}`, b);
        }
    }
    function applyTransform() { $('map-plane').style.transform = `translate(${S.pan.x}px, ${S.pan.y}px) scale(${S.zoom})`; }
    function centerOn(c) {
        const v = $('map-view'), origin = px(D.CAPITAL), p = px(c);
        // シートや浮きパネルに隠れない位置へ寄せる
        let cx = v.clientWidth / 2, cy = v.clientHeight / 2;
        if (S.variant === 'b' && !narrow()) cy -= 50;
        if (narrow() && S.variant === 'b' && S.sel) cy = v.clientHeight * 0.32;
        if (narrow() && S.variant === 'c' && S.sel) cy = v.clientHeight * 0.2;
        S.pan = { x: cx - (p.x - origin.x + TILE / 2) * S.zoom, y: cy - (p.y - origin.y + TILE / 2) * S.zoom };
        applyTransform();
    }
    function markPlan() {
        document.querySelectorAll('.cell .n').forEach((e) => e.remove());
        S.queue.forEach((q, i) => {
            const el = q.target_x == null ? null : cellEls.get(`${q.target_x},${q.target_y}`);
            if (!el || el.querySelector('.n')) return;
            const s = document.createElement('span'); s.className = 'n'; s.textContent = i + 1; el.appendChild(s);
        });
    }
    function select(c, center) {
        if (S.sel) cellEls.get(`${S.sel.x},${S.sel.y}`)?.classList.remove('sel');
        S.sel = c; S.group = null;
        app.dataset.sel = c ? '1' : '0';
        if (c) cellEls.get(`${c.x},${c.y}`).classList.add('sel');
        renderInspect();
        if (c && center) centerOn(c);
    }
    function initMapInput() {
        const v = $('map-view');
        let ptr = null;
        v.addEventListener('pointerdown', (e) => { if (e.button === 0) ptr = { id: e.pointerId, x: e.clientX, y: e.clientY, sx: e.clientX, sy: e.clientY, moved: false, target: e.target.closest('.cell') }; });
        v.addEventListener('pointermove', (e) => {
            if (!ptr || ptr.id !== e.pointerId) return;
            if (!ptr.moved && Math.hypot(e.clientX - ptr.sx, e.clientY - ptr.sy) < 6) return;
            if (!ptr.moved) { ptr.moved = true; v.classList.add('drag'); v.setPointerCapture(e.pointerId); }
            S.pan.x += e.clientX - ptr.x; S.pan.y += e.clientY - ptr.y; ptr.x = e.clientX; ptr.y = e.clientY;
            applyTransform();
        });
        const end = (e) => {
            if (!ptr || ptr.id !== e.pointerId) return;
            const p = ptr; ptr = null; v.classList.remove('drag');
            if (!p.moved && p.target && e.type === 'pointerup') {
                select(p.target.cell, false);
                if (S.variant === 'a' && narrow()) setTab('inspect');
                if (narrow() && S.variant !== 'a') centerOn(p.target.cell);
            }
        };
        v.addEventListener('pointerup', end); v.addEventListener('pointercancel', end);
        // マウスで載せたマスの詳細を、地図の上に小さく出す（指の操作では出さない）
        const hv = $('hover');
        v.addEventListener('pointermove', (e) => {
            if (e.pointerType !== 'mouse' || ptr?.moved) { hv.hidden = true; return; }
            const el = e.target.closest('.cell'); if (!el) { hv.hidden = true; return; }
            const c = el.cell, r = v.getBoundingClientRect();
            if (hv.cell !== c) {
                hv.cell = c;
                const queued = S.queue.map((q, i) => ({ q, i })).filter(({ q }) => q.target_x === c.x && q.target_y === c.y);
                hv.innerHTML = `<b>${h(c.display_name)}</b> <small class="num">(${c.x}, ${c.y})</small>
                    <p>${cellFacts(c).map(([k, val]) => `<span><i>${k}</i>${h(val)}</span>`).join('')}</p>
                    ${queued.length ? `<p class="hv-q">予約 ${queued.map(({ q, i }) => `${i + 1}番 ${def(q.command_key).name}`).join('、')}</p>` : ''}`;
            }
            hv.hidden = false;
            const x = e.clientX - r.left + 14, y = e.clientY - r.top + 14;
            hv.style.left = `${Math.min(x, r.width - hv.offsetWidth - 6)}px`;
            hv.style.top = `${y + hv.offsetHeight > r.height ? e.clientY - r.top - hv.offsetHeight - 10 : y}px`;
        });
        v.addEventListener('pointerleave', () => { hv.hidden = true; hv.cell = null; });
        v.addEventListener('pointerdown', () => { hv.hidden = true; });
        v.addEventListener('keydown', (e) => {
            if (e.key !== 'Enter' && e.key !== ' ') return;
            const c = document.activeElement?.cell; if (c) { e.preventDefault(); select(c, false); }
        });
        v.addEventListener('wheel', (e) => { e.preventDefault(); zoomBy(e.deltaY < 0 ? 0.25 : -0.25); }, { passive: false });
        $('map-home').onclick = () => { S.zoom = 1; centerOn(D.CAPITAL); };
        $('map-in').onclick = () => zoomBy(0.25); $('map-out').onclick = () => zoomBy(-0.25);
        $('map-territory').onchange = (e) => { app.dataset.territory = e.target.checked ? 'on' : 'off'; };
        $('map-plan').onchange = (e) => { app.dataset.planMarks = e.target.checked ? 'on' : 'off'; };
        new ResizeObserver(() => centerOn(narrow() && S.sel ? S.sel : D.CAPITAL)).observe(v);
    }
    function zoomBy(d) {
        const v = $('map-view'), old = S.zoom;
        S.zoom = Math.min(2, Math.max(0.5, +(S.zoom + d).toFixed(2)));
        const cx = v.clientWidth / 2, cy = v.clientHeight / 2;
        S.pan = { x: cx - (cx - S.pan.x) * S.zoom / old, y: cy - (cy - S.pan.y) * S.zoom / old };
        applyTransform();
    }

    // ---------- マス情報とコマンド ----------
    const cellFacts = (c) => [
        ['地形', c.terrain_name], ...(c.facility ? [['施設', c.facility_name]] : []),
        ['所有', c.owner_name ? `${c.owner_name}（N${c.owner_nation_number}）` : 'なし'],
        ...c.details.map((d) => [d.label, d.formatted]),
        ...(c.monster ? [['怪獣', `${c.monster.name} HP${c.monster.current_hp}/${c.monster.spawned_max_hp}`]] : []),
        ...(c.ship ? [['船', `${c.ship.name} HP${c.ship.current_hp}/${c.ship.max_hp}`]] : []),
    ];
    // 「全部出す」のときは、いまのマスでは意味のないコマンドも出す。埋め立て→埋め立て→地ならしのように、先の地形を見越して仕込むため
    function listed(cell) {
        const now = applicable(cell);
        if (!S.allCmds || !cell) return now.map((c) => ({ c, now: true }));
        const ok = new Set(now.map((c) => c.key));
        return D.COMMANDS.filter((c) => ok.has(c.key) || (c.target_type === 'cell' && !c.danger)).map((c) => ({ c, now: ok.has(c.key) }));
    }
    function applicable(cell) {
        return D.COMMANDS.filter((c) => {
            if (c.target_type === 'nation') return true;
            if (!cell || !c.terrains.includes(cell.terrain)) return false;
            const own = cell.owner_nation_id === D.OWN.id;
            if (c.own && !own) return false;
            if (c.unowned && cell.owner_nation_id !== null) return false;
            if (c.empty && cell.facility) return false;
            if (c.facility && cell.facility && cell.facility !== c.facility) return false;
            if (c.group === 'military' && !c.own && own) return false;
            return true;
        });
    }
    const mark = (c) => `<i class="mk${c.consumes_turn ? '' : ' free'}" title="${c.consumes_turn ? 'ターンを使う' : 'ターンを使わない'}"></i>`;
    function cmdButton({ c, now }) {
        const lack = c.cost_money - money(), noPd = c.cost_paradox > D.NATION.paradox, full = S.queue.length >= D.QUEUE_LIMIT;
        const cost = lack > 0 ? `あと${n(lack)}億円` : `${n(c.cost_money)}億円${c.cost_paradox ? `<em>${c.cost_paradox}Pd</em>` : ''}`;
        return `<button type="button" class="cmd${lack > 0 || noPd ? ' short' : ''}${now ? '' : ' later'}" data-cmd="${c.key}" ${lack > 0 || noPd || full ? 'disabled' : ''} ${now ? '' : 'title="いまの地形では使えません。前の計画で地形が変わる前提で入れます"'}>${mark(c)}<span class="nm">${h(c.name)}${c.suffix ? `<small>${h(c.suffix)}</small>` : ''}${now ? '' : '<small class="ltr">先読み</small>'}</span><span class="cost">${cost}</span></button>`;
    }
    function renderInspect() {
        const c = S.sel, info = $('cell-info');
        if (!c) {
            info.innerHTML = '<p class="empty">地図のマスを選ぶと、ここにそのマスの情報と使えるコマンドが出ます。</p>';
        } else {
            const facts = cellFacts(c);
            const queued = S.queue.map((q, i) => ({ q, i })).filter(({ q }) => q.target_x === c.x && q.target_y === c.y);
            info.innerHTML = `<h3><img src="${T.tile(c)}" alt="">${h(c.display_name)}<small class="num">(${c.x}, ${c.y})</small></h3>
                <p class="facts">${facts.map(([k, v]) => `<span><b>${k}</b>${h(v)}</span>`).join('')}</p>
                ${queued.length ? `<p class="facts"><span><b>予約</b>${queued.map(({ q, i }) => `${i + 1}番 ${def(q.command_key).name}`).join('、')}</span></p>` : ''}`;
        }
        const list = listed(c);
        const groups = D.GROUPS.filter((g) => list.some((x) => x.c.group === g.key));
        if (!groups.some((g) => g.key === S.group)) S.group = groups[0]?.key;
        const legend = '<span class="legend"><span><i class="mk"></i> ターンを使う</span><span><i class="mk free"></i> 使わない</span></span>';
        const pi = S.queue.findIndex((q) => q.id === S.planSel);
        // 入れる位置。計画欄が見えないスマホ幅でも分かるよう、コマンドの上に小さく出す
        const at = `<p class="insert-at">${pi >= 0 ? `<span>入れる位置 <b class="num">${pi + 2}番</b>（${pi + 1}番 ${h(def(S.queue[pi].command_key).name)}の後ろ）</span><button type="button" class="quiet" data-unpick>末尾に戻す</button>` : `<span>入れる位置 <b class="num">${S.queue.length + 1}番</b>（末尾）</span>`}</p>`;
        const allBtn = c ? `<label class="all-cmds" title="いまの地形では使えないコマンドも出します"><input type="checkbox" data-allcmds ${S.allCmds ? 'checked' : ''}> 全部出す</label>` : '';
        const full = S.queue.length >= D.QUEUE_LIMIT ? `<p class="empty">計画が${D.QUEUE_LIMIT}件で一杯です。どれかを取り消すと追加できます。</p>` : '';
        let html;
        if (S.variant === 'a') {
            // 案A: 分類ごとに全部並べる。切り替えなしで見渡せる
            html = `<div class="cmd-head"><strong>使えるコマンド</strong>${allBtn}${legend}</div>${at}${full}` + groups.map((g) => `<div class="cmd-head"><span class="num">${g.name}</span></div><div class="cmds">${list.filter((x) => x.c.group === g.key).map(cmdButton).join('')}</div>`).join('');
        } else {
            html = `<div class="cmd-head"><div class="seg">${groups.map((g) => `<button type="button" data-group="${g.key}" class="${g.key === S.group ? 'on' : ''}">${g.name}</button>`).join('')}</div>${allBtn}${legend}</div>${at}${full}
                <div class="cmds">${list.filter((x) => x.c.group === S.group).map(cmdButton).join('')}</div>`;
        }
        $('cmd-area').innerHTML = html;
    }

    // ---------- 開発計画 ----------
    function timeline() {
        let turn = D.NATION.current_turn + 1, cur = null;
        const groups = [];
        S.queue.forEach((q, i) => {
            const d = def(q.command_key), reps = d.repeats ? q.quantity : 1;
            cur ??= { from: turn, to: turn, items: [] };
            cur.items.push({ q, d, pos: i + 1 });
            if (d.consumes_turn) { cur.to = turn + reps - 1; turn += reps; groups.push(cur); cur = null; }
        });
        if (cur) { groups.push(cur); turn += 1; }
        return { groups, next: turn };
    }
    const qtyText = (q, d) => (d.quantity_semantics === 'ordinary' ? ` ×${q.quantity}` : d.quantity_semantics === 'selector' ? `（${d.options[q.quantity - 1]}）` : '');
    const whereText = (q, d) => (d.target_type === 'nation' ? (q.nation ?? '島全体') : `(${q.target_x}, ${q.target_y})`);
    function renderPlan() {
        const body = $('plan-body');
        $('plan-count').textContent = `${S.queue.length}/${D.QUEUE_LIMIT}件`;
        if (S.mode === 'loading') { body.innerHTML = '<p class="empty">計画を読み込み中…</p>'; return; }
        const { groups, next } = timeline();
        const auto = `<div class="tg auto"><div class="tg-h"><span>第${next}ターンごろから</span></div><div class="pr" style="cursor:default"><span class="pr-n">—</span>${'<i class="mk free"></i>'}<span class="pr-name">資金繰り（自動）</span><span class="pr-at">+10億円</span></div></div>`;
        body.innerHTML = (groups.length ? '' : '<p class="empty">計画は空です。地図でマスを選び、コマンドを入れてください。</p>') + groups.map((g) => `
            <div class="tg"><div class="tg-h"><span>第${g.from}${g.to > g.from ? `〜${g.to}` : ''}ターンごろ</span></div>
            ${g.items.map(({ q, d, pos }) => `<button type="button" class="pr${S.planSel === q.id ? ' on' : ''}" data-plan="${q.id}"><span class="pr-n">${pos}</span>${mark(d)}<span class="pr-name">${h(d.name)}${d.suffix ? h(d.suffix) : ''}${qtyText(q, d)}</span><span class="pr-at">${whereText(q, d)}</span></button>`).join('')}</div>`).join('') + auto + (groups.length ? '<p class="plan-note">ターンの数字は目安です。実行できなかった計画があると、後ろの計画が前に詰まります。</p>' : '');
        const tools = $('plan-tools'), i = S.queue.findIndex((q) => q.id === S.planSel);
        tools.hidden = i < 0;
        if (i >= 0) {
            const q = S.queue[i], d = def(q.command_key), fwd = S.variant === 'b' ? ['◀ 前へ', '後ろへ ▶'] : ['▲ 上へ', '▼ 下へ'];
            tools.innerHTML = `<b>${i + 1}番 ${h(d.name)}</b>
                <button type="button" data-act="up" ${i === 0 ? 'disabled' : ''}>${fwd[0]}</button>
                <button type="button" data-act="down" ${i === S.queue.length - 1 ? 'disabled' : ''}>${fwd[1]}</button>
                ${d.quantity_semantics === 'ordinary' ? '<button type="button" data-act="qty">数量</button>' : ''}
                <button type="button" data-act="del" class="danger">取消</button>`;
        }
        markPlan();
        renderTabs();
    }
    function planAction(act) {
        const i = S.queue.findIndex((q) => q.id === S.planSel); if (i < 0) return;
        const q = S.queue[i];
        if (act === 'up' || act === 'down') { const j = act === 'up' ? i - 1 : i + 1; [S.queue[i], S.queue[j]] = [S.queue[j], S.queue[i]]; }
        if (act === 'del') { S.queue.splice(i, 1); S.planSel = null; toast(`${i + 1}番を取り消しました。`); }
        if (act === 'qty') return openEntry(def(q.command_key), q);
        renderPlan(); renderInspect();
    }

    // ---------- コマンド入力 ----------
    let entry = null;
    function openEntry(d, editing) {
        if (!editing && d.quantity_semantics === 'unused' && d.target_type === 'cell' && !d.danger) return addToPlan(d, 1, null);
        entry = { d, editing, qty: editing?.quantity ?? 1 };
        $('entry-title').innerHTML = `${mark(d)} ${h(d.name)}${d.suffix ? h(d.suffix) : ''}`;
        drawEntry();
        $('entry').hidden = false;
        $('entry-body').querySelector('input, select, button')?.focus();
    }
    function drawEntry() {
        const { d, editing, qty } = entry, c = S.sel;
        const at = S.planSel != null && !editing ? S.queue.findIndex((q) => q.id === S.planSel) + 2 : S.queue.length + 1;
        const where = d.target_type === 'nation'
            ? (d.key.endsWith('_aid') || d.key === 'monster_dispatch' ? '<label>相手の島 <select id="entry-nation"><option>向かいの島（N3）</option><option>南の島（N5）</option><option>みどり島（N11）</option></select></label>' : '<p class="where">対象: 島全体</p>')
            : `<p class="where">対象: ${editing ? `(${editing.target_x}, ${editing.target_y})` : `${h(c.display_name)} (${c.x}, ${c.y})`}</p>`;
        let input = '';
        if (d.quantity_semantics === 'ordinary') {
            input = `<div class="qty"><button type="button" data-step="-1" aria-label="減らす">−</button><input id="entry-qty" type="number" inputmode="numeric" min="1" max="99" value="${qty}" aria-label="数量"><button type="button" data-step="1" aria-label="増やす">＋</button><span>${d.unit ?? (d.repeats ? '回' : '')}</span></div>
                <div class="presets">${[1, 5, 10, 25, 50, 99].map((v) => `<button type="button" data-preset="${v}">${v}</button>`).join('')}</div>`;
        } else if (d.quantity_semantics === 'selector') {
            input = `<label>種類 <select id="entry-kind">${d.options.map((o, i) => `<option value="${i + 1}" ${i + 1 === qty ? 'selected' : ''}>${o}</option>`).join('')}</select></label>`;
        }
        const times = d.quantity_semantics === 'ordinary' && !d.unit ? qty : 1, total = d.cost_money * times;
        const turns = d.consumes_turn ? (d.repeats ? `${qty}ターンかかる` : '1ターン使う') : 'ターンを使わない';
        $('entry-body').innerHTML = `${where}${input}
            <p class="total"><span>費用 <b class="num">${n(total)}億円</b>${d.cost_paradox ? ` ＋ <b class="num">${d.cost_paradox * times}Pd</b>` : ''}</span><span>${mark(d)} ${turns}</span>${editing ? '' : `<span>入れる位置 <b class="num">${at}番</b></span>`}</p>
            ${total > money() ? `<p class="warn">いまの資金では${n(total - money())}億円足りません。実行までに貯まれば実行されます。</p>` : ''}
            ${d.danger ? '<p class="warn">このマスの領土を手放します。元には戻せません。</p>' : ''}`;
    }
    function addToPlan(d, qty, nation) {
        const at = S.planSel != null ? S.queue.findIndex((q) => q.id === S.planSel) + 1 : S.queue.length;
        const item = { id: seq++, command_key: d.key, quantity: qty, target_x: d.target_type === 'cell' ? S.sel.x : null, target_y: d.target_type === 'cell' ? S.sel.y : null, nation };
        S.queue.splice(at, 0, item);
        S.planSel = item.id;
        renderPlan(); renderInspect();
        toast(`計画の${at + 1}番に入れました。実行されるのはターン更新のときです。`);
    }

    // ---------- 島ログ ----------
    function renderLog() {
        const body = $('log-body');
        if (S.mode === 'loading') { body.innerHTML = '<p class="empty">ログを読み込み中…</p>'; return; }
        if (S.mode === 'error') { body.innerHTML = '<p class="empty">ログを読み込めませんでした。通信状態を確認して、もう一度お試しください。<br><button type="button" data-retry>再読み込み</button></p>'; return; }
        if (S.mode === 'empty') { body.innerHTML = '<p class="empty">まだログはありません。最初のターン更新のあとに、ここへ結果が並びます。</p>'; return; }
        const d = (label, v, unit) => `<span><b>${label}</b><span class="${v < 0 ? 'down' : 'up'}">${v < 0 ? '▼' : '▲'}${n(Math.abs(v))}${unit}</span></span>`;
        body.innerHTML = D.LOG.map((g) => {
            const evs = g.events.filter((e) => S.logFilter === 'all' || e.importance !== 'info' || e.summary);
            return `<section class="lg"><h4>第${g.target_turn}ターン</h4>${evs.map((e) => e.summary
                ? `<div class="ev"><i>＝</i><div><p class="sum">${d('資金', e.summary.money, '億円')}${d('人口', e.summary.population, '人')}${d('食料', e.summary.food, 'トン')}</p>
                    <details><summary>内訳</summary><ul>${e.contributions.map(([k, v, u]) => `<li><span>${k}</span><span class="num ${v < 0 ? 'down' : 'up'}">${signed(v)}${u}</span></li>`).join('')}</ul></details></div></div>`
                : `<div class="ev ${e.importance}"><i>${e.importance === 'warning' ? '！' : e.importance === 'notable' ? '◆' : '・'}</i><div>${e.confidential ? '<span class="secret">秘密</span>' : ''}${h(e.message)}</div></div>`).join('')}</section>`;
        }).join('') + '<p class="empty"><button type="button" disabled>新しい12ターン</button> <button type="button">過去の12ターン</button></p>';
    }

    // ---------- 切り替え ----------
    function renderTabs() {
        const tabs = S.variant === 'a'
            ? [['inspect', 'マス・コマンド'], ['plan', `計画 ${S.queue.length}`], ['log', 'ログ']]
            : [['map', '<span class="t-pc">マス</span><span class="t-phone">地図</span>'], ['plan', `計画 ${S.queue.length}`], ['log', 'ログ'], ['island', '島']];
        // 案Aのスマホ: 右端のつまみでパネルを上まで伸ばし、地図と島の状況を隠して入力に専念できる
        const grow = S.variant === 'a' ? `<button type="button" class="grow" data-grow aria-pressed="${app.dataset.grow === 'on'}" aria-label="${app.dataset.grow === 'on' ? '地図を出す' : '上まで広げる'}">${app.dataset.grow === 'on' ? '︾' : '︽'}</button>` : '';
        $('tabs').innerHTML = tabs.map(([k, label]) => `<button type="button" data-tab="${k}" class="${app.dataset.tab === k ? 'on' : ''}">${label}</button>`).join('') + grow;
    }
    function setTab(t) { app.dataset.tab = t; renderTabs(); if (t === 'map') requestAnimationFrame(() => centerOn(S.sel ?? D.CAPITAL)); }
    function setVariant(v) {
        S.variant = v; app.dataset.variant = v; app.dataset.grow = 'off'; app.dataset.tab = v === 'c' ? 'map' : 'inspect';
        app.dataset.log = 'closed'; app.dataset.island = 'closed';
        document.querySelectorAll('#protobar [data-variant]').forEach((b) => b.classList.toggle('on', b.dataset.variant === v));
        try { history.replaceState(null, '', `#${v}`); } catch { /* 埋め込み先では書けないことがある */ }
        renderInspect(); renderPlan();
        requestAnimationFrame(() => centerOn(S.sel ?? D.CAPITAL));
    }
    function setMode(m) {
        S.mode = m;
        if (m === 'empty') { S.queue = []; S.planSel = null; }
        const st = $('map-status');
        st.hidden = !(m === 'loading' || m === 'error');
        st.innerHTML = m === 'loading' ? '地図を読み込み中…' : '地図を読み込めませんでした。<br>通信状態を確認して、もう一度お試しください。<br><button type="button" data-retry>再読み込み</button>';
        renderHud(); renderInspect(); renderPlan(); renderLog();
    }
    let toastTimer;
    function toast(text) { const t = $('toast'); t.textContent = text; t.hidden = false; clearTimeout(toastTimer); toastTimer = setTimeout(() => { t.hidden = true; }, 2600); }

    // ---------- イベント ----------
    document.addEventListener('click', (e) => {
        const b = e.target.closest('button'); if (!b) { $('menu').hidden = true; return; }
        if (b.id !== 'plan-bulk') $('menu').hidden = true;
        if (b.dataset.variant) setVariant(b.dataset.variant);
        else if ('grow' in b.dataset) { app.dataset.grow = app.dataset.grow === 'on' ? 'off' : 'on'; renderTabs(); if (app.dataset.grow === 'off') requestAnimationFrame(() => centerOn(S.sel ?? D.CAPITAL)); }
        else if (b.dataset.tab) setTab(b.dataset.tab);
        else if (b.dataset.group) { S.group = b.dataset.group; renderInspect(); }
        else if (b.dataset.cmd) openEntry(def(b.dataset.cmd));
        else if (b.dataset.plan) {
            const id = +b.dataset.plan; S.planSel = S.planSel === id ? null : id;
            const q = S.queue.find((x) => x.id === id);
            if (S.planSel && q.target_x != null) {
                // 広い画面では対象マスも選ぶ。狭い画面ではシートが計画を隠すので、地図を寄せるだけにする
                if (!narrow()) select(cellAt(q.target_x, q.target_y), true);
                else if (S.variant === 'b') { select(null); centerOn(cellAt(q.target_x, q.target_y)); }
            }
            renderPlan(); renderInspect();
        }
        else if (b.dataset.act) planAction(b.dataset.act);
        else if (b.dataset.filter) { S.logFilter = b.dataset.filter; document.querySelectorAll('#log-filter button').forEach((x) => x.classList.toggle('on', x === b)); renderLog(); }
        else if (b.dataset.step) { entry.qty = Math.min(99, Math.max(1, entry.qty + +b.dataset.step)); drawEntry(); }
        else if (b.dataset.preset) { entry.qty = +b.dataset.preset; drawEntry(); }
        else if (b.dataset.bulk) bulk(b.dataset.bulk);
        else if ('unpick' in b.dataset) { S.planSel = null; renderPlan(); renderInspect(); }
        else if ('retry' in b.dataset) { $('opt-state').value = 'normal'; setMode('normal'); toast('読み込み直しました。'); }
        else if (b.id === 'btn-island') app.dataset.island = app.dataset.island === 'open' ? 'closed' : 'open';
        else if (b.id === 'island-close') app.dataset.island = 'closed';
        else if (b.id === 'btn-log') app.dataset.log = app.dataset.log === 'open' ? 'closed' : 'open';
        else if (b.id === 'log-close') app.dataset.log = 'closed';
        else if (b.id === 'inspect-close') select(null);
        else if (b.id === 'entry-cancel') $('entry').hidden = true;
        else if (b.id === 'plan-bulk') {
            const m = $('menu'), r = b.getBoundingClientRect(), a = app.getBoundingClientRect();
            m.innerHTML = '<button type="button" data-bulk="level">荒地と焦土を全て地ならし</button><button type="button" data-bulk="clear">荒地と焦土を全て整地</button><button type="button" data-bulk="reclaim">浅瀬を全て埋め立て</button><button type="button" data-bulk="cut" class="danger">選んだ行から下を全て取消</button>';
            m.hidden = false;
            m.style.right = `${Math.max(8, a.right - r.right)}px`;
            if (r.top - a.top > a.height / 2) { m.style.top = 'auto'; m.style.bottom = `${a.bottom - r.top + 4}px`; } else { m.style.bottom = 'auto'; m.style.top = `${r.bottom - a.top + 4}px`; }
        }
    });
    function bulk(kind) {
        if (kind === 'cut') {
            const i = S.queue.findIndex((q) => q.id === S.planSel);
            if (i < 0) return toast('先に、取り消しを始める行を選んでください。');
            const cnt = S.queue.length - i; S.queue.splice(i); S.planSel = null; renderPlan(); renderInspect();
            return toast(`${cnt}件を取り消しました。`);
        }
        const key = { clear: 'land_clear', level: 'land_level', reclaim: 'reclaim' }[kind];
        const terr = kind === 'reclaim' ? ['shallow'] : ['wasteland', 'scorched'];
        const targets = D.cells.filter((c) => c.owner_nation_id === D.OWN.id && terr.includes(c.terrain) && !c.facility).slice(0, D.QUEUE_LIMIT - S.queue.length);
        // 行を選んでいればその後ろへ、選んでいなければ末尾へ入れる（立て直しで先頭付近に差し込む使い方を想定）
        const pi = S.queue.findIndex((q) => q.id === S.planSel), at = pi >= 0 ? pi + 1 : S.queue.length;
        S.queue.splice(at, 0, ...targets.map((c) => ({ id: seq++, command_key: key, quantity: 1, target_x: c.x, target_y: c.y })));
        renderPlan(); renderInspect();
        toast(targets.length ? `${def(key).name}を${targets.length}件、計画の${pi >= 0 ? `${at + 1}番から` : '末尾に'}入れました。` : '対象のマスがありません。');
    }
    $('entry-body').addEventListener('input', (e) => {
        if (e.target.id === 'entry-qty') { const v = Math.min(99, Math.max(1, Math.floor(+e.target.value || 1))); entry.qty = v; }
        if (e.target.id === 'entry-kind') entry.qty = +e.target.value;
    });
    $('entry-body').addEventListener('change', (e) => { if (e.target.id === 'entry-qty') drawEntry(); });
    $('entry-form').addEventListener('submit', (e) => {
        e.preventDefault();
        const { d, editing, qty } = entry;
        $('entry').hidden = true;
        if (editing) { editing.quantity = qty; renderPlan(); renderInspect(); return toast('数量を変えました。'); }
        addToPlan(d, qty, $('entry-nation')?.value ?? null);
    });
    document.addEventListener('change', (e) => { if (e.target.matches('[data-allcmds]')) { S.allCmds = e.target.checked; try { localStorage.setItem('proto-surface-allcmds', S.allCmds ? '1' : ''); } catch { /* 保存できなくても動く */ } renderInspect(); } });
    // 案Aの3列: 左右の列の幅を境目のつまみで変える。ダブルクリックで元に戻す
    const COLS = { l: [220, 300, 460], r: [240, 320, 480] };
    function setCol(side, w) {
        const [min, base, max] = COLS[side], v = Math.round(Math.min(max, Math.max(min, w ?? base)));
        app.style.setProperty(`--col-${side}`, `${v}px`);
        try { localStorage.setItem(`proto-surface-col-${side}`, String(v)); } catch { /* 保存できなくても動く */ }
    }
    document.querySelectorAll('.col-grip').forEach((g) => {
        const side = g.dataset.side;
        g.addEventListener('pointerdown', (e) => {
            e.preventDefault(); g.setPointerCapture(e.pointerId); g.classList.add('drag');
            const start = e.clientX, w0 = parseFloat(getComputedStyle(app).getPropertyValue(`--col-${side}`)) || COLS[side][1];
            const move = (ev) => setCol(side, w0 + (side === 'l' ? ev.clientX - start : start - ev.clientX));
            const up = () => { g.classList.remove('drag'); g.removeEventListener('pointermove', move); g.removeEventListener('pointerup', up); centerOn(S.sel ?? D.CAPITAL); };
            g.addEventListener('pointermove', move); g.addEventListener('pointerup', up);
        });
        g.addEventListener('dblclick', () => { setCol(side, null); centerOn(S.sel ?? D.CAPITAL); });
        g.addEventListener('keydown', (e) => { if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') { e.preventDefault(); const cur = parseFloat(getComputedStyle(app).getPropertyValue(`--col-${side}`)) || COLS[side][1]; setCol(side, cur + ((e.key === 'ArrowRight') === (side === 'l') ? 20 : -20)); } });
    });
    try {
        ['l', 'r'].forEach((s) => { const v = localStorage.getItem(`proto-surface-col-${s}`); if (v) setCol(s, +v); });
        S.allCmds = localStorage.getItem('proto-surface-allcmds') === '1';
    } catch { /* 保存できなくても動く */ }
    $('opt-state').onchange = (e) => setMode(e.target.value);
    $('opt-phone').onchange = (e) => { document.body.classList.toggle('phone', e.target.checked); requestAnimationFrame(() => { renderTabs(); centerOn(S.sel ?? D.CAPITAL); }); };
    $('opt-theme').onchange = (e) => { if (e.target.value) document.documentElement.dataset.theme = e.target.value; else delete document.documentElement.dataset.theme; };
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') { $('entry').hidden = true; $('menu').hidden = true; } });

    // ---------- 起動 ----------
    app.dataset.territory = 'on'; app.dataset.planMarks = 'on'; app.dataset.sel = '0';
    buildMap(); initMapInput(); renderHud(); renderIsland(); renderLog();
    const start = (location.hash || '').replace('#', '');
    setVariant(['a', 'b', 'c'].includes(start) ? start : 'a');
    select(cellAt(1, 2), false);
})();
