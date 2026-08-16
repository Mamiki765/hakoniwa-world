# 設定管理

## 状態

各Worldが不変の`ruleset_version_id`を参照することをproduction基盤として確定する。`hakoniwa-2s-plus-v1`を初期公開版の正本とし、初期配置、初期資源、command definition、production definitionを含むsettings全体をimmutable snapshotとして公開する。初期版の承認記録はGit、pull request、ruleset validator、CI、merge履歴とし、application内の公開画面は作らない。

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

ruleset versionは公開後に不変とし、変更は新しい一意なkey/versionを作る。各Worldは内部`ruleset_version_id`を記録する。turn導入後は各turn_runも実行時に解決した同じversionを記録する。

公開payloadはsettings全体と、その`ruleset_version_id`に属するcommand definitionsおよびproduction definitionsから成る。同じkeyがすでに存在するときは、payloadと関連定義が完全一致する場合だけ冪等に再利用する。不一致をupdateで合わせず、例外で停止して新しいkey/versionの公開を要求する。

repository内のauthoring sourceは`product/config/hakoniwa/rulesets/<version-key>.php`のPHP arrayとする。`product/config/hakoniwa.php`がversion fileを明示順でassembleし、filesystem globや暗黙順には依存しない。空keyと重複keyはconfig assembly時に拒否する。既存version fileを上書きせず、balance変更はlatest fileを新しいversion keyへcopyして行う。

`php artisan hakoniwa:ruleset:validate --key=<version-key>`はpublisherと同じschema validatorを使い、required key、strict integer type/range、catalog/definition reference、相互条件をDB mutationなしで検証する。このcommandはsnapshotをpublishせず、Worldの`ruleset_version_id`も変更しない。review後のpublishはimmutable snapshot作成、World切替は別の明示的operationであり、このauthoring境界にapply/switch機能を含めない。

`OceanWorldGenerator::initialize()`は、configured rulesetが存在しない場合だけpublisherを通して作成し、存在する場合は保存済みsnapshotとの完全一致を確認する。新規Worldだけをconfigured rulesetへ関連付け、既存Worldの`ruleset_version_id`を変更しない。historical ruleset Worldに対するstandalone initは`reset_required`で停止する。World resetはPR23のgo-live前に残る開発Worldと仮データだけへ使用でき、go-live後は既存Worldをmigrationまたは明示的変換なしに切り替えない。

## Runtime boundary

PR23でconfigured current rulesetを`hakoniwa-2s-plus-v1`へrebaselineする。`CurrentRulesetGuard`は既に読み込んだWorldの`ruleset_version_id`とimmutableなruleset key/versionから確定したcurrent ruleset row IDを比較し、`ruleset_versions.is_active`の意味を変更せず、guard専用SQLを追加しない。

その後のproduction gameplay変更も同じimmutable contractに従い、v2、v3、v4を経て現在は
`hakoniwa-2s-plus-v5`をconfigured current rulesetとする。v4は海底基地の経験値・level・発射数、
H2+命中経験値、海底基地耐性だけをv3へ追加した。v5は海際度による人口bandを削除し、位置に
依存しない通常/誘致growth contractへ移行する。v1–v4のpublished payloadは書き換えない。
既存shared-worldとlive definition参照は専用forward-only migrationでv5へ移し、historical TurnRun
snapshot、seed、queue内容、既存人口を維持する。

v5 migrationは次turnの未解決non-dry TurnRunを拒否し、releaseを跨ぐretryを発生させない。
一方、公開済みv1〜v4 payloadを使う既存の明示的same-ruleset / same-seed recovery契約のため、
TurnRunnerは旧payloadに`sea_edge_bands`がある場合だけ旧population計算を再現する。current v5の
通常turnはこのcompatibility pathへ入らず、sea-edge query、radius走査、全cell turn stateを持たない。

historical ruleset Worldの地図、audit/player event、TurnRun、ruleset snapshotはread-onlyで閲覧できる。turn、dry-run TurnRun作成、command queue追加・数量更新・並べ替え・取消、Nation作成、sale policy更新、standalone initはHTTPでは409 `reset_required`、console/application境界では同じcodeを含むexceptionで拒否する。拒否前後でgame stateとaudit eventを変更しない。go-live後のWorld resetは復旧経路としても許可せず、backup restore、forward migration、または明示的変換を使う。

