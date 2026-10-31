# 全処理の高速化調査と実装一覧 / Performance study and implementation index

2026-10-31。調査・実装の基準 main は [`2e93ec918774ad64d787aba99fc59a7bc3e54d16`](https://github.com/Mamiki765/hakoniwa-world/commit/2e93ec918774ad64d787aba99fc59a7bc3e54d16)。main、open PR 全13件を実装前に確認した。本書は提案と検証の記録であり、本番適用や merge の承認ではない。

ゲームの意味、抽選確率、資産・進行の整合性と、同一コード版・初期状態・seedでの再現を維持する。将来ターンの乱数系列変更はOwnerが許容しているが、今回の実装は RNG / Ruleset / schema を変更しない。旧版との乱数消費順一致を以後の全最適化へ一律に要求するものではない。

## 追加: Turn本体の保存コスト

[保存経路の再調査・27run比較](turn-persistence-2026-10-31.md)を追加。優先は既存 #340（growth全Turn中央値3093→1815ms）、新規 [#359](https://github.com/Mamiki765/hakoniwa-world/pull/359) は成熟SQL3355→2245の独立追加案でCPU改善は未支持。実C1と既存6件+#340との結合回帰、地底候補の見送り、memory/sequence差も記録した。

## 読む順番

1. 下のPR表で実装内容・依存・CIを確認する。
2. [固定版の総合監査](audit-2026-10-31/README.md)で優先候補と全件カバレッジを見る。
3. [DB再設計の比較](audit-2026-10-31/DB-REDESIGN.md)と各分冊で、局所変更に収まらない案を評価する。
4. [全処理台帳](audit-2026-10-31/coverage-ledger.csv)、[未確認集計](audit-2026-10-31/unverified-counts.json)、各PRの検証文書で未実行と確認済みを区別する。

## 実装PRと依存順

| PR / 目的 | 固定head | 局所測定（全HTTP/Turn倍率ではない） | focused回帰 | 最終head CI・検証文書 | 依存 |
|---|---|---|---|---|---|
| [#357](https://github.com/Mamiki765/hakoniwa-world/pull/357) CQ-01 コマンド応答 | `86243ae01f7671b9dad9fd450591a04b68afaec8` | 同首都30計画のSQL104→17（Q1は17→17） | 既存15case/260assert＋SQL1/9 | [Quality成功](https://github.com/Mamiki765/hakoniwa-world/actions/runs/41207759219) / [個別記録](https://github.com/Mamiki765/hakoniwa-world/blob/86243ae01f7671b9dad9fd450591a04b68afaec8/product/docs/performance/command-response-batching-2026-10-31.md) | なし |
| [#353](https://github.com/Mamiki765/hakoniwa-world/pull/353) FND-02/T01 秘書item定義 | `c33263dc018a4611d1973e8be327cdcd4835ac5e` | 検証済み効果catalog500回中央値1325.442→122.639ms、hash一致 | Unit19/64＋gameplay代表5 | [Quality成功](https://github.com/Mamiki765/hakoniwa-world/actions/runs/41207234143) / [個別記録](https://github.com/Mamiki765/hakoniwa-world/blob/c33263dc018a4611d1973e8be327cdcd4835ac5e/product/docs/performance/secretary-item-catalog-2026-10-31.md) | なし |
| [#356](https://github.com/Mamiki765/hakoniwa-world/pull/356) BU-01 地底catalog | `7aa89e1058416da5b7cc3b65b65a7cb3816deb83` | 構築数16/32/32/128→1/2/2/2、hash一致。file read維持 | 12tests/137assert | [Quality成功](https://github.com/Mamiki765/hakoniwa-world/actions/runs/41207563209) / [個別記録](https://github.com/Mamiki765/hakoniwa-world/blob/7aa89e1058416da5b7cc3b65b65a7cb3816deb83/product/docs/performance/underground-catalog-reuse.md) | なし |
| [#352](https://github.com/Mamiki765/hakoniwa-world/pull/352) CQ-02 bulk割当 | `bc285270183123ea76e89a03462f7d38dfcdb37e` | 1万候補の計画保持メモリ8,055,488→25,456B | 6tests/89assert＋純粋12比較 | [Quality成功](https://github.com/Mamiki765/hakoniwa-world/actions/runs/41207185330) / [個別記録](https://github.com/Mamiki765/hakoniwa-world/blob/bc285270183123ea76e89a03462f7d38dfcdb37e/product/docs/performance/bulk-command-planning.md) | なし |
| [#355](https://github.com/Mamiki765/hakoniwa-world/pull/355) T02 海底消防 | `69a5a038a21b7b2219d9ae87f125e8e0cb6c564e` | 9216cell候補選択20回中央値7429.29→5.52ms | 9tests/62assert＋baseline代表1/12 | [Quality成功](https://github.com/Mamiki765/hakoniwa-world/actions/runs/41207516436) / [個別記録](https://github.com/Mamiki765/hakoniwa-world/blob/69a5a038a21b7b2219d9ae87f125e8e0cb6c564e/product/docs/performance/undersea-fire-station-neighbors.md) | なし |
| [#354](https://github.com/Mamiki765/hakoniwa-world/pull/354) AP-02 Boost管理 | `60d65c66f5ee0b6798bc68351201aa233b04a3f8` | 1series/3開催回のSQL7→3 | 既存2tests/61assert＋SQL1/4 | [Quality成功](https://github.com/Mamiki765/hakoniwa-world/actions/runs/41207464656) / [個別記録](https://github.com/Mamiki765/hakoniwa-world/blob/60d65c66f5ee0b6798bc68351201aa233b04a3f8/product/docs/performance/boost-series-projection.md) | なし |
| [#359](https://github.com/Mamiki765/hakoniwa-world/pull/359) Turn監査rowまとめ書き | `43736136ea4d094fbdf1596be82316c20f3d3eab` | mature全SQL3355→2245、CPUほぼ不変、phase peak増分+4MiB | 新規3テスト、実C1、限定結合回帰 | [Quality成功](https://github.com/Mamiki765/hakoniwa-world/actions/runs/41214204802) / [総合追補](turn-persistence-2026-10-31.md) | なし。既存#340が先行推奨 |

表内7件はdraft・未merge。[CI snapshotと完全head一覧](implementation-status.json)。文書PR自身のheadとCIは当該PRのChecksで確認する。

[固定headの独立レビュー記録](implementation-reviews.md)では、各確認範囲で追加P0/P1/P2なし。

各変更は基準mainから独立している。[6 patchの順次適用確認](implementation-evidence/integration-patch-check.json)は、使い捨てのdetached worktreeで成功した（merge/commit/pushなし）。その後の[限定した結合runtime検証](integration-2026-10-31.md)では、Turn・横断実行・worker scopeを確認した。全suite/本番の実行保証ではない。既存 #340 → #342 → #343 のTurn書込/マス取得/領地感化スタックは今回再実装しない。#345 stage共通化、#347覚醒projection、#350取得helperも既存提案として区別する。海底消防は既存 #344 と同じファイルを使うが変更箇所が異なることを固定patchで確認する。未mergeの組合せ全体が本番で検証済みという意味ではない。

ターン保存は[追補の順位](turn-persistence-2026-10-31.md)を優先し、既存#340、新規#359の順で採用価値を判断する。初期6件の確認順はコマンド応答 → 秘書item定義 → 地底catalog → bulk割当 → 海底消防 → Boost管理。独立性があるので必要なものから選べる。原本レポートの旧順位や「未採用」は固定時点の記録で、本PR表が今回の進捗を上書きする。

## 追加の結合runtime検証

[結合検証レポート](integration-2026-10-31.md)に、同じfixture/seedの基準・統合比較、84表のTurn状態、コマンド→Turn→実地底戦闘の25表、worker scope、集約チェック、観測できなかった全Turn高速化、JSON member順差と残る限界を追記した。実装6headは不変。

## 効果の読み方と試験環境

PHP 8.5.8、現行 composer.lock から別途installしたvendor、PostgreSQL18.4の隔離環境。対象Dockerfileが指定するPHP8.5.12とは異なる。各担当ごとにinternal network / tmpfs DBを作り、host portを公開せず、製品の.env、production DB・既存volumeを使わない。sourceは専用worktreeの必要directoryをreadonly参照し、cache/storageは別copy。初回にmanual索引・phpstan設定の不足が分かった箇所は環境を補完して必要なcaseだけ再確認した。level0のPHPStan実行をlevel6の成功根拠に数えない。

SQL数・オブジェクト構築数・保持メモリは局所効果の証拠になる。短い共有ホストのCPU時間はcold/warm差を含み、本番のHTTP p95や全Turnの改善倍率へ換算しない。正当な設定変更を塞ぐ固定期待値や、旧実装を写した製品testは追加しない。既存代表の必要な境界を拡張し、全件回帰は各最終headのCIへ委ねる。CIの新規手動起動や失敗runのmanual retryは行わない。

## 全件調査の範囲と限界

基準版の758 source file（app PHP526）、名前付きメソッド3,751、アプリHTTP入口179（全route182の残3はframework）、Console23、公式Turn12phase、計画送信9入口・31処理群、地底戦闘/地下施設37群、DB96tableを目録と照合した。[source hash](audit-2026-10-31/source-census.csv)と固定SHAのsource linkから対象版を追える。「地下」はNation所有の首都地下施設、「地底」はSecretary所有RPGとして別に扱い、双方を対象にした。

統合台帳1,057行はファイル・処理・担当を重ねた目録で、独立した実行経路1,057本ではない。確認区分はstatic1,055、delegated1、unconfirmed1。結果欄「未確認」は2行で、Turnへの委譲1行とDB実行計画/通信負荷1行。限界欄に「未」を含む1,014行もあるため、残りすべて実行済みという意味ではない。全分岐・全負荷条件の動的網羅は主張しない。今回の変更で検証が増えた範囲はPRごとの記録へ追加し、固定監査の原本を再生成して履歴を消さない。

基準版の成熟3600cell/1Nation合成Turnは3977.031ms/3355 SQL、process_cells3096.320ms/3143 SQLだった。これは成長・stage遷移の多い人工fixtureで、今回の6変更を統合した全Turn測定ではない。初期監査の#340推定と、追補で新たに行った27runの実測は区別する。

[今回の検証生データ](implementation-evidence/)と[測定器・ログ一式](implementation-evidence-20261031.zip)も保存した。初回の環境不足・level0 logを成功根拠から除外し、最終focused結果と区別する。

## 追加検討と見送り

| 対象 | 今回の判断と次に必要な証拠 |
|---|---|
| bulk浅瀬候補のSQL集合化 | 不要wrapperのみ実装。全浅瀬lockと候補ごとの近接EXISTSは残る。先頭30件打切りは候補数/token/pairを変えるため不採用。 |
| 地底装備snapshot・AI再選択 | catalog改善と分離。追加3pairでは2SELECT削減のみ確認し安定latency改善は未支持、今回は見送り。装備mutation/覚醒/free-action/boss等は[追補](turn-persistence-2026-10-31.md)の未確認に残す。 |
| Turn capacityの丸ごとcache | 建設・災害・技能等で同Turn中に入力が変わるため一律cacheは不採用。定数由来item定義だけ再利用する。 |
| SurfaceShipCatalog / asset解決cache | Ruleset・file・config更新のinvalidationと船を含むchunkのprofileが必要。既存options/船一覧memoを重複提案しない。 |
| 市場SQLページング | 全active取得は残存。snapshot由来のquery列・型/NULL/欠損・sort/id tieを比較する隔離proofを別記し、実移行は行わない。 |
| chunk version更新 / 食料残高 / 油田再読取 | 局所SQL候補として残す。現行のlock順、missing row作成、trigger/observer、affected件数を先に検証。 |
| ログmetadata / ranking / 図鑑 / receipt purge | 各分冊の残存候補。特にreceipt保持条件とprefix進行を壊す一括化は採用しない。 |
| UI地底lazy import | 隔離buildで初期entry gzip36.2%減。direct URL、初回待機/失敗状態、実ブラウザ測定が先。 |
| RNG native engine | 100k drawの局所効果はあるが全Turnは通常数ms規模。series再現/version境界の検証費用に対し今回は低順位。 |
| phase並列化・怪獣を1回だけ動かす | 残高や占領等の途中観測、後続セルでの怪獣再行動を変えるので今回の意味保存条件では不採用。 |

市場の追加[隔離proof](implementation-evidence/boost/additional-api-proof.md)では96合成rowの9代表filter/sort/pageでID順とhas_moreが一致した。現行83active row読込に対しquery列からpage_size+1を取る案は最大21rowだった。全市場API、実データshape、購入/取消競合、index plan、移行lockは未検証で、今回のruntimeへ採用していない。

## DBを作り直す場合の比較

原本の訂正注記: `DB-REDESIGN.md` のqueue fence測定代表に残る「Pd即時実行」は現行のqueue経路には存在しない。現行addの**日課Pd付与・共有Userの競合**を確認する意味に読み替える。同原本内の説明および実コードを優先し、原本ZIPのbytesは変更しない。

[総合比較](audit-2026-10-31/DB-REDESIGN.md)、[Turn](audit-2026-10-31/db-design-turn.md)、[command](audit-2026-10-31/db-design-command.md)、[地底戦闘](audit-2026-10-31/db-design-battle.md)、[API/履歴](audit-2026-10-31/db-design-api.md)に実コードの根拠と対案を残した。既存tableを所与として局所化するだけの調査ではない。

| 段階 | 適用価値と複雑さ | 整合性・運用・測定条件 |
|---|---|---|
| 小改修 | 重複read/save削減、列を絞る、SQL page、dirty列単位のwrite-set。今回の独立PRもこの段階。 | 現canonical typed rowと単一TXを保つ。SQL数/実際の更新列/trigger/eventを比較。 |
| 段階再設計 | 同期Nation summary・可視性/ランキングread model、市場query列、明示Turn UnitOfWork / flush barrier。大きな期待はあるが全writerを追う必要。 | canonical revision、read-your-writes、Turn外更新、private freshnessを保ち、同一TXのshadow結果で比較。 |
| queue競合緩和 | 限定編集だけWorld共有fence+Nation/queue排他を比較。異Nation同時編集に価値があるかlock waitを測る。 | addは日課Pd、bulkは共通浅瀬lockあり、一律FOR SHAREへ置換しない。全writer/lock upgrade/deadlockを検証。 |
| 戦闘のlock外CPU/CAS | profile lock待ちが主因なら比較価値。全入力revision/時刻/boost/lease/借用状態をfreezeし最終TXで検証するので複雑。 | receipt採番は最終profile lock取得後。低IDの遅延commitは既存rollup prefixを壊す。updated_atだけをrevisionにしない。 |
| canonical chunk JSONB / 世代snapshot | 文数を減らせるが23列typed cellをまとめる利益は未証明。chunk全行lock/JSON書換/FK/集計/診断再設計が重い。 | まず表示用chunk projectionを試す。WAL/HOT/row幅/lock wait/再試行時間/DB CPUを比較し、文数だけで採用しない。 |
| table分割・履歴partition | 大payloadとidentity coreの分離は規模次第。TOASTがあり単なる縦分割の利益は自明でない。 | admin request/global unique、party/battle identity、receipt FKを維持。単純な月分割はglobal uniqueを壊す。 |
| event-only / wallet統合 / 分散TX | table数削減・流行だけでは採用価値なし。現auditはcompactされ完全再生journalでもない。 | owner/保持/ledgerが異なる資産を汎用JSON財布にまとめない。部分commit/非同期決算でゲーム意味を変えない。 |

将来の移行はexpand → 旧canonicalを正としてID/revision付きbackfill → 同一TXのmirror/shadow比較 → 境界を止めてcutover → 互換期間後contractの順で設計する。retry/idempotency/auditを含め、rollbackは新規資産を保持できる形式だけ。新規状態を捨てる古いbackup復元はrollback案にしない。今回はmigration作成・既存DB変更とも行わない。

## 原本保全と納品

完全原本は[証拠ZIP](audit-2026-10-31/hakoniwa-performance-audit-20261031.zip)（79file、465,072 B）。SHA-256は `91cafb691502aa157287dd899c03b5d92edfe1286472c8dbd7993be5fff6479b`、README原本は `223ef2cbc8c1105ab643cc14a04be124e04f6de6a9455063fef594e05ebbc08e`。このrepositoryには原本のMarkdown/CSV/JSON/ログも閲覧用にコピーした（API分冊の実行可能測定器2本へのリンクのみ、閲覧用 `.txt` へ変更）。閲覧用CSVはrepositoryのtext正規化により改行がLFとなり、閲覧用ログは末尾空白だけを除いた。PHP/Python/JSの測定器を含む完全なbytes/manifest照合はZIPを展開したdirectoryで行う。保存したコードsnapshotを現行runtimeへ導入するものではない。

Library添付は正規helperのhosted tools/list段階でnetwork errorとなりprepare/finalize未実行、library_file_idなし。本人が指定したrepository documentationとしての納品・PR作成は別経路の許可済み作業であり、この障害で止めない。認証・ネットワーク・セキュリティ設定は変更していない。merge / approve / auto-merge / 本番反映 / manual retry / SSH復旧は行わない。

## English

This index records the fixed-main performance audit, seven independent draft optimization PRs, before/after evidence, regression checks and remaining work. The turn-persistence follow-up prioritizes existing PR #340 and adds audit batching in #359, with explicit CPU/memory limits and real C1 rollback evidence. The audit inventories 758 source files, 179 application HTTP entry points, 23 console commands, all 12 official-turn phases and 96 tables. Static coverage is distinguished from executed tests; it does not claim every branch or production workload was exercised.

The implementation preserves gameplay, probabilities, assets and reproducibility for the same code/state/seed. Future RNG-series changes are permitted by the owner, but these PRs do not change RNG, rulesets or schemas. They are independent of the existing turn-write optimization stack. Local CPU/memory/query reductions are not converted into production HTTP or whole-turn speedup promises.

Database alternatives include synchronous read models, explicit turn write sets, selective queue fencing, combat input revisions/CAS, table/payload splitting, query columns and chunk snapshots. Each is compared against consistency, migration, rollback and operational cost; no production migration is performed. The complete original evidence is preserved in the linked ZIP. Library upload remains blocked before prepare/finalize, while authorized repository documentation and draft PR delivery proceed normally.
