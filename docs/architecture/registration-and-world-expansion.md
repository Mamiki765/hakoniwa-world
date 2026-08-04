# 国家登録と世界拡張

## 状態

MVPではサーバーによる自動配置を採用する。利用者の座標直接指定と3候補提示UIはMVP後の改善候補とする。初期Territoryの暫定ruleset値はCapitalからx/y grid distance 2以内、Capital間最低距離は12である。

## 目的

共有世界へ国家と首都を安全・公平に配置する。箱庭諸島2＋の利用者指定中心と固定世界、やまにてぃの独立島ランダム生成はそのまま採らず、空き領域探索と必要時拡張を1つの原子的処理にする。

## 前提

- 利用者は認証済みの内部Userを持つが、同じWorldにNationをまだ持たない。
- 地上map_spaceはx=0..59、y=0..59の60×60で開始し、既存のx、yを変えずにmin_x、max_x、min_y、max_yを拡張できる。
- Nation、Capital、初期Territoryは全て作成されるか、全て作成されない。
- 登録中にターンが開始しても半端な国家を読ませない。
- Nationのownerは内部`user_id`で表し、Discord ID、Google ID、メールアドレスを使わない。
- MVPは同じWorldで1 Userにつき最大1 Nationとする。将来別Worldで別Nationを持てるよう、system全体の`user_id`一意制約にはしない。

## 登録フロー

1. 入力を検証する。Nation名、表示設定、参加World、利用規約同意など。
2. `(user_id, world_id)`とclient request IDに対応するregistration requestまたは同等の冪等keyを確保する。
3. Worldのregistration lockを取得し、同じUserが同じWorldにNationを持たないことと、Worldの状態を再確認する。
4. 現在の地上生成境界内から、ruleset条件を満たすCapital候補をサーバーが探索する。
5. 候補がなければ、`chunk_size = 16`に整列した必要最小のWorld拡張計画を作る。
6. 拡張後の地形を決定的seedで生成し、候補を再探索する。
7. 候補をtransaction内で予約またはlockし、距離・Territory非重複・地形条件を再検証する。
8. Nation、Capital、初期Territoryを作る。
9. transaction確定後、初期画面をCapital周辺へ案内する。

同じ冪等keyの再送は、作成済みNationを返す。途中失敗時にNationだけ、Capitalだけ、Territoryだけ、予約だけが残らないようにする。MVPでは構造化event tableを先行作成しないが、このApplication serviceから将来`NationRegistered`と`ChunksExpanded`を同じtransactionへ追加できる境界を維持する。

## 候補地の条件

最低条件の候補は次の通り。

- 首都セルが建設可能な地形、または安全に造成可能。
- 初期Territoryの候補範囲が他国Territoryと重ならない。
- 他国Capitalから`capital_min_distance = 12`以上。
- 他国所有地、予約地、禁止地、イベント専用地と重ならない。
- 世界境界からregistration_clearance_radius以上。満たさない場合は拡張候補。
- Capital周囲に最低限の発展可能地がある。

距離はADR-0003のHexCoordinate.distanceToを使う。四角い配列上のEuclidean距離、odd-qのcolumn・row、UI pixel距離で代用しない。

Capitalを建設可能とする地形、初期Territoryへ水域・建設不能地形を含めるか、最低限の発展可能セル数、同点候補のscoreは国家作成実装前に決める。

## 探索方式の候補

| 方式 | 利点 | 問題 |
|---|---|---|
| 全候補から一様抽選 | 分かりやすい | 世界拡大後の全走査が重い |
| 空きregion索引 | 高速 | 索引更新と断片化管理が必要 |
| 外周ringを順次探索 | 拡張方向と相性がよい | 中央近くの再利用空地を逃しやすい |
| 複数候補をsampleしてscore | 距離・資源・公平性を調整可能 | scoreが不透明だと不公平感が出る |

MVPは、chunkごとの登録可能セル数から候補を絞り、複数候補をseed付きで評価し、地形・既存Capital距離・周辺余地をscoreしてサーバーが1地点を選ぶ。最終score定義とseed contractは国家作成実装前に決め、選択理由を監査可能にする。

利用者による座標直接指定は行わない。候補を3件提示して選ばせる案は体験を改善するが、予約競合、放置予約、資源比較による最適化問題が増えるためMVP後へ延期する。

## 初期領土と地形

初期TerritoryのMVP既定値は、Capital cellとCapitalからx/y grid distance 2以内の最大19セル相当とする。`territory_initial_radius = 2`としてruleset versionへ置き、確定balance値とは扱わない。他国Territoryと重ねず、別の新規Nationの初期Territoryとも重ねない。

すべてを同一地形へ上書きせず、生成済み地形を基礎にする。水域や建設不能地形をTerritoryへ含めるか、範囲外へはみ出す候補を失格またはWorld拡張対象にするか、最低限の発展可能セル数は国家作成実装前に決める。

初期内容はrulesetへ置く。

- 首都人口と最低保証値。
- 初期領土の形とセル数。
- Capitalの初期状態。
- 将来の初期資金、食料、基本resource。
- 将来の初期施設とlevel。
- 新規保護turn数。
- 周辺の海・山・資源の最低または最大条件。

