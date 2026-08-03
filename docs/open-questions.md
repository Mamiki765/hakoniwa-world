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
- x、yをそれぞれ16単位で区切る。負座標では数学的な`floorDiv`と`floorMod`を使う。
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
- Decision: 構造はruleset値とし、MVP既定値は初期領土がCapitalからx/y grid distance 2以内、首都間最低距離が12。
- Decision record: `docs/architecture/capital-and-territory.md`、`docs/architecture/registration-and-world-expansion.md`
- これは確定バランスではない。既存worldが参照するruleset versionを変えずに値を上書きせず、新しい版で見直す。

### A-09 rulesetのMVP境界

- Status: Decided
- Decision: 各Worldは不変の`ruleset_version_id`を参照する。一度公開したruleset row、settings、そのrulesetに属するcommand definitionsおよびproduction definitionsをinitializerや通常application codeから更新しない。変更はsettings全体と関連定義を持つ新しい一意なkey/versionとして公開し、同じkeyの完全一致だけを冪等に再利用する。不一致は例外で停止する。既存Worldの移行は対象Worldと旧ruleset IDを限定したdata-preserving migrationで行い、initializerは既存Worldを自動移行しない。Roadmap PR6は`roadmap-pr2-v1`を変更せず`roadmap-pr6-v1`を公開し、forward-only migrationで`shared-world`だけを明示的に移す。
- Pre-release decision: 現在は本公開前の開発期間であり、更新ごとに開発用Worldをresetしてよい。resetではNation、cell、command queue、TurnRunを含む開発データを破棄してよい。公開済みruleset row、settings、command definitions、production definitionsは監査用の不変recordとして維持し、payloadを直接書き換えない。
- Pre-release runtime boundary: runtimeで保証するのは最新のactive rulesetだけとする。過去ruleset上のWorldを継続実行できる後方互換性、過去ruleset用fallback、過去rulesetでのTurnRunner実行test、failed/pending runのruleset更新後retry互換は、repository ownerが個別に要求しない限り追加しない。これは公開payloadのimmutability契約を弱めず、A-09のmigration境界を破壊しない期間限定の運用decisionである。
- Pre-release read boundary: 過去rulesetを参照するWorldの地図、audit、TurnRun、ruleset snapshotはread-onlyで閲覧できる。game state mutationは、既にロードしたWorldの`ruleset_version_id`とcurrent ruleset IDを比較する共通`CurrentRulesetGuard`で`reset_required`として拒否する。`ruleset_versions.is_active`の意味は変更しない。
- Pre-release cleanup plan: PR17ではschemaを変更せずruntime fallbackだけを削除する。migration chain、historical ruleset publication、current validatorとhistorical audit verificationの分離は変更せず、PR19完了後かつ正式公開前のPR20でcanonical schema rebaselineとして扱う。
- Required before: 本公開準備へ移行する前に、正式なdata migration方針、runtime後方互換性範囲、failed/pending runの扱いを改めて決定する。
- Decision record: `docs/architecture/configuration-management.md`、`docs/architecture/target-architecture.md`
- `chunk_size = 16`と座標方式は既存worldの互換性に関わるarchitecture invariantであり、通常のバランス設定として変更しない。
- global catalogはinitializerが欠損rowだけを作成する。既存rowとconfigの不一致は上書きせず、明示的migrationを要求する。

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
- Decision: 地上の初期生成範囲は`x = 0..59`、`y = 0..59`の3,600セル。各yに60セルを持つ。
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
- compact array、binary、独自圧縮はMVP後へ延期する。APIとDBはcanonical x/yを使い、各chunk responseもabsolute x/yを返す。

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
- Decision: 中心からdistance 5以内の91セルが全て生成済みの海・無所有・施設なしであること、Capital間距離12以上を必須とする。既存Capitalから最も遠い候補を優先し、y、x昇順で安定tie-breakする。

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

- Status: Decided
- Required before: ターン処理実装前
- Decision: `development_commands`では、各turnにつきNationのcommand処理順を1回ランダム化し、その順序で逐次処理する。`process_cells`では、全surface cellの処理順を各turnにつき独立して1回ランダム化し、その順序で逐次処理する。先に成立したgame-state changeは、同じturn内の後続処理から観測される。economyその他のphaseまで一律にNation shuffleするとは決めず、Hakoniwa Islands 2+で確認したphase固有の順序を維持する。monster、missile、territory influenceその他のcross-border effectも、Hakoniwa Islands 2+で確認した因果順を維持する。完全なsimultaneous resolutionを暗黙に導入しない。
- Decision record: `docs/reference-analysis/hakoniwa-2plus-turn-processing.md`

### A-07 1ターンのtransaction規模

