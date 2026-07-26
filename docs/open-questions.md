# 新作の設計判断と未解決事項

## 使い方

本書は、決定済み事項を含む設計判断の索引である。実装担当者は作業開始前に、対象機能に関係する項目の`Status`と`Required before`を確認する。

- `Status: Decided`: 方針を決定済み。`Decision`と`Decision record`を正本とする。
- `Status: Open`: 指定した機能の実装前に決定が必要。
- `Status: Deferred`: MVP縦切りでは実装しない。将来機能を追加できる境界だけ維持する。

`Open`または`Deferred`を、実装中に暗黙の仮定で確定してはならない。該当項目、候補、実装への影響を報告してから判断する。

## 最初のMVP縦切り

今回の最初の実装範囲は次に限定する。

1. Laravelプロジェクト作成。
2. PostgreSQL接続。
3. Discord OAuthとGoogle OAuthによるログイン。
4. 1つのUserへ複数の認証identityを明示的に連携。
5. 共有地上worldの初期生成。
6. Nation作成とサーバーによる空き地点への自動配置。
7. Capitalと初期Territoryの生成。
8. Capital周辺のchunk取得API。
9. Vueによる地図表示。

ターン、コマンド、生産・消費、災害、戦闘、国境変化、自動発展、休眠遷移Job、通知配送はこの縦切りへ含めない。

## MVP縦切りの確定事項

### A-02 チャンク辺長

- Status: Decided
- Decision: `chunk_size = 16`
- Decision record: `docs/architecture/chunk-storage.md`、`docs/decisions/ADR-0003-hex-coordinate-system.md`
- q、rをそれぞれ16単位で区切る。負座標では数学的な`floorDiv`と`floorMod`を使う。
- PHP、TypeScript、SQL固有の整数除算・剰余へ直接依存しない。

### A-03 DB製品

- Status: Decided
- Decision: PostgreSQL
- Decision record: `docs/architecture/target-architecture.md`
- 箱庭専用DBとして、Nextcloud用MariaDBとは分離する。

### A-04 国家登録地点

- Status: Decided
- Decision: MVPではサーバーが安全な空き地点へ自動配置する。
- Decision record: `docs/architecture/registration-and-world-expansion.md`
- 座標直接指定と3候補提示UIは採用しない。同時登録はtransactionとlockまたは予約行で直列化し、空きがなければ必要最小限のchunkを生成して再探索する。

### A-05 初期領土と首都間距離

- Status: Decided
- Decision: 構造はruleset値とし、MVP既定値は初期領土がCapitalからaxial distance 2以内、首都間最低距離が12。
- Decision record: `docs/architecture/capital-and-territory.md`、`docs/architecture/registration-and-world-expansion.md`
- これは確定バランスではない。既存worldが参照するruleset versionを変えずに値を上書きせず、新しい版で見直す。

### A-09 rulesetのMVP境界

- Status: Decided
- Decision: 各Worldは不変の`ruleset_version_id`を参照する。初期MVPは配置・初期領土だけを版管理し、Roadmap PR2のdata-preserving migrationで既存worldを新しい`roadmap-pr2-v1`へ明示的に移してcommand・施設・生産定義を追加する。既存ruleset rowを上書きしない。
- Decision record: `docs/architecture/configuration-management.md`、`docs/architecture/target-architecture.md`
- `chunk_size = 16`と座標方式は既存worldの互換性に関わるarchitecture invariantであり、通常のバランス設定として変更しない。

### A-10 認証方式

- Status: Decided
- Decision: Discord OAuthとGoogle OAuthを使用し、Userと外部認証identityを分離する。
- Decision record: `docs/architecture/authentication-and-identities.md`、`docs/decisions/ADR-0005-authentication-identities.md`
- 1つのUserへ複数identityを関連付けられる。
- `(provider, provider_user_id)`を一意にする。
- providerのメールアドレス一致だけでは自動統合しない。
- UserとNationを分離し、外部provider IDをNation所有者IDにしない。

### A-11 初期生成範囲

- Status: Decided
- Decision: 地上の初期生成範囲は`q = -30..29`、`r = -30..29`の3,600セル。
- Decision record: `docs/architecture/world-and-map-space.md`、`docs/decisions/ADR-0003-hex-coordinate-system.md`
- 論理上の固定上限ではない。拡張時も既存セルの座標を移動しない。

### C-05 API版管理

- Status: Decided
- Decision: URL prefixを`/api/v1`とする。
- Decision record: `docs/architecture/ui-and-map-loading.md`

### C-06 chunk応答形式