箱庭諸島2＋の村2、森3、山1、ミサイル基地1や、やまにてぃの資産値は参考挙動として比較するだけで、初期値として確定しない。

MVP縦切りでは生産・消費や追加resourceを実装しない。将来のresource種を固定columnだけに閉じず、catalogと版付きrulesetから追加できる境界を維持する。

## 拡張計画

候補不足時は、現境界の各方向について追加chunk数、生成セル数、最寄りNationとの距離、陸海比、将来の余白を評価する。最小セル列ではなく、辺長16の最小chunk整列矩形を拡張単位とする。

選択規則の候補は次の通り。

- 最少追加チャンクを優先。
- 世界中心からの偏りが小さい方向を優先。
- 直近の拡張方向を避けて均等化。
- seed付き同点抽選。

どの規則も既存座標を変えない。min_x、max_x、min_y、max_yの必要な境界だけをチャンク単位で広げ、既存セルのx、yは更新しない。候補探索、地形生成、所有権確定は同じcanonical x/yを使い、pixel座標を入力にしない。

## 初期範囲と探索中心

x=0..59、y=0..59を採用する。初期worldは論理的な長方形で、全rowが同じ60セルを持つ。登録地点は原点に近い順ではなく、既存首都距離、登録可能セル数、地形、将来余白をscoreして選ぶ。

UIは首都と各cellのabsolute x/yをpixelへ投影し、その差で中心表示する。首都相対yのparityは使わない。

## 同時実行とターン境界

registrationとturnが同じworldを更新するため、ロック階層を統一する。簡明な初期案はworld lockを共有し、登録かturnの片方だけを確定させることである。登録受付締切を次ターン直前に置き、待ち時間をUIへ表示する。

登録処理同士も同じ候補を取らないよう、候補予約だけでなく最終transactionで所有・距離を再確認する。deadlock時は冪等キーを保ったまま有限回再試行する。

## 配置失敗

次を区別する。

- validation failure: 名前、資格、既存nation。
- temporarily unavailable: turn実行中、lock timeout。
- capacity policy: 設定上の最大拡張や定員。
- generation failure: 地形生成や制約の不整合。
- infrastructure failure: DB等。

空きがないだけで即失敗せず、許可範囲内の拡張を試す。無限拡張はせず、1登録あたりの最大追加chunkと管理者警告を設定する。

## 不正利用対策

- 1 userあたりのnation数と再登録cooldown。
- 名前の正規化・禁止語・なりすまし対策。
- 候補APIから未公開資源を推測できる情報を制限。
- 登録連打にrate limitと冪等キー。
- 外部認証identityの一意性と退会後の扱い。

## 再入植との境界

sunken_archivedの国家は旧領土・旧首都を地図へ巻き戻さない。本人が後日復帰する場合は、新規登録と共通の空き領域探索・世界拡張を利用する再入植を基本候補とする。ただし旧国家名、初期資源、ランキング、称号、新規保護期間、同じnation_idを再利用するかは未決定である。

## 未決定事項

- Status: Open / Required before: 国家作成実装前 — Capital建設可能地形、水域・建設不能地形のTerritory所有、最低発展可能セル数、候補score、生成seed。
- Status: Open / Required before: 国家作成実装前 — 1登録あたりの最大拡張chunk数と同点の拡張方向選択。
- Status: Open / Required before: コマンド実装前 — 新規保護turn数と対象行為。
- Status: Open / Required before: ターン処理実装前 — turn直前登録を当該turnへ含めるか。
- Status: Deferred / Required before: MVP後 — 3候補提示UIと予約期限。

## MVP実装記録（2026-07-26）

国家作成はWorld row lockとworld単位のPostgreSQL transaction advisory lockで直列化する。要求予約、候補選定、Nation、初期資源、島、Capital、Territory、Membership、audit eventを1 transactionに含め、例外時は全てrollbackする。

PR19では登録入力に公開用の`owner_name`（必須、1–30文字）と`comment`（任意、0–100文字）を追加する。どちらも1行のplain textとして保存し、制御文字・改行・Unicode line/paragraph separatorを拒否する。前後のUnicode space separatorは除去するが、HTMLやURLを解釈・展開しない。OAuthの表示名、provider ID、emailから島主名を暗黙補完しない。

登録後はNation ownerだけが`PATCH /api/v1/nations/{nation}/profile`で島主名と一言コメントを変更できる。変更はWorldとNationをlockし、最新ruleset Worldだけを対象にして、変更前後・変更field・actor user IDを`nation.profile_updated`へ記録する。同値更新は保存もaudit eventも作らない。過去ruleset Worldの更新は`reset_required`で拒否する。

候補は中心からdistance 5以内の91セルが生成済みの海・無所有・施設なしで、他Capitalから12以上離れる地点だけとする。最も近い既存Capitalまでの距離を最大化し、y/xで安定tie-breakする。現在は先頭候補を使用するが、serviceの結果を上位3候補へ拡張できる。初期範囲に候補がない場合の自動拡張はMVP外である。
- Status: Deferred / Required before: MVP後 — 放棄Territory再利用、World運用上限と新World作成、sunken_archivedからの再入植。