- Status: Decided
- Required before: ターン処理実装前
- Decision: 同じWorldの1ターンは1つのPostgreSQL transactionで処理し、ゲーム状態の全phaseと`current_turn`更新を含める。全phase成功時だけcommitし、途中失敗時はそのターンのゲーム状態をすべてrollbackする。World単位のadvisory lockをturn実行全体で保持する。`turn_runs`の開始・失敗記録はゲーム状態transactionから分離して監査可能にする。transaction内では外部HTTP通信、通知送信、長時間の外部I/Oを行わず、notification等はcommit後の別境界とする。phaseごとの処理時間を`turn_runs.phase_results`へ記録する。実command、全cell処理、災害等を追加後にlock時間を計測し、実運用で許容できない長時間transactionになった場合だけcheckpoint方式を別ADRで再検討する。現時点では部分commitやphase checkpointを実装しない。
- Decision record: `docs/architecture/turn-runner-scaffold.md`、`docs/architecture/turn-pipeline.md`

### POP-01 population random rangeのcanonical化

- Status: Decided
- Required before: population増減処理実装前
- Decision: legacyで100人単位として定義されたrandom population rangeは、canonical 1人単位のinteger rangeへ展開する。例としてlegacy 1..30 unitsはcanonical 100..3,000人とする。legacyと同じminimum、maximum、expected valueを維持する。100人刻みの離散分布そのものに明確なgameplay上の意味が確認された場合は、その個別処理だけsource analysisに基づいて例外を記録する。

### B-09 災害抽選単位

- Status: Decided
- Required before: 各disaster handler実装前
- Decision: 地震、津波、台風、流星群、巨大隕石、噴火は各World・各turnにそれぞれ1回発生判定する。地ならし即時地震はsuccessful `land_level` commandごと、火災・飢餓暴動・海底油田稼働はrandomized `process_cells`の対象cellごとに判定する。chunkはstorage/API boundaryだけに用い、抽選母集団・抽選回数へ使わない。
- Decision: 1時間turnの初期balanceとして、legacyの`n / 1000`である6 global disaster trigger、successful `land_level`ごとの5/1000、各対象cellの火災10/1000だけを、整数丸めせずそれぞれ`n / 2000`とする。油田枯渇40/1000、飢餓暴動1/4、災害発生後のcell別被害率、流星群の1/2継続、津波・台風等の内部確率、範囲・対象・被害内容は半減しない。
- Decision record: `product/docs/disaster-oil-audit-pr15.md`、`docs/reference-analysis/hakoniwa-2plus-turn-processing.md`

### T-01 乱数seedと再現方式

- Status: Decided
- Required before: ターン処理実装前
- Decision: `turn_runs.random_seed`に保存するprivateな256-bit master seedを正本とし、プレイヤーへturn実行前に公開しない。同じfailedまたはblocked runのretryは同じseedを再利用し、新しいtarget turnだけが新しいseedを生成する。
- Decision: 固定version文字列を含むHMAC-SHA-256で用途labelごとにcounter-based streamを派生する。Nationは不変ID昇順、surface cellはmap space ID・canonical x/y・cell ID順をstable inputとし、それぞれ専用streamで1 turnに1回だけdeterministic Fisher-Yates shuffleする。
- Decision: inclusive bounded integer drawはfloatやglobal RNGを使わず、32-bit unsigned wordに対するrejection samplingでmodulo biasを避ける。別labelは独立しており、あるstreamへのdraw追加は他streamを変えない。
- Decision: random call log全件は保存しない。master seed、versioned derivation contract、stable input、ruleset/input state、phase resultsをretry整合性と障害調査の境界とする。
- Decision record: `docs/architecture/turn-randomness.md`

### T-02 休眠状態遷移Job

- Status: Open
- Required before: 休眠状態遷移実装前
- ADR-0004の状態とUTC境界は決定済み。scheduler、world lock、turnとの直列化、batch checkpointは実装前に確定する。

### D-02 turn失敗時の再試行

- Status: Open
- Required before: ターン処理実装前
- 冪等性を保証した後、回数、backoff、手動再開条件を決める。
- PR #7 checkpoint: game stateをrollbackし、同じrun・target turn・ruleset・seedを明示的な手動再実行で再利用する。自動retry、backoff、stale-running回復は未決定。
- Owner direction: transient failureにはbounded automatic retryを導入する方向とする。retry上限後は`current_turn`を進めず保留状態にし、管理者へ「turn処理を再開できないため確認が必要」と通知する方向とする。exact retry count、backoff、retryable error分類、stale-running recovery、通知経路は未決定とする。

## コマンド実装前まで保留する事項

### A-08 コマンド件数・順序・予約

