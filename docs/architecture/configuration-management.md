# 設定管理

## 状態

各Worldが不変の`ruleset_version_id`を参照することをMVP基盤として確定する。MVPではNation配置と初期Territoryに必要な最小keyだけを版管理し、turn、command、災害、戦闘、管理画面は先行実装しない。

## 目的

旧C版のconfig.cgiとコード定数、やまにてぃのPHP内ハードコードを、新作では変更頻度、秘密性、監査要件に応じて分離する。

## 3種類の設定

| 種類 | 例 | 保存候補 | 変更方法 |
|---|---|---|---|
| 環境・基盤 | DB接続、queue、cache、公開URL、認証client secret | 環境変数・secret manager | deploy権限者 |
| ゲームruleset | ターン間隔、災害率、費用、生産率、初期資産、首都保護 | 版管理DB＋検証schema | 管理画面でdraft、公開 |
| コード不変条件 | staggered x/y座標、`chunk_size = 16`、状態遷移、許可された演算種別 | source code・ADR | reviewとrelease |

秘密をrulesetへ入れない。ゲームバランス値を環境変数だけに置かない。管理者が任意PHPや式を入力して実行する仕組みも作らない。

## rulesetの版管理

ruleset versionは公開後に不変とし、変更は新しい版を作る。各Worldは内部`ruleset_version_id`を記録する。turn導入後は各turn_runも実行時に解決した同じversionを記録する。

MVP migrationは、Worldからversionを参照でき、配置用の`territory_initial_radius = 2`と`capital_min_distance = 12`を保持できる最小構造に留める。draft、scheduled、retired、公開workflow、turn・command・災害schemaを空実装で先行させない。

公開操作では次を検証する。

- 必須キーと型。
- 数値範囲、単位、合計確率。
- カタログID参照。
- 相互条件。例として最低首都人口が初期人口を超えないこと。
- 既存世界に適用可能か、次ターンからか、新世界だけか。
- 変更前後の差分と影響見積り。

## 設定キーの候補

### 世界

- initial_x_min、initial_x_max、initial_y_min、initial_y_max。MVPは`0`、`59`、`0`、`59`。
- territory_initial_radius。MVPの暫定既定値は2。
- capital_min_distance。MVPの暫定既定値は12。
- registration_clearance_radius。
- expansion_margin、expansion_max_chunks_per_operation。
- terrain_generation_profile。

`chunk_size = 16`はruleset keyではなくarchitecture invariantとする。

### ターン

- turn_interval_seconds。
- command_deadline_offset_seconds。
- max_commands_per_nation。
- inactivity_warning_days。休眠stateの30日、180日、365日はADR-0004の正式判断であり、turn数では管理しない。
- event_retention_turns、snapshot_interval_turns。

### 経済と人口

- initial_funds、initial_resources（MVPはwheat、fish、monster_meatの国家別残高）。
- production_rates、maintenance_costs。
- food_consumption_per_population。
- population_growth_curve。
- resource_caps。旧来の255や65535を根拠にしない。

経済・生産・消費はMVP縦切りに含めない。将来のresource種はcatalogから追加できるようにし、MVP schemaを資源種ごとの固定columnだけへ閉じ込めない。

### 災害と戦闘

- disaster_rate_by_type。
- missile_cost、range、accuracy、damage profile。
- defense modifiers。
- border influence and resistance。
- capital_damage_ratio、capital_recovery、capital_population_display_unit。首都人口の下限1単位は変更不能なdomain invariantとする。

### 機能フラグ

- layer availability。
- research、items、proficiencyなど段階公開。
- 特定イベントの有効期間。

## 管理画面

管理画面は単なるkey-value編集にせず、単位、説明、最小・最大、既定値、適用範囲をschemaから表示する。draftの検証、差分、承認、公開予定turn、rollback先を提供する。

本番変更は、誰が、いつ、何を、なぜ、どのworldへ適用したか監査する。高影響設定は二者承認を検討する。公開後の履歴は削除しない。

## 時間設定

turn_intervalは設定可能にするが、既存worldの次回時刻をどう変えるかを明示する。候補は次の通り。

- 次ターン完了後から新間隔。
- 指定turnから新間隔。
- next_turn_atを管理者が明示指定。

DSTの影響を避けるため内部はUTC instantで管理する。ローカル時刻の「毎日0時」のようなcalendar scheduleが必要なら、timezoneとDST規則を別に持つ。

## 災害率と確率

確率は整数分子・分母、basis points、または固定精度decimalで保存し、浮動小数の比較差を避ける。抽選回数と対象母集団も設定説明に含める。「1%」だけでは、国家ごと、セルごと、世界ごとのどれか不明だからである。

確率変更は次のturn_runが参照するrulesetから有効にし、実行途中にactive版が変わっても結果へ影響させない。

## キャッシュ

rulesetは読取り頻度が高いためキャッシュ可能だが、cache keyにversionを含める。activeという可変キーだけに依存せず、turn開始時に具体版を解決する。キャッシュ障害時はDBから読み、古い版へ無言でfallbackしない。

## 未決定事項

- Status: Open / Required before: ターン処理実装前 — PostgreSQL上の完全なruleset本体をJSONB、型付き設定table、hybridのどれにするか。
- Status: Open / Required before: 本番公開前 — Worldごとの上書き範囲と公開承認の権限model。
- Status: Open / Required before: ターン処理実装前 — turn interval変更時の既存予約処理。
- Status: Deferred / Required before: MVP後 — season eventと恒久rulesetの関係、管理画面内simulation。