latest rulesetの必須runtime metadataが欠落している場合もhistorical behaviorへfallbackせず、transactionを失敗させてgame stateをrollbackする。advisory lock、World row lock、TurnRun retry、queue consistency trigger、unique/FK/check constraint、published payload immutabilityはこの期間も維持する。

連続するproduction gameplay ruleset migrationは、後段をまとめて適用したものとして扱わず、一段ずつ完了と整合性を確認する。ver 2.xのv6→v7→v8 chainでは、まずWorldとlive queue/monster/kill-stat参照がv6で整合していることを確認し、v7 migration後に同じ対象がすべてv7へ揃ったことを確認してからv8 migrationへ進む。v8のqueued missile guardが停止した場合はv7を正常なcheckpointとして保持し、review済みconfirmationをその一回のmigration processにだけ与えてretryする。`.env`やpersistent configへconfirmationを保存せず、未解決TurnRun guard、DB constraint/trigger、historical queue item、v1–v8 payload/checksumを各段で維持する。

以下のRoadmap PR6/PR7 migration記録はfresh installと監査に必要な既適用schema履歴として保持するものであり、historical World継続運用の現行手順ではない。PR23では過去PR間だけを再現する互換テストを整理し、現行仕様の回帰テストをproduction rulesetへ向ける。

Roadmap PR6は`roadmap-pr2-v1`を更新せず、`roadmap-pr6-v1`を新規公開した。当時のforward-only migrationは`shared-world`が旧rulesetを参照している場合だけ新rulesetへ移し、queue itemのcommand definition参照を同じcommand keyの新定義へ付け替えた。

Roadmap PR7も既存snapshotを更新せず、基礎資金上限と基礎食料上限を含む`roadmap-pr7-v1`を新規公開した。当時の`shared-world` migrationはWorld、Nation queue、queue itemの順にlockし、旧application processとの競合を直列化した。DBのdeferred constraint triggerはmigration履歴から独立したcurrent integrity constraintとして維持し、queue itemが参照するcommand definitionとWorldの`ruleset_version_id`一致を通常書込み時にも強制する。`CommandQueueService`のcurrent mutationもWorld、Nation queue、queue itemの順にlockする。queue読取はrowを作成せず、Worldを排他lockしない。

global catalogである`TerrainDefinition`、`FacilityDefinition`、`ResourceDefinition`もinitializerから上書きしない。欠けているrowだけ作成し、既存値がconfigと異なる場合は明示的migrationを要求して停止する。PR6のfood単位変更は専用migrationが既存food balanceを100倍し、catalogの単位を`ton`へ変更する。

draft、scheduled、retired、管理画面での公開workflowは初期版へ実装しない。将来in-app publishを追加するときにapplication auditも設計する。

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

## 将来の管理画面

管理画面は単なるkey-value編集にせず、単位、説明、最小・最大、既定値、適用範囲をschemaから表示する。draftの検証、差分、承認、公開予定turn、rollback先を提供する。

初期版はGitHubのPR、commit、CI、merge履歴を承認と公開の記録にする。将来のin-app公開では、誰が、いつ、何を、なぜ、どのWorldへ適用したかをapplication auditへ残し、高影響設定の承認方法もその時点で決める。公開後の履歴は削除しない。

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

## Historical pre-implementation questions

以下はTurnRunner/ruleset実装前に記録したhistorical questionsであり、現在のOpen gateではない。pre-release契約とpublic-release gateは`docs/open-questions.md`のA-09とRELEASE-01を正本とする。

- Status: Open / Required before: ターン処理実装前 — PostgreSQL上の完全なruleset本体をJSONB、型付き設定table、hybridのどれにするか。
- Status: Open / Required before: 本番公開前 — Worldごとの上書き範囲と公開承認の権限model。
- Status: Open / Required before: ターン処理実装前 — turn interval変更時の既存予約処理。
- Status: Deferred / Required before: MVP後 — season eventと恒久rulesetの関係、管理画面内simulation。
