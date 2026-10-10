// 地底の残りの画面: 戦闘履歴、スキル・覚醒、戦法、魔石研磨、不動産、行商人、冒険日誌、回想、トロフィー棚。
// ug.js から状態と部品（ctx）を受け取って描く。技の名前・条件の種類・値段・文面は試作用の仮のもの。APIは呼ばない。
(function () {
    const box = (title, body, right = '') => `<section class="box pn"><h2>${title}${right ? `<span>${right}</span>` : ''}</h2>${body}</section>`;
    const st = {
        hist: [
            { id: 1, at: '10/10 12:41', place: '夕凪の帰港地', foe: 'オルカ・ハウンド', res: '勝利', rounds: 7, xp: 5200, g: 4200, drop: '夕凪の細身剣' },
            { id: 2, at: '10/10 12:38', place: '夕凪の帰港地', foe: 'アビサル・プリースト', res: '敗北', rounds: 12, xp: 0, g: 0, drop: '' },
            { id: 3, at: '10/10 12:35', place: '封印の地', foe: '黒竜バハムル・初級1', res: '時間切れ', rounds: 100, xp: 0, g: 0, drop: '' },
            { id: 4, at: '10/10 12:20', place: '夕凪の帰港地', foe: 'クリスタル・ドルフィン', res: '勝利', rounds: 5, xp: 4800, g: 3900, drop: '' },
        ], hfilter: 'all',
        tree: 'war', node: 'heavy', sp: 6, slots: ['slash', 'heavy', null, null],
        nodes: {
            war: [['slash', '斬撃', 0, null, 1, '敵1体に武力で攻撃する。', 0, 0], ['heavy', '渾身の一撃', 2, 'slash', 1, '敵1体に大きなダメージ。', 400, 2], ['combo', '連ね斬り', 3, 'slash', 0, '敵1体を3回斬る。', 300, 1], ['spirit', '闘志', 2, 'heavy', 0, '攻撃するたびに闘志がたまり、与えるダメージが上がる。', 0, 0], ['finisher', '断ち切り', 4, 'combo', 0, 'HPが少ない敵に特に効く一撃。', 600, 3]],
            guard: [['stance', '防御の構え', 0, null, 1, 'そのラウンドに受けるダメージを減らす。', 0, 0], ['taunt', '挑発', 2, 'stance', 0, '2ラウンドの間、敵の攻撃を引きつける。', 200, 3], ['ward', '守りの障壁', 3, 'stance', 0, '味方全員に障壁を張る。', 300, 3]],
            bless: [['bolt', 'ホーリーボルト', 0, null, 0, '敵1体に精神で攻撃する。', 350, 1], ['heal', '癒しの祈り', 2, 'bolt', 0, 'HPが一番減っている味方を回復する。', 450, 2], ['grace', '加護', 3, 'heal', 0, '回復するたびに加護がたまり、回復量が上がる。', 0, 0]],
        },
        line: '——まだ、終わらせない。', tech: 'burst',
        mode: 'custom', dirty: false,
        rules: [
            { c: [['selfhp', 30]], a: 'stance' }, { c: [['awake', 0]], a: 'awaken' }, { c: [['foehp', 50], ['mp', 400]], a: 'heavy' }, { c: [], a: 'slash' },
        ],
        polish: 3, shardG: 0,
        owned: { villa: true, mirror: false, trophy: true, vault: false, reso: false }, buy: null,
        topic: null,
        story: null,
    };
    const COND = { selfhp: ['自分のHPが', '%以下'], allyhp: ['味方の誰かのHPが', '%以下'], foehp: ['敵のHPが', '%以上'], mp: ['自分のMPが', '以上'], awake: ['覚醒ゲージが満タン', ''], always: ['いつでも', ''] };
    const ACT = { slash: '斬撃', heavy: '渾身の一撃', stance: '防御の構え', awaken: '覚醒技', normal: '通常攻撃' };
    const TREES = [['war', '戦技'], ['guard', '護身'], ['bless', '祝福']];
    const PROP = [['villa', '別荘', 500000, '冒険日誌と回想を読めるようになる。'], ['mirror', '鏡', 300000, 'ホームの秘書の姿を、通常と覚醒で選べるようになる。'], ['trophy', 'トロフィー棚', 400000, '初めて倒した強敵の記念品を飾れる。'], ['vault', '保管庫の拡張', 800000, '保管できる数 500 → 600'], ['reso', '共鳴結晶の枠の拡張', 600000, '共鳴結晶を持てる数 50 → 100']];
    const TOPICS = [['このあたりで一番売れる物は？', '共鳴結晶だね。攻撃技が2つ付いたやつは、出したそばから無くなるよ。'], ['地上の話を聞かせて', '上は上で忙しそうだね。台風が来ると、こっちまで水が染みてくるんだ。'], ['おすすめの行き先は？', '装備Lvが足りないなら、無理せず一つ前の場所を回るのが早いよ。']];
    const STORIES = [['出会い', '案内人と初めて会った日のこと。', true, [['案内人', '……おや。こんな深いところに、人が来るとはね。'], ['ノエル', '道に迷いました。上へ戻る道を教えてください。'], ['案内人', '戻る道なら教えるさ。でも、せっかく来たんだ。少し見ていくかい。']]], ['名前', '案内人に名前を付けた日のこと。', true, [['案内人', '名前？　そんなもの、長いこと呼ばれてないよ。'], ['ノエル', 'では、わたしが付けます。']]], ['黒竜の影', '試練3をクリアすると読めます。', false, []]];
    const nodeOf = (k) => Object.values(st.nodes).flat().find((x) => x[0] === k);

    const V = {
        history: ({ h, n }) => {
            const list = st.hist.filter((x) => st.hfilter === 'all' || x.res !== '勝利');
            return box('戦闘履歴', `<div class="pick" style="margin-bottom:6px">${[['all', 'すべて'], ['lost', '勝てなかった戦い']].map(([k, l]) => `<button type="button" data-hfilter="${k}" class="${st.hfilter === k ? 'on' : ''}">${l}</button>`).join('')}</div>
                ${list.map((x) => `<button type="button" class="hrow" data-dummyx="戦闘ログを開きます（戦闘ログは別の試作にあります）"><span class="res r-${x.res === '勝利' ? 'win' : x.res === '敗北' ? 'lose' : 'draw'}">${x.res}</span><span class="nm">${h(x.foe)}<small>${x.place}　${x.at}　${x.rounds}ラウンド</small></span><span class="rw">${x.xp ? `経験値 +${n(x.xp)}　${n(x.g)}G${x.drop ? `<small>${h(x.drop)}</small>` : ''}` : '—'}</span></button>`).join('')}
                <p class="muted" style="margin-top:8px">行を押すと、その戦いのログを読めます。スキップで受け取った分は履歴に残りません。</p>`, '直近の20件');
        },
        skills: ({ h }) => {
            const nodes = st.nodes[st.tree], sel = nodeOf(st.node), pre = sel[3] ? nodeOf(sel[3]) : null, can = !sel[4] && (!pre || pre[4]) && st.sp >= sel[2];
            return box('スキル', `<div class="pick" style="margin-bottom:8px">${TREES.map(([k, l]) => `<button type="button" data-tree="${k}" class="${st.tree === k ? 'on' : ''}">${l}</button>`).join('')}</div>
                <div class="two"><div class="tree">${nodes.map((x) => `<button type="button" class="node ${x[4] ? 'got' : ''} ${st.node === x[0] ? 'on' : ''}" data-node="${x[0]}" style="margin-left:${x[3] ? (nodeOf(x[3])[3] ? 44 : 22) : 0}px"><b>${x[1]}</b><span>${x[4] ? '取得済み' : `${x[2]} SP`}${st.slots.includes(x[0]) ? '　装備中' : ''}</span></button>`).join('')}</div>
                <div class="detail"><h3>${sel[1]}</h3><p style="font-size:13px">${sel[5]}</p><dl class="kv"><dt>取るのに必要</dt><dd>${sel[2]} SP</dd><dt>先に必要な技</dt><dd>${pre ? `${pre[1]}（${pre[4] ? '取得済み' : '未取得'}）` : 'なし'}</dd>${sel[6] ? `<dt>MP / 再使用</dt><dd>${sel[6]} / ${sel[7]}ラウンド後</dd>` : ''}</dl>
                    ${sel[4] ? (sel[6] || sel[0] === 'slash' || sel[0] === 'stance' ? `<button type="button" data-equip="${sel[0]}">${st.slots.includes(sel[0]) ? '技の枠から外す' : '技の枠に入れる'}</button>` : '<p class="muted">持っているだけで効く技です。</p>') : `<button type="button" class="primary" data-learn="${sel[0]}" ${can ? '' : 'disabled'}>${sel[2]} SPで取る</button>${can ? '' : `<p class="warn">${pre && !pre[4] ? `先に「${pre[1]}」が必要です。` : 'SPが足りません。'}</p>`}`}</div></div>
                <p class="muted" style="margin-top:8px">技の枠: ${st.slots.map((k, i) => `${i + 1}. ${k ? nodeOf(k)[1] : '空き'}`).join('　')}</p>`, `残り <b style="color:var(--gold);font:400 18px var(--f-game)">${st.sp}</b> SP`)
                + box('覚醒', `<div class="filters"><div class="frow"><b>覚醒の台詞</b><input type="text" id="aw-line" maxlength="40" value="${h(st.line)}" style="flex:1;min-width:160px;padding:5px 8px;border:1px solid var(--line-2);border-radius:4px;background:var(--win-2);color:var(--ink);font:inherit"></div>
                    <div class="frow"><b>覚醒技</b><span class="pick">${[['burst', '解放の一撃'], ['guardian', '不落の誓い'], ['drain', '吸命の刃']].map(([k, l]) => `<button type="button" data-tech="${k}" class="${st.tech === k ? 'on' : ''}">${l}</button>`).join('')}</span></div></div>
                    <p class="muted" style="margin-top:6px">${{ burst: '覚醒した直後に、敵1体へ大きなダメージを与えます。', guardian: '覚醒してから2ラウンド、受けるダメージを大きく減らします。', drain: '覚醒してから3ラウンド、与えたダメージの一部でHPを回復します。' }[st.tech]}　台詞は戦闘ログの覚醒の行に出ます。</p>`);
        },
        ai: ({ h }) => {
            const edit = st.mode === 'custom';
            return box('戦法', `<div class="pick" style="margin-bottom:6px"><button type="button" data-aimode="default" class="${edit ? '' : 'on'}">おまかせ</button><button type="button" data-aimode="custom" class="${edit ? 'on' : ''}">自分で決める</button></div>
                <p class="muted">上から順に見て、条件に合い、使える最初の行動を取ります。どれも使えないときは通常攻撃です。${edit ? '' : 'いまは「おまかせ」で、下の並びをそのまま使います。'}</p>
                <ol class="rules">${st.rules.map((r, i) => `<li class="rule"><span class="no">${i + 1}</span><div class="if">
                    ${r.c.length ? r.c.map(([k, v], ci) => `<span class="cond">${ci ? '<i>かつ</i>' : '<i>もし</i>'}<select data-rc="${i}:${ci}" ${edit ? '' : 'disabled'}>${Object.entries(COND).map(([ck, cl]) => `<option value="${ck}" ${ck === k ? 'selected' : ''}>${cl[0]}${cl[1] ? '…' : ''}</option>`).join('')}</select>${COND[k][1] ? `<input type="number" inputmode="numeric" value="${v}" data-rv="${i}:${ci}" ${edit ? '' : 'disabled'}>${COND[k][1]}` : ''}${edit ? `<button type="button" class="x" data-rcdel="${i}:${ci}" aria-label="この条件を消す">×</button>` : ''}</span>`).join('') : '<span class="cond"><i>いつでも</i></span>'}
                    ${edit && r.c.length < 3 ? `<button type="button" class="chip" data-rcadd="${i}">＋条件</button>` : ''}
                    <span class="then"><i>なら</i><select data-ra="${i}" ${edit ? '' : 'disabled'}>${Object.entries(ACT).map(([k, l]) => `<option value="${k}" ${k === r.a ? 'selected' : ''}>${l}</option>`).join('')}</select><i>を使う</i></span></div>
                    ${edit ? `<span class="mv"><button type="button" data-rmv="${i}:-1" ${i ? '' : 'disabled'} aria-label="上へ">▲</button><button type="button" data-rmv="${i}:1" ${i < st.rules.length - 1 ? '' : 'disabled'} aria-label="下へ">▼</button><button type="button" data-rdel="${i}" aria-label="この行を消す">×</button></span>` : ''}</li>`).join('')}</ol>
                ${edit ? `<p style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:8px"><button type="button" data-radd ${st.rules.length >= 8 ? 'disabled' : ''}>＋行を足す</button><span class="muted" style="margin-right:auto">${st.rules.length} / 8行</span><span class="${st.dirty ? 'warn' : 'muted'}">${st.dirty ? 'まだ保存していません' : '保存済み'}</span><button type="button" class="primary" data-rsave ${st.dirty ? '' : 'disabled'}>保存する</button></p>` : ''}
                <p class="muted" style="margin-top:6px">変更は、次に始まる戦闘から効きます。</p>`);
        },
        polish: ({ n, S }) => {
            const cost = 120000 * (st.polish + 1), max = st.polish >= 5;
            return box('魔石研磨', `<p class="muted">装備している共鳴結晶をみがいて、効果を上げます。</p><div class="two" style="margin-top:8px">
                <div class="slot"><small>いま</small><b class="r-relic">黒竜の共鳴結晶 ＋${st.polish}</b><span>攻撃技強化 +${18 + st.polish * 2}、攻撃技強化 +${15 + st.polish * 2}、単体攻撃強化 +${12 + st.polish * 2}</span></div>
                ${max ? '<div class="slot empty"><small>みがいたあと</small><b>これ以上はみがけません</b><span>＋5まで</span></div>' : `<div class="slot"><small>みがいたあと</small><b class="r-relic">黒竜の共鳴結晶 ＋${st.polish + 1}</b><span>攻撃技強化 +${20 + st.polish * 2}、攻撃技強化 +${17 + st.polish * 2}、単体攻撃強化 +${14 + st.polish * 2}</span></div>`}</div>
                ${max ? '' : `<p style="display:flex;gap:10px;align-items:center;margin-top:10px;flex-wrap:wrap"><span>費用 <b style="font:400 18px var(--f-game);color:var(--gold)">${n(cost)}G</b></span><button type="button" class="primary" data-polish="${cost}" ${S.g < cost ? 'disabled' : ''}>みがく</button>${S.g < cost ? '<span class="warn">手持ちが足りません。</span>' : ''}<button type="button" data-dummyx="みがく共鳴結晶を選び直します（試作では開きません）">別の結晶にする</button></p>`}`, `＋${st.polish} / ＋5`);
        },
        property: ({ n, S }) => box('不動産', `<p class="muted">${st.owned.villa ? '別荘の住み心地はどうだい。足りない物があれば言っておくれ。' : 'いい物件があるよ。まずは別荘からどうだい。'}</p>
            ${PROP.filter(([k]) => k === 'villa' || st.owned.villa).map(([k, name, price, text]) => `<div class="prop"><div><b>${name}</b><small>${text}</small></div><span class="price">${n(price)}G</span>${st.owned[k] ? '<span class="tag">購入済み</span>' : st.buy === k ? `<span class="pick"><button type="button" data-buyno>やめる</button><button type="button" class="on" data-buyyes="${k}:${price}">${n(price)}Gで買う</button></span>` : `<button type="button" class="${S.g >= price ? 'primary' : ''}" data-buyask="${k}" ${S.g < price ? 'disabled' : ''}>${S.g >= price ? '買う' : '手持ち不足'}</button>`}</div>`).join('')}`),
        merchant: ({ h }) => box('行商人と話す', `<p style="font:15px/1.8 var(--f-game)">${st.topic === null ? 'やあ。今日は何が聞きたい？' : h(TOPICS[st.topic][1])}</p><div class="pick" style="flex-wrap:wrap;gap:6px;margin-top:8px">${TOPICS.map(([q], i) => `<button type="button" data-topic="${i}" class="${st.topic === i ? 'on' : ''}" style="margin:0;border-radius:14px">${h(q)}</button>`).join('')}</div>`),
        diary: ({ n }) => st.owned.villa ? box('冒険日誌', `<dl class="kv" style="grid-template-columns:auto 1fr auto 1fr"><dt>戦った回数</dt><dd>${n(2841)}</dd><dt>勝った回数</dt><dd>${n(2590)}</dd><dt>与えたダメージ</dt><dd>${n(48210337)}</dd><dt>受けたダメージ</dt><dd>${n(9120044)}</dd><dt>回復した量</dt><dd>${n(3310820)}</dd><dt>覚醒した回数</dt><dd>${n(612)}</dd></dl>
            <h3 style="font:400 14px var(--f-game);color:var(--ink-2);margin:10px 0 4px">クリアした試練</h3><p style="font-size:13px">試練1　試練2　<span class="muted">試練3（未クリア）</span></p><p class="muted" style="margin-top:8px">スキップで受け取った分は数えていません。</p>`) : needVilla(),
        recollect: () => st.owned.villa ? box('回想', STORIES.map(([t, d, open], i) => `<button type="button" class="hrow" ${open ? `data-story="${i}"` : 'disabled'}><span class="res r-${open ? 'win' : 'draw'}">${open ? '読む' : '未解禁'}</span><span class="nm">${open ? t : '？？？'}<small>${d}</small></span><span></span></button>`).join('') + '<p class="muted" style="margin-top:8px">読むときは、メニューを消して全面で表示します。</p>') : needVilla(),
        trophy: () => st.owned.trophy ? box('トロフィー棚', [['再生肉塊の核', '浅い洞窟の主を初めて倒した', '2026/08/30 21:14'], ['黒晶の番人の欠片', '黒晶洞の主を初めて倒した', '2026/09/12 08:02'], ['王国の執行官の印章', '輝きの王国の主を初めて倒した', '2026/09/28 23:40']].map(([t, d, at]) => `<div class="prop"><div><b>${t}</b><small>${d}</small></div><span class="muted">${at}</span></div>`).join('') + '<p class="muted" style="margin-top:8px">強敵を初めて倒すと、ここに記念品が増えます。</p>') : box('トロフィー棚', '<p class="muted">交流場の不動産でトロフィー棚を買うと、記念品を飾れます。</p><p style="margin-top:8px"><button type="button" data-jump="exchange:property">不動産へ</button></p>'),
    };
    const needVilla = () => box('別荘', '<p class="muted">交流場の不動産で別荘を買うと、冒険日誌と回想を読めます。</p><p style="margin-top:8px"><button type="button" data-jump="exchange:property">不動産へ</button></p>');

    V.click = (d, ctx, b) => {
        const S = ctx.S, pair = (v) => v.split(':').map(Number);
        if (d.hfilter) st.hfilter = d.hfilter;
        else if (d.dummyx) { b.textContent = d.dummyx; b.disabled = true; return false; }
        else if (d.tree) { st.tree = d.tree; st.node = st.nodes[d.tree][0][0]; }
        else if (d.node) st.node = d.node;
        else if (d.learn) { const x = nodeOf(d.learn); x[4] = 1; st.sp -= x[2]; }
        else if (d.equip) { const i = st.slots.indexOf(d.equip); if (i >= 0) st.slots[i] = null; else { const free = st.slots.indexOf(null); if (free >= 0) st.slots[free] = d.equip; } }
        else if (d.tech) st.tech = d.tech;
        else if (d.aimode) st.mode = d.aimode;
        else if (d.rcadd) { st.rules[+d.rcadd].c.push(['selfhp', 50]); st.dirty = true; }
        else if (d.rcdel) { const [i, c] = pair(d.rcdel); st.rules[i].c.splice(c, 1); st.dirty = true; }
        else if (d.rmv) { const [i, dir] = pair(d.rmv); [st.rules[i], st.rules[i + dir]] = [st.rules[i + dir], st.rules[i]]; st.dirty = true; }
        else if (d.rdel) { st.rules.splice(+d.rdel, 1); st.dirty = true; }
        else if ('radd' in d) { st.rules.push({ c: [], a: 'normal' }); st.dirty = true; }
        else if ('rsave' in d) st.dirty = false;
        else if (d.polish) { S.g -= +d.polish; st.polish++; }
        else if (d.buyask) st.buy = d.buyask;
        else if ('buyno' in d) st.buy = null;
        else if (d.buyyes) { const [k, price] = d.buyyes.split(':'); S.g -= +price; st.owned[k] = true; st.buy = null; }
        else if (d.topic) st.topic = +d.topic;
        else if (d.story) { S.script = STORIES[+d.story][3]; S.talk = 0; ctx.goto = 'event'; }
        else return false;
        return true;
    };
    V.change = (t) => {
        const pair = (v) => v.split(':').map(Number);
        if (t.dataset.rc) { const [i, c] = pair(t.dataset.rc); st.rules[i].c[c] = [t.value, t.value === 'mp' ? 400 : 50]; if (t.value === 'always') st.rules[i].c.splice(c, 1); st.dirty = true; }
        else if (t.dataset.rv) { const [i, c] = pair(t.dataset.rv); st.rules[i].c[c][1] = +t.value || 0; st.dirty = true; }
        else if (t.dataset.ra) { st.rules[+t.dataset.ra].a = t.value; st.dirty = true; }
        else if (t.id === 'aw-line') st.line = t.value;
        else return false;
        return true;
    };
    window.UG_EXTRA = V;
})();
