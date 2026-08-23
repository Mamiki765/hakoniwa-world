# 首都と領土

## 状態

Capitalの現行契約は、初期人口1,000人、地図上に存在する間の最低人口100人、通常成長上限25,000人である。Capitalと初期Territoryは実装済みで、人口成長と災害damageも後続roadmapで実装された。怪獣、戦闘、機能停止、復旧、占領の未決事項は`docs/open-questions.md`を正本とする。

## 首都の確定要件

- 国家作成時に1つ自動配置する。
- 通常の建設命令では作れない。
- 通常の災害、ミサイル、怪獣によって首都施設自体は消滅しない。
- 通常の国境処理や占領で所有国は変わらない。
- 首都人口は災害・攻撃で割合減少し得る。
- 地図上に首都が存在する間、首都人口は最低100人を下回らない。
- 被害は人口、収入、機能、復旧負担として残り、無敵の利益装置にはしない。
- 国家存続条件を首都人口0に依存させない。
- activeな首都はsettlement_seed能力により再建の起点になれる。

この要件は「首都セルが一切影響を受けない」ことを意味しない。施設identityと所有権は不変でも、人口・稼働率・周囲の領土・接続は損傷し得る。

## 初期TerritoryのMVP既定値

MVPの初期TerritoryはCapital cellと、Capitalからx/y grid distance 2以内を第一候補とする。最大19セル相当であり、`territory_initial_radius = 2`としてWorldが参照するruleset versionへ置く。

これは確定balance値ではなく、次の条件を守ったまま新しいruleset versionで見直せる値である。

- 他国Territoryと重ならない。
- 別の新規Nationの初期Territoryとも重ならない。
- Capital間は暫定`capital_min_distance = 12`以上離す。
- 現在の生成済み境界を固定の「世界端」とみなさない。必要なら既存座標を動かさずWorldを拡張する。
- Capital周囲に最低限の発展可能地を確保する。

初期Territoryはdistance 2以内の生成陸地19 cellsだけとし、範囲外の生成陸地は中立のまま残す。候補地点のscoreと初期人口は`docs/open-questions.md`のB-18とB-01で決定済みである。

## データモデル案

- NationCapitalは`active`、`dormant`、`recovery`で非null、`abandoned`で終了する。
- facility_instancesにcapital種別を持たせ、通常commandからの生成を禁止する。
- capital_stateにpopulation、operational_level、damage_state、recovery_progressを持つ。
- 現在地図に首都がある間、map_cell.owner_nation_idはcapitalのnationと一致させる。
- CapitalDamaged、CapitalRecovered等をdomain eventとして記録する。
- settlement_seed能力は施設IDの直接分岐ではなく、版管理された能力定義として首都へ関連付ける。
- emergency recoveryのgrant、cooldown、自己撤去履歴、生成施設を監査可能にする。

首都だけをif分岐で多数のhandlerへ散らさず、DamagePolicy、OwnershipPolicy、ConstructionPolicyで明示する。ただし汎用modifierだけで不変条件を表し、設定ミスで消滅可能になる設計は避ける。

## 人口と割合被害

Capitalのcanonical populationは人単位で、初期値1,000、固定下限100、通常成長上限25,000とする。全てのCapital population damageは各event開始時点の現在人口へ逐次適用し、`max(100, floor(old_population * (100 - damage_percent) / 100))`をeventごとに確定する。turn内でdamageを合算して最後に1回だけ丸めたり、minimumを最後だけ適用したりしない。

通常cellを荒地化するdamageは10%、一段階掘削・浅瀬化相当は30%、深海化相当は90%、噴火中心の山化は30%とする。Capital facility identity、owner、terrain、Nationのcapital coordinate、territory identityを維持し、population、cell version、chunk invalidation、audit/player logだけを変更する。`abandoned`へのmanual/automatic cleanupではCapital自体を地図から除去するため、このpopulation下限を適用しない。

## 復旧方式の案

- 自然回復: 毎ターン一定割合または一定人数。
- 命令回復: 資金・資源を使う復旧command。
- 生産連動: 食料、医療、交通が揃うほど回復。
- 時限機能停止: 一定turnで段階回復。

暫定推奨は小さな自然回復と、費用を払う復旧commandの併用である。完全な自動回復は攻撃の意味を弱め、完全な手動回復は復帰不能を生みやすい。

## settlement_seedと人口成長

randomized sequential cell processingで、所有者がいる人口0・施設なしの平地を候補とする。候補ごとに100面20未満を先に抽選し、その後、隣接6 cellsに農場または人口1人以上の集落があれば人口100人の村を発生させる。先に発生した村は同じturnの後続cellから観測できる。

`hakoniwa-2s-plus-v5`では海際度をgameplayから廃止し、通常settlementは位置にかかわらず100〜1,000人、通常上限10,000人とする。誘致時は通常上限未満100〜3,000人、到達後100〜300人、最終上限20,000人である。飢餓時の100〜3,000人減少、stage transition、Capitalをsettlement facilityへ置換しないidentity、minimum 100、ordinary growth cap 25,000を維持する。実装記録は`product/docs/ver-1.5.0-beta3-sea-edge-removal.md`を正本とする。

## 緊急開拓のhistorical proposal

以下は初期設計時のplayer command proposalであり、現在のcommand契約では採用していない。ADR-0014はこれと別に、dormant entry/heartbeatでfarm capacity 0の場合だけdistance 2以内へ決定的な最小農場を1つ作る。active Nation向けcommand、cooldown、債務等は実装しない。