- Status: Decided
- Required before: コマンド実装前
- Decision: command queue limitはarchitecture invariantではなくruleset-configurableなgameplay valueとする。legacyおよび既存published rulesetは20、`roadmap-pr11-v1`は30とし、authoringではDB互換な整数1–168だけを許可する。runtime、backend、API、frontendはactive Worldの値を使い、20または30をhard-codeしない。positionは1始まりとし、追加・全件並べ替え・取消後の左詰めをtransactionで行う。header versionによるoptimistic concurrencyとrequest keyによる重複防止を使う。登録時に資金・資源を予約せず、turn runnerが実行時に再検証する。quantityは全command共通のfirst-class column/API fieldで、整数1–99、default 1、preset 1/5/10/25/50/99とする。明示的nullは422とし、実行時のdecrement・先頭保持・一括使用はcommand handlerが所有する。effective planはactive limit分の枠を返し、未使用枠をquantity nullかつ永続IDのないautomatic finance placeholderで補完する。
- Decision record: `docs/architecture/roadmap-pr2-systems.md`

### RES-01 food生産量のton換算

- Status: Decided
- Required before: ターン処理・production実装前
- Decision: legacy food storage 1 unitは100 tonsとしてcanonical tonへ変換する。legacy farm productionのscale 1あたり10 food unitsは、canonical 1,000 tonsとして扱う。food consumptionはlegacy balanceを維持し、population 1人あたり0.2 tonsとする。およそpopulationの20%がfarm capacityに収容されていれば、基本的な食料収支が釣り合う関係を維持する。integer calculationと丸め規則はsource analysisで確認したlegacyの非負整数切捨てを基準とする。
- Decision record: `docs/reference-analysis/hakoniwa-2plus-turn-processing.md`

### CMD-01 箱庭諸島2＋コマンドの採否

- Status: Decided
- Required before: コマンド実装前
- Decision: PR2では旧作sourceで確認した整地、地ならし、埋め立て、掘削、農場建設、工場建設、採掘場建設を別々のversioned definitionとして採用する。費用と施設scaleは旧作値を維持し、実行、副作用、乱数処理はturn runnerへ延期する。
- Decision record: `docs/architecture/roadmap-pr2-systems.md`

### CMD-02 地ならし由来の即時地震

- Status: Decided
- Required before: land_level earthquake side effect実装前
- Source-derived behavior: successful `land_level`ごとに5/1000をcommand実行時に即時抽選し、当選時は震源半径10内の人口10,000人以上の都市、工場、ハリボテをそれぞれ独立に1/4で荒地化する。invalid target、ownership failure、insufficient moneyでは抽選しない。通常のglobal earthquakeとは別処理であり、counter/modifier方式ではない。
- Decision: successful `land_level`だけが5/2000を即時抽選する。invalid target、ownership failure、insufficient money、command実行失敗では抽選しない。通常のglobal earthquakeから独立したversioned labelled streamを使い、当選時は同じcommand call内で発生event、半径10、対象ごと1/4被害まで完結させる。modifier、counter、global発生率加算、抽選だけの実装はしない。
- Decision: 人口10,000人以上のCapitalは通常都市と同じ対象判定へ参加する。当選時はfacility identity、owner、terrain、Nationのcapital coordinate、territory identityを維持し、人口をeventごとに10%減らして各event後にfloorと最低100人を適用する。player logは該当Nationに座標、災害種別、実被害だけを投影し、seed/raw draw/internal metadataを公開しない。
- Decision record: `product/docs/disaster-oil-audit-pr15.md`、`docs/reference-analysis/hakoniwa-2plus-turn-processing.md`

### B-16 settlement_seed

- Status: Decided
- Required before: settlement_seedまたはsettlement growth実装前
- Decision: randomized sequential cell processingで、所有者がいる人口0・施設なしの平地を候補とする。候補ごとに100面20未満を先に抽選し、その後、隣接6セルに農場または人口1人以上の集落が1つ以上あれば人口100人の村を発生させる。隣接施設・集落のowner一致は要求しない。先に発生した村は同じturnの後続cellから観測できる。
- Decision: villageは1–2,999人、townは3,000–9,999人、cityは10,000人以上とする。海際度24以上、12–23、0–11で通常人口上限をそれぞれ10,000、5,000、2,000人とし、通常成長のcanonical inclusive integer rangeをそれぞれ100–900、100–600、100–300人とする。上限を超えないようclampする。誘致中は通常上限未満で100–3,000、100–2,000、100–1,000人、通常上限到達後は100–300、100–200、100人ずつ20,000人まで成長する。
- Decision: 飢餓時は村発生・成長を行わず、各有人口集落をcanonical inclusive integer 100–3,000人だけ減少させ、0未満を0にする。人口0では施設stageを外して所有された平地へ戻す。CapitalはCapital identityを維持し、town/city facilityへ置換せず、同じ海際度bandの通常成長rangeと飢餓減少drawを適用する。Capitalは各飢餓event後に最低100人を維持し、通常成長は25,000人へclampする。attractionは今回追加しない。
- Decision: sea-edge contextはturn開始時点の海、海底基地、範囲外海から半径4へ加算した値をそのturnで固定して使う。PR #11はattractionを発生させるcommandを追加しないが、将来の誘致stateが同じcell processorへ接続できるruleset境界を保持する。
- Decision record: `docs/reference-analysis/hakoniwa-2plus-turn-processing.md`

