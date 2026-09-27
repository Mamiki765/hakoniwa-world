# 4.5.0：Trial 3 後の 140 SP Skill Tree

## 判断と測定条件

既存 skill の定義（威力・回復量・効果・cost・prerequisite）は変更していない。100 SP 以下の吸収斬り route も維持した。新 skill は各 tree に 2 方向を追加した。全て active、cost 24 SP、同 tree の先行投資 96 SP と既存 node の prerequisite を要求する。取得には最低 120 SP が必要で、同 tree の新 skill 2 つは最低でも 144 SP になる。全取得費用は戦技 198、護身 174、祝福 186 SP。active は常に 5 枠。

Lv300～700 の production 秘書について、Owner 許可の read-only 集計を 2026-09-27 に行った。戦技 3 人、護身 3 人、祝福 4 人の匿名サンプルで、Lv500 以上は祝福 2 人・護身 1 人・戦技 0 人と少ない。このため Lv650 個体の再現値とは扱わない。戦技と祝福の一人ずつを等重みで平均し、丸めた **全 role 共通 STP 方針**を使った。生命 21.0%、主攻撃能力 52.0%、技巧 12.5%、敏捷 14.5%。護身だけ技巧 12.5% を生命へ移し、生命 33.5%、武力 52.0%、敏捷 14.5% とした。戦技の主攻撃能力は武力、祝福は精神。growth path・装備 budget・Lv650・seed は各 role で揃えた。匿名 aggregate 以外は保存していない。

木人は非攻撃・十分な HP・100 round、seed 31000～31031。実戦は Trial 3 の最終ボス「殲滅王ギルガメス」単独戦、同 seed、Trial 3 ボス直前の装備。10 連戦は各段階の装備更新、戦間 HP 30% 回復、覚醒 gauge 引継ぎを行い、以前の Trial 3 計測と同じ seed 0～99 を使った。木人比と実戦 DPS は別に読む。

## 追加 skill

| Tree / skill | 効果 | Cost / gate / prerequisite | 位置と競合 |
|---|---|---|---|
| 戦技・淀み断ち `dispelling_cut` | 単体物理 150%、CT2。解除可能な強化を 1 個解除。固有 trait や解除不可状態は対象外 | 24 / 96 / `martial_armor_break` | 破甲撃から伸ばす対強化 route。一刀両断より低威力、出血 finish との同時取得不可 |
| 戦技・傷開き `wound_opening` | 単体物理 150%、CT3。出血を最大 3 stack 消費し、1 stack につき威力係数 +74% | 24 / 96 / `martial_severing_bleed` | 短剣乱舞・裂傷斬りとの連係。蓄積と消費の手番が必要 |
| 護身・護命陣 `emergency_cover` | 次の単体直接攻撃 1 回を肩代わりし、その攻撃を 85% 軽減。2 round 有効、CT6。solo は自分へ使用 | 24 / 96 / `guardianship_protective_oath` | 継続挑発と違う緊急保護。AoE は対象外 |
| 護身・砕壁衝 `barrier_crash` | 単体物理 155%、CT3。自分の障壁を全消費し、消費量の 70% を加算。加算源は最大 HP の 15% で上限 | 24 / 96 / `guardianship_counter_stance` | 鏡陣・活身法の障壁を攻撃へ変える。防御資源を失う |
| 祝福・調和の祈り `harmony_heal` | 生存味方全員に精神 65% + 各人最大 HP の 1.75% を回復、MP2800、CT3 | 24 / 96 / `miracle_regeneration` | 単体ヒールより一人あたり弱く、複数人の被害に対応 |
| 祝福・癒光 `healing_ray` | 単体魔法 180%。傷ついた味方 1 人に精神 50% + 最大 HP の 1% を回復、MP900、CT2 | 24 / 96 / `miracle_holy_lance` | 純攻撃のホーリーランス 280% と単体ヒールの両方より各成分が弱く、攻撃手番に小回復を載せる |

先行投資条件は既存の `invested_points_required` を使用し、Trial 3 clear 専用 lock は増やしていない。既存技の potency などの JSON 定義は HEAD と全件一致する。

## 140 SP の合法 route と active 5 枠

下記の node 名は `foundation-v1.json` の node key。列の順は active slot 1～5。取得済みでも枠外の技はその戦闘では使用できない。各 route は prerequisite と 96 SP gate を実際の catalog で検証した。

### 戦技

**対強化・継戦（120 SP、一般構成）**

- 取得：`martial_precision_cut`, `martial_dagger_flurry`, `martial_severing_bleed`, `martial_sweeping_cut`, `martial_armor_break`, `martial_iaido_cut`, `martial_draining_cut`, `martial_quick_stab`, `martial_dispelling_cut`
- 5 枠：短剣乱舞、裂傷斬り、居合斬り、吸収斬り、淀み断ち。
- AI：解除可能な敵強化を見たら淀み断ち、自己 HP 70% 以下で吸収斬り。通常は出血・居合で攻撃。Trial 3 ボスでは淀み断ちを 32 seed で 32 回使用。
- 選択：出血消費 finish と一刀両断を捨て、解除と継戦を取る。技巧の先行投資分として瞬突も取得するが、この 5 枠には入れない。