- Status: Decided
- Decision: MVPは可読なJSONを使い、chunk単位で取得する。
- Decision record: `docs/architecture/ui-and-map-loading.md`
- compact array、binary、独自圧縮はMVP後へ延期する。APIとDBはabsolute axial `q`、`r`を使い、offset座標はUI投影だけに使う。

## 認証実装前に決める事項

認証のdomain modelと安全条件は決定済みだが、Laravelの具体的なpackageと画面遷移は未決定である。

### AUTH-01 Discord用OAuth adapter

- Status: Decided
- Required before: 認証実装前
- Decision: Laravel Socialite 5.29.0とSocialiteProviders/Discord 4.2.0を使用する。Discordは`identify`、Googleは`openid profile`だけを要求し、tokenとemailは保存しない。

### AUTH-02 SPAのsession認証

- Status: Decided
- Required before: 認証実装前
- Decision: 同一originのLaravel sessionを使用する。OAuth state、CSRF、callback後のsession regenerationを必須とし、MVPではSanctumを追加しない。

### AUTH-03 VueとLaravelの配信origin

- Status: Decided
- Required before: Laravel初期構築前
- Decision: production buildしたVueをLaravelの`public/`から同一origin配信する。

### AUTH-04 ログイン・連携後のredirect UX

- Status: Decided
- Required before: UI実装前
- Decision: 成功・連携競合・失敗をquery status付きでトップへ戻し、Vueが結果を案内する。login/linkの意図はsessionへ別保存する。

### AUTH-05 provider障害時の復旧

- Status: Open
- Required before: 本番公開前
- 片方のproviderが停止・終了した場合の案内、既存session、再試行、もう一方のidentityによるログインを決める。

### AUTH-06 管理者用緊急復旧

- Status: Deferred
- Required before: MVP後
- 管理者によるidentity差替え、本人確認、二者承認、監査を設計してから実装する。

### AUTH-07 account merge

- Status: Deferred
- Required before: MVP後
- 別々に作成されたUser同士の自動統合・手動統合は行わない。Nation、資源、履歴、認証identity等の衝突方針を決めてから追加する。

### AUTH-08 ログイン手段の解除UI

- Status: Deferred
- Required before: MVP後
- MVP縦切りでは解除UIを実装しない。データモデルとservice境界は、identityが2個以上なら解除可能、最後の1個は解除不可という不変条件を維持する。

### AUTH-09 追加providerとローカル認証

- Status: Deferred
- Required before: MVP後
- メールアドレス・パスワード、パスキー、GitHub、Apple等はprovider追加境界から実装する。既存`users`へprovider固有列を追加しない。

## 国家作成実装前に決める事項

### B-01 初期Capital人口

- Status: Decided
- Required before: 国家作成実装前
- Decision: MVPのCapital人口は1,000、最低人口は1。表示換算はturn・人口処理実装前に決める。

### B-06 初期Territoryへ含められる地形

- Status: Decided
- Required before: 国家作成実装前
- Decision: MVPでは生成した初期島のdistance 2以内の陸地19セルだけを所有させる。distance 2外の生成陸地は中立のまま残す。

### B-18 登録候補地点の評価

- Status: Decided
- Required before: 国家作成実装前
- Decision: 中心からdistance 5以内の91セルが全て生成済みの海・無所有・施設なしであること、Capital間距離12以上を必須とする。既存Capitalから最も遠い候補を優先し、q、r昇順で安定tie-breakする。

### B-08 初期保護

- Status: Deferred
- Required before: 攻撃command実装前
- Roadmap PR2の7 commandは自国cellだけを対象とする国内commandで、初期保護の対象外とする。保護期間、敵対行為、解除条件は攻撃・占領command導入前に決め、今回のqueueへ暗黙の保護期間を追加しない。

## マップAPI・UI実装前に決める事項

### C-01 地図描画方式

- Status: Decided
- Required before: UI実装前
- Decision: MVPはDOM/CSS rendererを採用する。API、map state、projection、rendererを分離し、計測後にCanvasへ交換できる境界を維持する。

### C-03 霧・未発見領域

- Status: Decided
- Required before: マップAPI実装前
- Decision: MVPに霧はないが、`visibility_policy=disguised`のfacilityはserver presenterが公開表現へ置換する。ミサイル基地は所有国だけに実体を返し、その他のviewerへは通常の他国森林と同じterrain=forest、facility=null、数量なしを返す。OAuth・内部metadata・秘密stateは公開しない。

### C-07 国際化

- Status: Decided
- Required before: UI実装前
- Decision: MVP UIは日本語、全新規source・DB text・APIはUTF-8とする。本格的なmessage catalogはMVP後。