### B-17 緊急農場

- Status: Decided
- Required before: コマンド実装前
- Decision: emergency farm commandはMVPへ導入しない。automatic financeによる資金10億円の増加と、explicit abandonment/recreationを立て直しの境界とする。将来の別rulesetで再検討する可能性までは禁止しない。

## 戦闘実装前まで保留する事項

### B-02 Capitalへの複数被害

- Status: Decided
- Required before: 戦闘実装前
- Decision: 災害・将来の戦闘damageはいずれもeventごとの逐次適用とする。同じturnの複数被害もA-06のrandomized sequential causalityに従い、各event開始時点の現在人口へ割合を適用し、`max(100, floor(old_population * (100 - damage_percent) / 100))`を各event後に確定する。turn合算や最後だけの丸め・最低人口適用は行わない。
- Decision: 通常cellが荒地化・施設消失となる被害は10%、1段階の掘削・浅瀬化相当は30%、深海化相当は90%、噴火中心の山化は30%とする。Capital facility identity、owner、terrain、Nationのcapital coordinate、territory identityは維持し、population、cell version、chunk invalidation、audit/player logだけを変更する。

### B-19 Capitalと将来の怪獣・戦闘damage

- Status: Decided
- Required before: 怪獣・戦闘実装前
- Decision: 怪獣はCapital cellへ侵入・踏み荒らしできず、移動候補からCapitalを除外する。通常・PPミサイル等の荒地化相当はCapital人口10%、地形破壊弾等の1段階掘削相当は30%、深海化相当は90%の逐次damageへ変換する。戦闘damageでもCapital identityと最低人口100人を維持する。
- Decision: 戦闘damageと怪獣移動のコードは別PRで実装する。災害・油田稼働PRではdecisionと拡張境界だけを記録し、怪獣・ミサイル・地形破壊弾の実行コードを追加しない。

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
- Owner direction: Nation orderに依存しないsimultaneous resolutionを前提にしない。legacyがrandom cell orderによる逐次処理なら、その因果順を採用する。exact territory influence algorithm、同値競合、tie handlingはsource調査待ちとする。

### MISSILE-01 launch intentと基地単位解決

- Status: Decided
- Required before: ミサイルcommand実装前
- Decision: missile commandは`development_commands`で即時発射しない。commandはturn-scopedなlaunch intentとして、発射Nation、missile type、target、要求発射数を登録する。実際の発射、費用支払、着弾解決は`process_cells`で行う。ランダム化されたcell順で各missile base cellの手番が来た時に、その基地が発射を試みる。各基地の発射数は、その基地自身のlevelを上限とする。さらに、launch intentの残り発射数、現在資金、射程、基地がその時点で存在し稼働していることによって制限する。発射前に破壊された基地は発射できない。すでに発射した基地が後から破壊されても、成立済みの発射は取り消さない。基地levelをturn開始時に合計してNation単位で一括発射する実装にはしない。利用可能な各基地のlevel合計は理論上の最大発射数になるが、実際の発射数と順序はcell processing中の状態に従う。exact missile type、accuracy、cost、range、experience、defense interception、public log payloadは別の既存gateまたはmissile実装前のgateで扱う。
- Decision record: `docs/reference-analysis/hakoniwa-2plus-turn-processing.md`

### B-10 ミサイル可視性

- Status: Open
- Required before: 戦闘実装前
- Owner direction: normal missileは発射Nationを公開する方向とし、ST missileは発射Nationを匿名とする方向とする。target、impact、damage、failure、internal random valuesのexact public/private event payloadはsource調査待ちとする。

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

- Status: Decided
- Required before: ターン処理実装前
- Decision: 当面はAsia/TokyoのOCI host cronを1時間ごとのthin triggerとし、`docker compose exec -T`で同じArtisan commandを呼ぶ。Web containerへcron daemonを同居させず、DB/application lockを正本、host `flock`を任意の一次filterとする。production登録は別の運用変更とする。
- Decision record: `docs/operations/turn-cron.md`

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