**出血 finish・継戦（120 SP）**

- 取得：`martial_precision_cut`, `martial_dagger_flurry`, `martial_severing_bleed`, `martial_sweeping_cut`, `martial_armor_break`, `martial_iaido_cut`, `martial_draining_cut`, `martial_quick_stab`, `martial_wound_opening`
- 5 枠：短剣乱舞、裂傷斬り、傷開き、居合斬り、吸収斬り。
- AI：敵に出血 2 stack 以上なら傷開き、自己 HP 70% 以下で吸収斬り、ほかは出血付与と居合。
- 選択：淀み断ちと一刀両断を捨て、高 HP の出血可能な敵へ寄せる。短剣乱舞→裂傷斬り→傷開きの取得と 5 枠装備が成立する。

### 護身

**緊急保護（132 SP、一般構成）**

- 取得：`guardianship_shield_bash`, `guardianship_rallying_cry`, `guardianship_counter_stance`, `guardianship_protective_oath`, `guardianship_fortress`, `guardianship_bulwark_strike`, `guardianship_retort`, `guardianship_renewing_guard`, `guardianship_emergency_cover`
- 5 枠：護命陣、不動結界、活身法、盾撃、闘志破砕。
- AI：大予告で護命陣、味方 HP 75% 以下で不動結界、自分 HP 55% 以下で活身法、それ以外は挑発付き攻撃。solo ボス 32 seed で護命陣 91 回、不動結界 200 回、活身法 105 回。
- 選択：鏡陣と不屈反攻は取得しても枠から外し、反撃火力より保護を優先。砕壁衝との同時取得はできない。

**障壁反撃（132 SP）**

- 取得：`guardianship_shield_bash`, `guardianship_rallying_cry`, `guardianship_counter_stance`, `guardianship_protective_oath`, `guardianship_fortress`, `guardianship_bulwark_strike`, `guardianship_retort`, `guardianship_renewing_guard`, `guardianship_barrier_crash`
- 5 枠：砕壁衝、鏡陣、活身法、盾撃、闘志破砕。
- AI：障壁が最大 HP の 1% 以上なら砕壁衝、強打予告・負傷時は鏡陣、自己回復後は攻撃。solo ボス 32 seed で砕壁衝 304 回、鏡陣 305 回。
- 選択：護命陣と不動結界を枠から外す。障壁を消費する分、次の被弾に備える資源は減る。

### 祝福

**全体回復（138 SP、一般構成）**

- 取得：`miracle_mending_prayer`, `miracle_regeneration`, `miracle_resurrection`, `miracle_heart_of_mercy`, `miracle_holy_bolt`, `miracle_holy_nova`, `miracle_holy_lance`, `miracle_harmony_heal`, `miracle_lucid_dream`, `miracle_crystal_cycle`
- 5 枠：ヒール、調和の祈り、夢想、ホーリーノヴァ、ホーリーランス。
- AI：重傷者へ単体ヒール、味方 HP 75% 以下で全体回復、MP 低下時は夢想、残りは攻撃。solo ボス 32 seed で調和の祈り 99 回、ヒール 105 回。
- 選択：癒光とリジェネ、蘇生、マナリカバーを枠から外す。全体被害向けで、solo では回復効率が下がる。

**攻撃回復（138 SP）**

- 取得：`miracle_mending_prayer`, `miracle_regeneration`, `miracle_resurrection`, `miracle_heart_of_mercy`, `miracle_holy_bolt`, `miracle_holy_nova`, `miracle_holy_lance`, `miracle_healing_ray`, `miracle_lucid_dream`, `miracle_crystal_cycle`
- 5 枠：ヒール、リジェネ、夢想、ホーリーランス、癒光。
- AI：重傷者へヒール、HP 80% 以下でリジェネ、MP 管理、癒光とランスで攻撃。solo ボス 32 seed で癒光 340 回。
- 選択：調和の祈りと範囲攻撃を枠から外し、単体戦と小回復を優先。高い木人 DPS と引き換えに全体回復を持てない。

## 木人と実戦

一般構成はいずれも攻撃だけで 5 枠を埋めていない。100 SP 戦技は短剣乱舞・裂傷斬り・居合斬り・吸収斬り・精密斬り（84 SP）、護身は盾撃・鏡陣・活身法・闘志破砕・不動結界（84 SP）、祝福はヒール・リジェネ・夢想・ホーリーノヴァ・ホーリーランス（72 SP）。140 SP は上記の対強化・継戦、緊急保護、全体回復を代表とした。