### C-08 アクセシビリティ

- Status: Decided
- Required before: UI実装前
- Decision: 六方向keyboard移動、選択セルの通常HTML text、所有国名とID表示を最低要件とする。

## ターン処理実装前まで保留する事項

### A-06 ターンの確定順序

- Status: Open
- Required before: ターン処理実装前
- 現時点ではMVP縦切りを妨げない。phase境界と同時解決規則をシナリオテストで確定する。

### A-07 1ターンのtransaction規模

- Status: Open
- Required before: ターン処理実装前
- 単一transaction、phase checkpoint、公開境界を負荷試験後に決める。

### B-09 災害抽選単位

- Status: Open
- Required before: ターン処理実装前
- world、Nation、chunk、cellのどれを母集団にするか、災害種ごとに決める。

### T-01 乱数seedと再現方式

- Status: Open
- Required before: ターン処理実装前
- seedの生成・保存、安定した列挙順、再試行時の再現契約を決める。

### T-02 休眠状態遷移Job

- Status: Open
- Required before: ターン処理実装前
- ADR-0004の状態とUTC境界は決定済み。scheduler、world lock、turnとの直列化、batch checkpointは実装前に確定する。

### D-02 turn失敗時の再試行

- Status: Open
- Required before: ターン処理実装前
- 冪等性を保証した後、回数、backoff、手動再開条件を決める。

## コマンド実装前まで保留する事項

### A-08 コマンド件数・順序・予約

- Status: Decided
- Required before: コマンド実装前
- Decision: 旧作と同じ上限20件、1始まりの明示positionとし、追加・全件並べ替え・取消後の左詰めをtransactionで行う。header versionによるoptimistic concurrencyとrequest keyによる重複防止を使う。登録時に資金・資源を予約せず、turn runnerが実行時に再検証する。数量・繰返しは未実装で、versioned parameters境界だけ維持する。
- Decision record: `docs/architecture/roadmap-pr2-systems.md`

### CMD-01 箱庭諸島2＋コマンドの採否

- Status: Decided
- Required before: コマンド実装前
- Decision: PR2では旧作sourceで確認した整地、地ならし、埋め立て、掘削、農場建設、工場建設、採掘場建設を別々のversioned definitionとして採用する。費用と施設scaleは旧作値を維持し、実行、副作用、乱数処理はturn runnerへ延期する。
- Decision record: `docs/architecture/roadmap-pr2-systems.md`

### B-16 settlement_seed

- Status: Open
- Required before: ターン処理実装前
- 発生率、村規模、頻度、候補選択を決める。MVP縦切りでは自動発展を実装しない。

### B-17 緊急農場

- Status: Open
- Required before: コマンド実装前
- cooldown、自己撤去確認期間、昇格・消滅・代償を決める。MVP縦切りでは実装しない。

## 戦闘実装前まで保留する事項

### B-02 Capitalへの複数被害

- Status: Open
- Required before: 戦闘実装前
- eventごとかturn合算か、丸めと最低人口適用順を決める。

### B-03 Capital機能停止と復旧

- Status: Open
- Required before: 戦闘実装前
- 自然回復、復旧command、生産連動、時限停止の組合せを決める。

### B-05 防壁都市

- Status: Open
- Required before: 戦闘実装前
- 周辺抵抗、倍率、耐久、重複、攻略方法を決める。

### B-07 国境影響の同時解決

- Status: Open
- Required before: 戦闘実装前
- 国家の処理順に依存せず、同一turnの影響を集約して1セル1結果にする規則を決める。

### B-10 ミサイル可視性

- Status: Open
- Required before: 戦闘実装前
- 発射者、目標、結果、命中・失敗理由の公開範囲を決める。

### B-11 怪獣の主体モデル

- Status: Open
- Required before: 戦闘実装前
- cell stateか独立actorかを決める。

### B-12 dormant国家への攻撃詳細

- Status: Open
- Required before: 戦闘実装前
- dormant_contestableの施設・防壁処理と、怪獣討伐例外の実行契約を決める。

### B-13 Capital周辺の占領保護

- Status: Open
- Required before: 戦闘実装前
- dormant_contestableでCapitalから何ringを保護するか決める。

## 本番公開前・運用開始前に決める事項

### B-14 明示的放棄の安全策

- Status: Open
- Required before: 本番公開前
- 再認証、待機・取消期間、確認入力、cooldown、監査を決める。MVP縦切りに放棄UIは含めない。

### B-15 再入植