次を全て満たすactive国家だけが、首都から緊急開拓を実行できる。

- 農場系施設が0。
- 通常農場の建設費を支払える資金がない。
- 食料生産能力がrulesetの最低基準未満。
- 首都が完全な機能停止中ではない。
- 首都に隣接する建設可能な自国領土がある。
- 有効な緊急農場を同時に保有していない。
- cooldown中ではない。
- 最近、自分で農場系施設を撤去していない。

第一候補は費用0で最低規模の緊急農場を1つ生成する方式である。通常建設ではなく復旧支援commandとして扱い、eligibilityをcommand登録時と実行時に再検証する。

悪用防止として次を必須にする。

- nation単位のgrant履歴とidempotency key。
- 同時に1施設までの一意制約。
- rulesetで定めるcooldown。
- 自己撤去、売却、建替え履歴の確認。
- 災害・敵対行為による喪失と自己撤去を理由codeで区別。
- 農業能力が最低基準以上へ戻った時点で新規利用を拒否。
- EmergencyCultivationGranted eventへの条件、生成座標、生成施設の記録。

代償案は次の通り。

| 案 | 利点 | 懸念 |
|---|---|---|
| 数turnの税収低下 | 回復後に支払うため即時詰みを防ぐ | 他の収入がない国家には重い |
| 復興債務 | 費用0でも長期costを明示できる | 債務システムが初期版には過剰 |
| 低生産の仮設農場 | 通常農場を置き換えず悪用価値が低い | 低すぎると救済にならない |
| 一定期間後に通常農場へ昇格 | 再建目標が分かりやすい | 無料の恒久施設化を防ぐ条件が必要 |
| 一定期間後に消滅 | 繰返し利用を抑えやすい | 期限までに復旧できないと再び詰む |

将来実装時は、費用0、最低規模、低生産の仮設施設、同時1つ、cooldown、自己撤去履歴確認を第一候補とする。昇格・消滅・税収低下・債務の最終組合せと具体turn数は確定しない。

## 領土の正本

領土はmap_cell.owner_nation_idで表し、国境は隣接する異なる所有者から導出する。所有権変更は、攻撃影響、隣接支持、防御、地形、施設、保護状態を入力とする純粋な判定へ寄せる。

中立地はownerがnullのセルであり、海・荒地などterrainとは別概念とする。ADR-0014に従い、dormant Capital distance 2以内はowner変更を禁止し、範囲外は通常のterritory契約を適用する。abandoned cleanupは残存領土を海へ戻し、過去履歴を残す。

## 防壁都市の比較

| 方式 | 効果 | 評価 |
|---|---|---|
| 国境変更を完全阻止 | 存在中は所有権が変わらない | 首都以外まで不可侵になり膠着しやすく非推奨 |
| 固定抵抗値を加算 | 攻撃影響から一定値を引く | 分かりやすいが規模差で無力・過強になりやすい |
| 周辺セルへ抵抗を付与 | 隣接範囲の防御を強化 | 戦略性があるが重複・範囲計算が必要 |
| 攻撃影響へ倍率 | 受ける影響を一定割合へ減らす | 拡張性が高く複数効果との合成規則が必要 |
| 耐久が尽きるまで阻止 | 防壁にdurabilityを持つ | 状態が明確で攻城戦を表現しやすい |

推奨は「周辺抵抗＋倍率または耐久」の征服可能な施設である。首都と異なり、耐久低下、機能停止、破壊、占領を可能にする。完全阻止を採る場合も短期間の効果や高維持費などの制約が必要である。

## 所有権変更の制約

- capital cellは通常処理で対象外。
- 新規保護中の領土はrulesetに従う。
- 変更元・変更先nationが同じworldに存在する。
- 1セルの所有権変更は1turn内で最終結果を1つに決定する。
- 競合影響へsimultaneous resolutionを暗黙に導入しない。source-derivedなrandom cell orderの逐次因果を前提に、exact algorithmとtie handlingはB-07で決める。
- 変更時にchunk version、国家領土集計、domain eventを同時更新する。

## 国家の存続と休眠

首都人口0や戦闘敗北による即時削除を基本ルールにしない。国家stateは`active`、`dormant`、`recovery`、`abandoned`とし、ver 2.4.0はidle/collapse/manualでdormant、期限/queueでactive、idle 2160または既存owner操作でabandonedへ遷移する。

dormant復帰は現在の首都・領土から再開し、休止中の生産を遡及しない。abandonedでは首都も現在地図から除去するが、user、nation、Secretary、event、統計、領土・首都履歴は物理削除しない。

## 現在の要決定事項

現在のblocking gateは`docs/open-questions.md`を正本とする。Capital関連ではB-03（機能停止と復旧）とB-05（防壁）がOpenであり、B-13（dormant距離2保護）はADR-0014でDecided、B-15（再入植）は現行manual abandonment契約としてDecidedである。

## Historical initial MVP実装記録（2026-07-26）

Capitalは原作にない新施設であり、箱庭諸島2＋由来画像を流用しない。`tile.capital`はGit外read-only asset directoryの`capital.gif`へ解決し、不足時はCSS fallbackを表示する。中心cellは必ずNation所有、population 1,000とし、`nation_capitals`から座標を安定取得する。初期sliceのminimum ruleset値1は、後続の災害・人口契約でcanonical 100人へsupersedeされた。

初期TerritoryはCapitalからdistance 2以内の生成陸地19セルだけである。島の成長範囲はdistance 4、配置予約範囲はdistance 5であり、Territoryと同一視しない。distance 2外に生成された陸地は中立のまま残せる。