| Budget / role | 木人 damage/round | 戦技=100 の比 | 最終ボス damage/round | 勝利 | 実戦の役割行動 |
|---|---:|---:|---:|---:|---|
| 100 戦技・継戦 | 2431.6 | 100 | 1538.7 | 32/32 | 吸収斬り 554 回 |
| 100 護身 | 1950.5 | 80.2 | 1247.4 | 32/32 | 鏡陣 292 回、反撃 835 回 |
| 100 祝福 | 1760.7 | 72.4 | 1299.5 | 32/32 | リジェネ 112 回、夢想 124 回 |
| 140 戦技・対強化継戦 | 2302.1 | 100 | 1532.9 | 32/32 | 吸収斬り 457 回、淀み断ち 32 回 |
| 140 護身・緊急保護 | 1950.5 | 84.7 | 1058.0 | 32/32 | 護命陣 91 回、不動結界 200 回、活身法 105 回 |
| 140 祝福・全体回復 | 1760.7 | 76.5 | 1163.7 | 32/32 | 調和の祈り 99 回、ヒール 105 回 |

目標帯 100 / 護身 75～85 / 祝福 73～83 と比べ、140 SP 一般構成は 100 / 84.7 / 76.5。100 SP 祝福は下限より 0.6 point 低い。100 SP の新技追加で帳尻を合わせることはせず、この差を残す。新技の威力を過剰に上げる必要はない。木人では回復・保護が発動しないが、5 枠の機会費用は反映している。

特化構成の木人 damage/round は出血 finish 戦技 2612.4、障壁反撃 護身 1950.5、攻撃回復 祝福 2140.7。出血 finish は一般戦技の +13.5% だが解除を失う。攻撃回復は一般戦技の 93.0% に達するが、全体回復・範囲攻撃を失い、最終ボスでの有効回復は平均 18,544（全体回復型 22,970）、軽減は 59,836（同 81,896）。この特化構成同士を一般 role 比とは混ぜない。

## Trial 3・10 連戦再測定

| Build | SP | Lv500 完走 /100 | Lv650 完走 /100 |
|---|---:|---:|---:|
| 既存戦技・火力寄り | 90 | 0 | 83 |
| 既存戦技・吸収斬り | 96 | 19 | 98 |
| 戦技・出血と吸収斬り | 84 | 18 | 98 |
| 護身 100 standard | 84 | 68 | 100 |
| 祝福 100 standard | 72 | 98 | 100 |
| 戦技 140・対強化継戦 | 120 | 20 | 96 |
| 戦技 140・出血 finish | 120 | 24 | 98 |
| 護身 140・緊急保護 | 132 | 100 | 100 |
| 護身 140・障壁反撃 | 132 | 67 | 98 |
| 祝福 140・全体回復 | 138 | 75 | 99 |
| 祝福 140・攻撃回復 | 138 | 99 | 100 |

旧 Trial 3 報告の成長型代表は Lv500 で戦技 2% / 護身 95% / 祝福 98%、Lv650 で 90% / 100% / 100%。今回の seed は旧報告と同じ 0～99 だが、STP を全 role 共通方針へ置き換え、100 SP の役割 loadout も明示的に組み直したため、旧値との増減を skill 単独の効果とは解釈できない。新しい同条件内では Lv500 の戦技吸収斬りが火力寄り 0% に対して 19%、Lv650 は 83% に対して 98%。Trial 3 の敵・報酬・装備進行は変更していない。140 SP は clear 後の参考値であり、初回攻略の必要条件ではない。全体回復型は solo 10 連戦ではリジェネを active から外す影響があり、Lv500 の完走率が 100 SP 祝福より低い。

匿名護身サンプルの実配分は生命平均 46.3%、武力 27.8% だった。今回の共通条件では生命 33.5%、武力 52.0% なので、Lv500 の護身 68% と旧 95% の差を敵や既存技の弱体化と見なすことはできない。

## 実装・再現

- 吸収斬り固有の 70% は実 HP damage にだけ適用し、修羅の血脈中も独立。通常吸収 + 血脈の合計には従来の 25% 上限を維持する。両者は実回復量だけを記録する。
- 護命陣は単体 direct attack だけを対象とし、AoE は対象外。solo では自己への一撃軽減となる。障壁・出血消費、解除、全体回復、攻撃回復は canonical combat model と既存 status/heal/dispel 経路で実装した。
- default AI の不動結界は味方 HP 75% 以下で使うよう調整した。非攻撃木人への空打ちを避け、実戦では必要時に使用するための発動条件の変更であり、技本体の軽減量・持続・CT は変更していない。
- skill tree identity は v3→v4 を forward-only migration で更新。取得 node・slot・AI・獲得 SP は消さない。combat identity は v8。Trial 1/2/3 の manifest はこの identity に合わせた。
- `benchmark.php` が木人・単独最終ボス測定、`trial3-reference.php` が段階装備と戦間回復を含む 10 連戦参考測定。入力と詳細は `results-lv650-boss.json`, `trial3-lv500.json`, `trial3-lv650.json`。これらは balance report であり永続的な DPS acceptance test ではない。
- 変更範囲は `product/app/Domain/Underground/Combat/`, `product/config/underground-alpha-v1.php`, `product/config/underground/balance/`, forward migration、必要な test とこの報告。狩場4や Trial 3 enemy には手を加えていない。