- Status: Deferred
- Required before: MVP後
- 初期資源、旧国家名、Nation identity、ranking、保護期間を決める。

### D-01 scheduler・queue基盤

- Status: Open
- Required before: ターン処理実装前
- Web processと分離する。製品と運用方式はturn・Lifecycle Job導入前に決める。

### D-03 ruleset公開承認

- Status: Open
- Required before: 本番公開前
- 単独管理者か二者承認かを決める。

### D-04 backupのRPO・RTO

- Status: Open
- Required before: 本番公開前
- PostgreSQLの継続backup、snapshot、復旧演習と目標値を決める。

### D-05 event・log保持期間

- Status: Open
- Required before: 本番公開前
- プレイヤー表示、監査、分析を分離して決める。

### D-06 通知dead letter

- Status: Deferred
- Required before: MVP後
- notification outbox導入時に再送、破棄、監査を決める。

### D-07 moderation

- Status: Open
- Required before: 本番公開前
- 国家名、プロフィール、ログ、通報、凍結の最低運用を決める。

### D-08 複数World

- Status: Deferred
- Required before: MVP後
- Worldごとのruleset、season、終了・archiveを決める。MVP schemaでは同じUserがWorldごとに別Nationを持てる一意性境界を維持する。

## MVP後へ延期する機能

### C-02 turn更新通知

- Status: Deferred
- Required before: MVP後
- 最初の縦切りにはturn更新がない。pollingとWebSocketはturn実装時に比較する。

### C-04 低zoom集約

- Status: Deferred
- Required before: MVP後
- 世界規模とviewport計測後に集約tileの座標・cache契約を決める。

### E-01 地下

- Status: Deferred
- Required before: MVP後
- 地上との座標関係、portal、所有、可視性を決める。

### E-02 宇宙

- Status: Deferred
- Required before: MVP後
- hex平面かnode graphかを決める。

### E-03 追加資源

- Status: Decided
- Required before: Roadmap PR2
- catalogとbalance行を使い、`industrial_goods`と`minerals`を追加する。Nation固定columnは追加しない。農場・工場・採掘場のproduction definitionと売却方針だけを実装し、生産・消費・ledger・自動売却はturn runnerまでDeferredとする。
- Decision record: `docs/architecture/roadmap-pr2-systems.md`

### E-04 Modifier

- Status: Deferred
- Required before: MVP後
- 加算、乗算、上限、優先度、循環防止を決める。

### E-05 研究・熟練度

- Status: Deferred
- Required before: MVP後
- Nation、施設、command、Userのどこへ属するか決める。

### E-06 隕石itemと対象指定

- Status: Deferred
- Required before: MVP後
- cell、範囲、Nation、layer、eventへの対象契約を決める。

### E-07 Mariachang連携

- Status: Deferred
- Required before: MVP後
- 認証、データ境界、片方向参照、障害分離を決める。

### E-08 season

- Status: Deferred
- Required before: MVP後
- 座標、Nation、研究をどこまで持ち越すか決める。

### E-09 binary map API

- Status: Deferred
- Required before: MVP後
- JSON APIの測定結果を根拠に、compact array、binary、圧縮の必要性を判断する。

## MVPで維持する将来拡張境界

将来機能そのものは先行実装せず、次の境界だけをMVP設計で維持する。

- Worldが不変のruleset versionを参照できる。
- Nationは外部IDやranking順位ではない不変の`nation_id`を持つ。
- UserとNationを分離し、`(world_id, user_id)`をMVPの所有境界にできる。
- command queueを後から通常テーブルとして追加できる。
- turn処理を複数phaseへ分割できる。
- 構造化event logとnotification outboxを後から追加できる。
- terrain・facility定義が安定した`asset_key`を持てる。
- resource typeをcatalogから追加でき、固定カラムだけに依存しない。
- Userへprovider固有列を追加せず新しい`auth_identity`を関連付けられる。

「後から追加する場所が明確」であることを求め、MVP migrationやclassへ未実装機能の詳細を先行実装しない。

## 参考実装側の確認

参考実装の未確認挙動とprovenanceは新作のMVP設計判断と分離する。出典不明素材とやまにてぃ画像は使用しない。箱庭諸島2＋の原GIFは`docs/assets/tile-asset-mapping.md`の限定方針に従い、公開前に許可説明の適用範囲を再確認する。

## 決定記録の運用

設計判断を更新するときは次を残す。

1. 選択肢と採否理由。
2. 変更される不変条件、API、migration、test。
3. rulesetで変更可能か、code releaseが必要か。
4. 既存Worldへの移行方法。
5. 観測指標と見直し条件。
