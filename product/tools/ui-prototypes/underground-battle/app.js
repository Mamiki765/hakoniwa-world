// 地底の戦闘ログの試作。1対1も多人数も同じ部品で描き、「読む」（スクロールに戦況が追従）と
// 「再生」（1行動ずつ進む）を切り替えられる。APIは呼ばない。
(function () {
    const B = window.PROTO_BATTLE;
    const $ = (id) => document.getElementById(id);
    const app = $('app'), log = $('log');
    const h = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
    const n = (v) => Number(v).toLocaleString('ja-JP');

    // ---- 仮の顔アイコン（16×16のドット絵） ----
    const faceCache = new Map();
    function face(a) {
        const key = `${a.team}:${a.look}`;
        if (faceCache.has(key)) return faceCache.get(key);
        const cv = document.createElement('canvas'); cv.width = cv.height = 16;
        const g = cv.getContext('2d'), p = (c, x, y, w, hh) => { g.fillStyle = c; g.fillRect(x, y, w, hh); };
        if (a.team === 'player') {
            const [bg, hair, cloth] = [['#243a66', '#e6c35a', '#c8553d'], ['#243a66', '#e6c35a', '#c8553d'], ['#4a2a2a', '#b33b3b', '#8f9aa5'], ['#27484a', '#dfe8f0', '#3f6fb0'], ['#4a3f1f', '#8b5e34', '#3d7a3a']][a.look] ?? [];
            p(bg, 0, 0, 16, 16); p(hair, 4, 2, 8, 4); p(hair, 3, 4, 2, 6); p(hair, 11, 4, 2, 6);
            p('#f1cfa8', 5, 5, 6, 6); p('#26303a', 6, 7, 1, 2); p('#26303a', 9, 7, 1, 2); p('#c97b6a', 7, 10, 2, 1);
            p(cloth, 3, 12, 10, 4); p('#f1cfa8', 7, 11, 2, 1);
        } else if (a.look === 1) { p('#16261a', 0, 0, 16, 16); p('#5fae4a', 3, 7, 10, 7); p('#7fd066', 4, 5, 8, 3); p('#16261a', 5, 8, 2, 2); p('#16261a', 9, 8, 2, 2); p('#b9f0a0', 5, 6, 2, 1); }
        else if (a.look === 2) { p('#1d1528', 0, 0, 16, 16); p('#5a3f8a', 3, 2, 10, 13); p('#1d1528', 5, 5, 6, 5); p('#f0655a', 6, 7, 1, 1); p('#f0655a', 9, 7, 1, 1); p('#7a5cb0', 3, 2, 10, 2); }
        else if (a.look === 3) { p('#2a1418', 0, 0, 16, 16); p('#b85a6a', 2, 6, 12, 8); p('#d98794', 4, 4, 7, 3); p('#7a2f3c', 5, 9, 3, 2); p('#7a2f3c', 10, 7, 2, 2); p('#f4e9c9', 6, 12, 1, 1); p('#f4e9c9', 9, 12, 1, 1); }
        else if (a.look === 4) { p('#201a10', 0, 0, 16, 16); p('#8a6a3a', 4, 5, 8, 7); p('#b8935a', 5, 4, 6, 2); p('#201a10', 6, 7, 1, 1); p('#201a10', 9, 7, 1, 1); [3, 6, 9].forEach((y) => { p('#8a6a3a', 1, y, 3, 1); p('#8a6a3a', 12, y, 3, 1); }); }
        else { p('#150b10', 0, 0, 16, 16); p('#2b2530', 3, 4, 10, 9); p('#3d3644', 4, 3, 8, 2); p('#d9d2c0', 2, 1, 2, 4); p('#d9d2c0', 12, 1, 2, 4); p('#f0655a', 5, 7, 2, 1); p('#f0655a', 9, 7, 2, 1); p('#150b10', 5, 11, 6, 1); p('#d9d2c0', 6, 11, 1, 1); p('#d9d2c0', 9, 11, 1, 1); }
        faceCache.set(key, cv.toDataURL());
        return faceCache.get(key);
    }

    let bt, by, mode = 'read', playing = false, speed = 1, timer = null, shown = 0, seq = [];

    // ---- 行のまとまりを作る ----
    function groups(round) {
        const out = [];
        for (const a of round.actions) {
            const last = out[out.length - 1];
            if (a.type === 'damage' && last?.type === 'damage' && last.actor_id === a.actor_id && last.label === a.label) { last.hits.push(a); last.state = a.state; last.important ||= a.important; continue; }
            if (a.type === 'defeat') { out.push({ ...a, hits: [] }); continue; }
            out.push({ ...a, hits: a.type === 'damage' ? [a] : [] });
        }
        return out;
    }
    function rowHtml(g) {
        const actor = by[g.actor_id], img = `<img class="face" src="${face(actor)}" alt="">`;
        const cost = g.mp_cost ? `<small>MP −${n(g.mp_cost)}</small>` : '';
        const open = (cls) => `<div class="row ${cls}" data-team="${actor.team}">`;
        if (g.type === 'defeat') return `${open('big fall')}${img}<div class="tx">${actor.team === 'enemy' ? `<b>${h(actor.name)}</b>を倒した。` : `<b>${h(actor.name)}</b>は倒れた。`}</div><span></span></div>`;
        if (g.type === 'awakening') return `${open('big awk')}${img}<div class="tx">${h(actor.name)}、覚醒！</div><span></span></div>`;
        if (g.type === 'warning') return `${open('big warn')}${img}<div class="tx"><span class="sk">${h(g.label)}</span>　${h(g.text)}</div><span></span></div>`;
        if (g.type === 'status_damage') return `${open('minor')}${img}<div class="tx"><b>${h(actor.name)}</b> <span class="to">${h(g.label)}のダメージ</span></div><span class="val ${actor.team === 'player' ? 'dmg-a' : 'dmg-e'}">−${n(g.amount)}</span></div>`;
        if (g.type === 'status_applied') return `${open('minor')}${img}<div class="tx"><b>${h(actor.name)}</b> <span class="sk">${h(g.label)}</span> <span class="to">${h(g.text)}</span>${cost}</div><span></span></div>`;
        if (g.type === 'recovery') return `${open('minor')}${img}<div class="tx"><b>${h(actor.name)}</b> <span class="sk">${h(g.label)}</span> <span class="to">→ ${h(by[g.target_id].name)}</span>${cost}</div><span class="val heal">+${n(g.amount)}</span></div>`;
        if (g.type === 'barrier') return `${open('minor')}${img}<div class="tx"><b>${h(actor.name)}</b> <span class="sk">${h(g.label)}</span> <span class="to">→ 味方全体</span>${cost}</div><span class="val wardv">障壁 +${n(g.amount)}</span></div>`;
        // ダメージ
        const hits = g.hits, targets = [...new Set(hits.map((x) => x.target_id))], total = hits.reduce((s, x) => s + x.amount, 0);
        const crit = hits.some((x) => x.critical), allMiss = hits.every((x) => x.evaded), absorbed = hits.reduce((s, x) => s + (x.barrier_absorbed ?? 0), 0);
        const statuses = [...new Set(hits.map((x) => x.status).filter(Boolean))];
        const to = targets.length > 1 ? `${actor.team === 'enemy' ? '味方' : '敵'}全体` : h(by[targets[0]].name);
        const tags = `${crit ? '<span class="tag crit">会心</span>' : ''}${allMiss ? '<span class="tag miss">回避</span>' : ''}${statuses.map((s) => `<span class="tag st">${h(s)}</span>`).join('')}`;
        const side = by[targets[0]].team === 'enemy' ? 'dmg-e' : 'dmg-a';
        const sub = hits.length > 1 ? `<div class="sub">${hits.map((x) => `<span>${targets.length > 1 ? h(by[x.target_id].name) : `${x.hit ?? ''}撃目`}<b>${x.evaded ? '回避' : `−${n(x.amount)}`}${x.critical ? ' 会心' : ''}</b></span>`).join('')}</div>` : '';
        return `${open((g.important ? 'big' : 'minor'))}${img}<div class="tx">${tags}<b>${h(actor.name)}</b> <span class="sk">${h(g.label)}</span> <span class="to">→ ${to}${hits.length > 1 && targets.length === 1 ? ` ×${hits.length}` : ''}</span>${cost}</div>
            <span class="val ${side}">${allMiss ? '—' : total ? `−${n(total)}` : '0'}${absorbed ? `<small>障壁 ${n(absorbed)}</small>` : ''}</span>${sub}</div>`;
    }

    // ---- 戦況カード ----
    function unitHtml(a) {
        return `<article class="unit win" data-team="${a.team}" data-id="${a.id}">
            <img class="face" src="${face(a)}" alt="">
            <div class="nm"><span>${h(a.name)}</span><em hidden>覚醒</em></div>
            <div class="bars">
                <div class="bar hp"><i>HP</i><span class="track"><span class="fill"></span><span class="ward"></span></span><b></b></div>
                ${a.team === 'player' ? '<div class="bar mp"><i>MP</i><span class="track"><span class="fill"></span></span><b></b></div><div class="bar aw"><i>覚醒</i><span class="track"><span class="fill"></span></span><b></b></div>' : ''}
            </div>
            <div class="chips"></div></article>`;
    }
    function applyState(state, label, actingId) {
        $('round-now').textContent = label;
        for (const a of bt.actors) {
            const el = document.querySelector(`.unit[data-id="${a.id}"]`), s = state[a.id];
            const ratio = s.hp / s.max_hp, fill = el.querySelector('.hp .fill');
            fill.style.width = `${ratio * 100}%`; fill.className = `fill${ratio <= 0.25 ? ' crit' : ratio <= 0.5 ? ' low' : ''}`;
            el.querySelector('.hp .ward').style.width = `${Math.min(100, s.barrier / s.max_hp * 100)}%`;
            el.querySelector('.hp b').textContent = `${n(s.hp)}/${n(s.max_hp)}`;
            if (a.team === 'player') {
                el.querySelector('.mp .fill').style.width = `${s.mp / a.mp * 100}%`; el.querySelector('.mp b').textContent = n(s.mp);
                el.querySelector('.aw .fill').style.width = `${s.awakened ? 100 : s.gauge / s.gauge_max * 100}%`;
                el.querySelector('.aw b').textContent = s.awakened ? '覚醒中' : s.gauge >= s.gauge_max ? '発動可' : `${Math.round(s.gauge / s.gauge_max * 100)}%`;
                el.querySelector('.nm em').hidden = !s.awakened;
            }
            el.classList.toggle('down', s.hp <= 0); el.classList.toggle('awake', !!s.awakened); el.classList.toggle('act', a.id === actingId);
            el.querySelector('.chips').innerHTML = `${s.barrier > 0 ? `<span class="chip ward">障壁 ${n(s.barrier)}</span>` : ''}${s.taunt > 0 ? '<span class="chip">挑発</span>' : ''}${s.statuses.map((x) => `<span class="chip">${h(x.label)} ${x.remaining}</span>`).join('')}`;
        }
    }

    // ---- 組み立て ----
    function load(key) {
        stop();
        bt = B.run(key); by = Object.fromEntries(bt.actors.map((a) => [a.id, a]));
        document.querySelectorAll('#opt-scenario button').forEach((b) => b.classList.toggle('on', b.dataset.scenario === key));
        $('head-place').textContent = `${bt.context}　${bt.place}`;
        $('head-name').textContent = bt.encounter;
        for (const team of ['enemy', 'player']) {
            const list = bt.actors.filter((a) => a.team === team), el = $(`team-${team}`);
            el.dataset.size = list.length === 1 ? 'l' : list.length > 2 ? 's' : 'm';
            el.style.setProperty('--n', list.length);
            el.innerHTML = list.map(unitHtml).join('');
        }
        const allies = bt.actors.filter((a) => a.team === 'player');
        seq = [];
        let html = `<div class="open win"><b>${h(bt.encounter)}</b>が現れた。${allies.length > 1 ? `${h(allies[0].name)}たち${allies.length}人` : h(allies[0].name)}は戦闘を開始した。</div>`;
        for (const r of bt.rounds) {
            const gs = groups(r), sum = { ad: 0, ed: 0, heal: 0 };
            r.actions.forEach((a) => { if (a.type === 'damage' || a.type === 'status_damage') { const victim = by[a.type === 'damage' ? a.target_id : a.actor_id]; if (victim.team === 'enemy') sum.ad += a.amount; else sum.ed += a.amount; } if (a.type === 'recovery' && by[a.actor_id].team === 'player') sum.heal += a.amount; });
            const minor = gs.filter((g) => !(g.important || ['defeat', 'awakening', 'warning'].includes(g.type))).length;
            html += `<section class="rd" data-round="${r.round}"><header class="rd-h"><h2>第${r.round}ラウンド</h2><span class="a">与えた <b>${n(sum.ad)}</b></span><span class="e">受けた <b>${n(sum.ed)}</b></span>${sum.heal ? `<span>回復 <b>${n(sum.heal)}</b></span>` : ''}</header>
                ${gs.map((g) => { seq.push(g); return rowHtml(g).replace('<div class="row ', `<div data-i="${seq.length - 1}" class="row `); }).join('')}
                ${minor ? `<button type="button" class="more" data-more>ほか${minor}行動を見る</button>` : ''}</section>`;
        }
        const rw = bt.rewards, top = (k) => Math.max(...bt.actors.map((a) => bt.stat[a.id][k]));
        const label = { victory: '勝利', defeat: '敗北', stalemate: '時間切れ' }[bt.result];
        html += `<section id="result" class="win"><h2 class="${bt.result}">${label}</h2>
            <p class="rewards"><span><b>ラウンド</b>${bt.rounds.length}</span>${bt.result === 'victory' ? `<span><b>経験値</b>+${n(rw.xp)}</span><span><b>G</b>+${n(rw.g)}</span>${rw.drops.map((d) => `<span><b>入手</b>${h(d)}</span>`).join('')}` : ''}</p>
            <div class="tbl-wrap"><table><thead><tr><th>名前</th><th>与えた</th><th>受けた</th><th>回復</th><th>残りHP</th></tr></thead><tbody>
            ${bt.actors.map((a) => { const s = bt.stat[a.id]; const c = (k) => `<td class="${s[k] > 0 && s[k] === top(k) ? 'top' : ''}">${n(s[k])}</td>`; return `<tr><td>${h(a.name)}</td>${c('dealt')}${c('taken')}${c('healed')}<td>${n(bt.final[a.id].hp)}</td></tr>`; }).join('')}
            </tbody></table></div>
            <div class="next"><button type="button" class="primary" data-dummy="同じ場所をもう一度探索します（試作では動きません）。">もう一度ここを探索する</button><button type="button" data-dummy="地底のホームへ戻ります（試作では動きません）。">地底へ戻る</button></div></section>`;
        log.innerHTML = html;
        $('ctrl').innerHTML = `<div class="play"><button type="button" data-c="first" aria-label="最初から">⏮</button><button type="button" data-c="toggle" class="primary" id="c-toggle">▶ 再生</button><button type="button" data-c="step">1行動</button><button type="button" data-c="round">次のR</button><button type="button" data-c="speed" id="c-speed">速さ 1×</button></div>
            <div class="rounds">${bt.rounds.map((r) => `<button type="button" data-goto="${r.round}">${r.round}</button>`).join('')}<button type="button" data-goto="end">結果</button></div>`;
        log.scrollTop = 0;
        setMode(mode);
    }
    const rows = () => [...log.querySelectorAll('.row')];
    const visible = (el) => el.offsetParent !== null;

    // ---- 読む: スクロール位置に戦況を合わせる ----
    function syncRead() {
        if (mode !== 'read') return;
        const line = log.getBoundingClientRect().top + log.clientHeight * 0.2;
        let cur = null;
        for (const el of rows()) { if (!visible(el)) continue; if (el.getBoundingClientRect().top <= line) cur = el; else break; }
        const atEnd = log.scrollTop + log.clientHeight >= log.scrollHeight - 4;
        rows().forEach((el) => el.classList.toggle('cur', el === cur && !atEnd));
        if (atEnd) applyState(bt.final, '決着', null);
        else if (!cur) applyState(bt.initial, '開始前', null);
        else { const g = seq[+cur.dataset.i]; applyState(g.state, `第${g.round}ラウンド`, g.type === 'defeat' ? null : g.actor_id); }
        markRound(atEnd ? 'end' : cur ? seq[+cur.dataset.i].round : 1);
    }
    function markRound(r) { document.querySelectorAll('#ctrl [data-goto]').forEach((b) => b.classList.toggle('on', b.dataset.goto === String(r))); }

    // ---- 再生 ----
    function reveal(i, pop) {
        const el = log.querySelector(`.row[data-i="${i}"]`), g = seq[i];
        el.hidden = false; el.closest('.rd').hidden = false;
        applyState(g.state, `第${g.round}ラウンド`, g.type === 'defeat' ? null : g.actor_id);
        markRound(g.round);
        if (pop && visible(el)) {
            const show = (id, text, color) => { const u = document.querySelector(`.unit[data-id="${id}"]`); const s = document.createElement('span'); s.className = 'pop'; s.textContent = text; s.style.color = `var(${color})`; u.appendChild(s); u.classList.remove('hit'); void u.offsetWidth; if (color !== '--heal') u.classList.add('hit'); setTimeout(() => s.remove(), 900); };
            const sums = {}; g.hits.forEach((x) => { sums[x.target_id] = (sums[x.target_id] ?? 0) + x.amount; });
            Object.entries(sums).forEach(([id, v]) => show(id, v ? `−${n(v)}` : '回避', by[id].team === 'enemy' ? '--ink' : '--enemy'));
            if (g.type === 'recovery') show(g.target_id, `+${n(g.amount)}`, '--heal');
            el.scrollIntoView({ block: 'nearest' });
        }
    }
    function next() {
        // 「要点」のときは隠れている行を飛ばしつつ、状態だけは反映する
        while (shown < seq.length) {
            const i = shown++; reveal(i, true);
            if (visible(log.querySelector(`.row[data-i="${i}"]`))) return true;
        }
        finish(); return false;
    }
    function finish() {
        stop(); shown = seq.length;
        rows().forEach((el) => { el.hidden = false; }); log.querySelectorAll('.rd, #result').forEach((el) => { el.hidden = false; });
        applyState(bt.final, '決着', null); markRound('end');
        log.scrollTop = log.scrollHeight;
    }
    function stop() { playing = false; clearInterval(timer); const b = $('c-toggle'); if (b) b.textContent = shown >= seq.length && seq.length ? '▶ もう一度' : '▶ 再生'; }
    function start() {
        if (shown >= seq.length) reset();
        playing = true; $('c-toggle').textContent = '⏸ 止める';
        clearInterval(timer); timer = setInterval(() => { if (!next()) stop(); }, 900 / speed);
    }
    function reset() {
        shown = 0; rows().forEach((el) => { el.hidden = true; el.classList.remove('cur'); });
        log.querySelectorAll('.rd, #result').forEach((el) => { el.hidden = true; });
        log.scrollTop = 0; applyState(bt.initial, '開始前', null); markRound(1);
    }
    function setMode(m) {
        mode = m; app.dataset.mode = m; stop();
        document.querySelectorAll('#opt-mode button').forEach((b) => b.classList.toggle('on', b.dataset.mode === m));
        if (m === 'play') reset();
        else { rows().forEach((el) => { el.hidden = false; }); log.querySelectorAll('.rd, #result').forEach((el) => { el.hidden = false; }); shown = seq.length; syncRead(); }
    }

    // ---- イベント ----
    log.addEventListener('scroll', () => requestAnimationFrame(syncRead));
    document.addEventListener('click', (e) => {
        const b = e.target.closest('button'); if (!b) return;
        if (b.dataset.scenario) load(b.dataset.scenario);
        else if (b.dataset.mode) setMode(b.dataset.mode);
        else if (b.dataset.detail) { app.dataset.detail = b.dataset.detail; document.querySelectorAll('#opt-detail button').forEach((x) => x.classList.toggle('on', x === b)); log.querySelectorAll('.rd.x').forEach((x) => x.classList.remove('x')); syncRead(); }
        else if ('more' in b.dataset) { b.closest('.rd').classList.add('x'); b.hidden = true; syncRead(); }
        else if (b.dataset.dummy) { b.textContent = b.dataset.dummy; b.disabled = true; }
        else if (b.dataset.goto) {
            if (mode === 'play') { stop(); const target = b.dataset.goto === 'end' ? seq.length : seq.findIndex((g) => g.round === +b.dataset.goto); if (b.dataset.goto === 'end') return finish(); reset(); while (shown < target) reveal(shown++, false); next(); return; }
            const el = b.dataset.goto === 'end' ? $('result') : log.querySelector(`.rd[data-round="${b.dataset.goto}"]`);
            log.scrollTop = b.dataset.goto === 'end' ? log.scrollHeight : el.offsetTop - log.offsetTop - 4;
        }
        else if (b.dataset.c === 'toggle') { if (playing) stop(); else start(); }
        else if (b.dataset.c === 'step') { stop(); next(); }
        else if (b.dataset.c === 'round') { stop(); const r = seq[Math.min(shown, seq.length - 1)].round; let moved = false; while (shown < seq.length && seq[shown].round === r) { reveal(shown++, false); moved = true; } if (!moved || shown >= seq.length) finish(); else next(); }
        else if (b.dataset.c === 'first') reset();
        else if (b.dataset.c === 'speed') { speed = speed === 1 ? 2 : speed === 2 ? 4 : 1; b.textContent = `速さ ${speed}×`; if (playing) start(); }
    });
    $('opt-phone').onchange = (e) => { document.body.classList.toggle('phone', e.target.checked); requestAnimationFrame(syncRead); };
    $('opt-theme').onchange = (e) => { if (e.target.value) document.documentElement.dataset.theme = e.target.value; else delete document.documentElement.dataset.theme; };
    document.querySelector('#opt-detail [data-detail="digest"]').classList.add('on');

    const startKey = (location.hash || '').replace('#', '');
    load(B.SCENARIOS[startKey] ? startKey : 'boss');
})();
