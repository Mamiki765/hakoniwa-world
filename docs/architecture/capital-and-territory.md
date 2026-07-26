# 首都と領土

## 状態

Capitalの不変条件と最低人口1単位は確定する。最初のMVP縦切りではCapitalと初期Territoryの生成までを対象とし、被害、回復、settlement_seed、緊急開拓、国境、戦闘は実装しない。これらは該当機能の実装前に`docs/open-questions.md`を確認する。

## 首都の確定要件

- 国家作成時に1つ自動配置する。
- 通常の建設命令では作れない。
- 通常の災害、ミサイル、怪獣によって首都施設自体は消滅しない。
- 通常の国境処理や占領で所有国は変わらない。
- 首都人口は災害・攻撃で割合減少し得る。
- 地図上に首都が存在する間、首都人口は最低1単位を下回らない。
- 被害は人口、収入、機能、復旧負担として残り、無敵の利益装置にはしない。
- 国家存続条件を首都人口0に依存させない。
- activeな首都はsettlement_seed能力により再建の起点になれる。

この要件は「首都セルが一切影響を受けない」ことを意味しない。施設identityと所有権は不変でも、人口・稼働率・周囲の領土・接続は損傷し得る。

## 初期TerritoryのMVP既定値

MVPの初期TerritoryはCapital cellと、Capitalからaxial distance 2以内を第一候補とする。最大19セル相当であり、`territory_initial_radius = 2`としてWorldが参照するruleset versionへ置く。

これは確定balance値ではなく、次の条件を守ったまま新しいruleset versionで見直せる値である。

- 他国Territoryと重ならない。
- 別の新規Nationの初期Territoryとも重ならない。
- Capital間は暫定`capital_min_distance = 12`以上離す。
- 現在の生成済み境界を固定の「世界端」とみなさない。必要なら既存座標を動かさずWorldを拡張する。
- Capital周囲に最低限の発展可能地を確保する。

水域・建設不能地形を初期Territoryへ含めるか、distance 2の範囲外セルや生成済み境界外をどう扱うか、候補地点のscore、Capital初期人口は国家作成実装前に決める。

## データモデル案

- nations.current_capital_cell_idを一意とする。active、dormant_frozen、dormant_contestableでは非null、sunken_archivedではnullとする。
- facility_instancesにcapital種別を持たせ、通常commandからの生成を禁止する。
- capital_stateにpopulation、operational_level、damage_state、recovery_progressを持つ。
- 現在地図に首都がある間、map_cell.owner_nation_idはcapitalのnationと一致させる。
- CapitalDamaged、CapitalRecovered等をdomain eventとして記録する。
- settlement_seed能力は施設IDの直接分岐ではなく、版管理された能力定義として首都へ関連付ける。
- emergency recoveryのgrant、cooldown、自己撤去履歴、生成施設を監査可能にする。

首都だけをif分岐で多数のhandlerへ散らさず、DamagePolicy、OwnershipPolicy、ConstructionPolicyで明示する。ただし汎用modifierだけで不変条件を表し、設定ミスで消滅可能になる設計は避ける。

## 最低人口

首都人口の固定下限を1単位とする。1単位が実人口何人に相当するかは表示・バランス仕様で別途決める。

全ての首都人口damageはpopulation = max(1, afterDamage)を最後に適用する。ただし、sunken_archivedへの沈没処理では首都自体を現在地図から除去するため、この下限は適用しない。

最低1でも、稼働率、税収、機能、自然回復速度、復旧費はdamageを受ける。人口下限だけで国家を削除せず、最低値への到達を無敵化や敗北判定として使わない。

## 割合被害の案

被害量を単純な固定人数ではなく、攻撃・災害ごとのdamage ratioで算出する。丸め規則、1回上限、1ターン累積上限、最低保証適用順を固定する。

候補順序:

1. rawDamage = floor(currentPopulation × ratio)。
2. 防御・災害軽減を適用する。
3. 1イベント上限を適用する。
4. population = max(capitalMinimum, current - effectiveDamage)。
5. 防ぎ切れなかったraw impactからoperational damageを算出する。

複数攻撃のたびに割合計算するか、ターン内で合算して1回計算するかで結果が変わる。後者は順序依存を減らすため有力だが、戦闘ログ表現と合わせて決める。

## 復旧方式の案

- 自然回復: 毎ターン一定割合または一定人数。
- 命令回復: 資金・資源を使う復旧command。
- 生産連動: 食料、医療、交通が揃うほど回復。
- 時限機能停止: 一定turnで段階回復。

暫定推奨は小さな自然回復と、費用を払う復旧commandの併用である。完全な自動回復は攻撃の意味を弱め、完全な手動回復は復帰不能を生みやすい。

## settlement_seedによる村発生

このsectionはturnの自動発展実装前に確定する将来設計であり、MVP縦切りには含めない。

首都を村・集落の発生源とする。地形・施設IDごとのif分岐ではなく、settlement_seed能力が候補探索と生成profileを提供する。

ターンの自動発展phaseで、activeな国家について次を全て満たす場合だけ候補にする。

- 首都に隣接する6セルのいずれか。
- 自国領土または中立地。
- 村を生成可能な地形。
- 敵国が所有・支配していない。
- 首都が完全な機能停止中ではない。
- 村または同等集落を置ける空きがある。

中立地へ生成する場合は、そのセルを同じ国家の領土として確定する。敵国領土を村の自然発生で上書きしない。候補が複数あるときはturn seedと安定した座標順を使って決定的に選ぶ。

dormant_frozen、dormant_contestable、sunken_archivedでは自動発展を停止するため発生させない。首都だけが残ったactive国家も、適格な隣接セルがあれば時間をかけて村と領土を再建できる。

成功時はSettlementSeeded eventにsource capital、target q、r、生成施設、所有権変更を記録する。発生確率、最小村規模、1回あたりの頻度は未決定である。

## 緊急開拓

このsectionはcommand実装前に確定する将来設計であり、MVP縦切りには含めない。

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

中立地はownerがnullのセルであり、海・荒地などterrainとは別概念とする。ADR-0004に従い、dormant_contestableでは首都以外を占領可能にし、sunken_archivedでは残存領土を海へ戻す。どちらでも過去領土履歴は残す。

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
- 競合影響は処理順ではなく、同時入力を集約して解決する。
- 変更時にchunk version、国家領土集計、domain eventを同時更新する。

## 国家の存続と休眠

首都人口0や戦闘敗北による即時削除を基本ルールにしない。国家stateはactive、dormant_frozen、dormant_contestable、sunken_archivedに統一し、30日、180日、365日のUTC経過または明示的放棄で遷移する。

365日未満の復帰では残存首都から再建できる。他国に占領された領土を自動返還せず、凍結期間の生産も遡及しない。sunken_archivedでは首都も現在地図から除去するが、user、nation、event、統計、領土・首都履歴は物理削除しない。

## 要決定事項

- Status: Open / Required before: 国家作成実装前 — 初期Capital人口、1人口単位の表示換算、初期Territoryへ含められる地形。
- Status: Open / Required before: 戦闘実装前 — 被害率、丸め、turn累積上限、機能低下、復旧費、占領保護ring、Capital移転、防壁都市。
- Status: Open / Required before: ターン処理実装前 — settlement_seedの発生率、最小村規模、頻度。
- Status: Open / Required before: コマンド実装前 — 緊急農場のcooldown、自己撤去確認期間、昇格・消滅・代償。
- Status: Deferred / Required before: MVP後 — sunken_archivedからの再入植条件。

## MVP実装記録（2026-07-26）

Capitalは原作にない新施設`hakoniwa_new.capital`であり、原作GIFを流用せずCSS placeholderを表示する。中心セルは必ずNation所有、population 1,000、最低人口ruleset値1とし、`nation_capitals`から座標を安定取得する。

初期TerritoryはCapitalからdistance 2以内の生成陸地19セルだけである。島の成長範囲はdistance 4、配置予約範囲はdistance 5であり、Territoryと同一視しない。distance 2外に生成された陸地は中立のまま残せる。
