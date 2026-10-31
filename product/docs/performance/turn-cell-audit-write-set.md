# process_cellsの監査rowを記録時snapshotでまとめる

基準main: `2e93ec918774ad64d787aba99fc59a7bc3e54d16`。独立したcode最適化で、schema・Ruleset・RNGは変更しない。既存 #340 (`8509bd5c3c6666db580c6fda2a9c7ce986316642`) のstage二重UPDATE/成長write-setは再実装しない。

## 変更と安全境界

`CompleteTurnEngine::execute('process_cells')`だけを`TurnEventRecorder::duringCellProcessing()`で囲む。既存の`row()`でmetadata JSON・subject ID・visibility/severity/message・各記録時点の時刻を即確定し、同じTurnContextのTurnStateへFIFOで保持する。別recorder instanceとrecordManyも同じstateを使い、1000row到達時とphase末にINSERTする。例外時はfinallyで未flush行を捨てる。外部状態やModel参照は保持せず、SQL文字列hookやphase並列化は追加しない。

開発コマンドは同transactionのauditを読み返すため対象外。他phase/WorldEventContextでは従来の即時保存を維持する。current process_cells到達経路にはaudit readback/ID取得・成功するrecorder外audit INSERTを見つけなかった。MonsterDropのinventory-fullは生成前に検査し、treasureもlock中の必要枠検査を通る。今後このphaseにaudit readerや独立writerを導入する場合は、その前にflushする境界の再検討が必要。

同一Turn内のevent順・件数・snapshot・記録時刻は保つ。通常の隔離比較はauditの数値IDも全一致した。一方、他transactionとのglobal sequence割込や失敗attemptで消費する未使用ID数まで旧版と一致することは保証しない。audit IDは外部のgameplay identityとして再採番/書換しない。C1 rollback後もWorld状態と監査内容/相対順を保持し、sequence gapは診断上の差として明記する。

## 比較条件

PHP8.5.8 / PG18.4 / 同じcomposer.lock vendor、CLI OPcache無効、専用internal networkとtmpfs DB、source readonly。main、main+#340固定patch、main+本変更の3条件を、mature3600 / growth3600 / protection1024の3fixtureで各3回、順位置を均等化した逐次Latin順で測った。重い他処理は止めたが、共有ホストの外部負荷は制御できない。全27runの同じ初期state/seed/clock/fixture recipeと最終stateを比較した。

[生比較JSON](evidence/turn-audit-write-comparison.json)、[全sample CSV](evidence/turn-audit-write-samples.csv)。fixtureは既存性能testと#340のgrowth recipeを外部copyで共通化。snapshotのauth/credential表・列を取得せず、残る91表はtimestampを含め保持し、phase計測durationだけを正規化した。全27runで入力・最終state・audit ID/順/metadata/時刻・全12phase metrics・記録draw・seed・依存が一致。同版各3回のreplayも一致した。

## 局所効果と全Turnの区別

以下は全Turn中央値。括弧は3回のmin–max（ms）。#340列は本変更とは別の既存提案。

| fixture | main SQL / wall | 本変更 SQL / wall | #340 SQL / wall |
|---|---|---|---|
| mature | 3355 / 3290.066 (3272.297–3483.816) | 2245 / 3118.641 (2705.691–3307.110) | 2455 / 2768.924 (2694.048–3360.902) |
| growth | 3138 / 3093.306 (3060.733–4017.685) | 2884 / 3019.768 (2856.822–3060.714) | 744 / 1815.176 (1679.670–1859.459) |
| protection | 696 / 762.597 (717.960–949.773) | 696 / 719.405 (716.945–728.873) | 186 / 460.179 (453.158–466.455) |

成熟fixtureのprocess_cellsは **3143→2033 SQL**、INSERTは **1113→3**（audit1112行が2batch、別用途1INSERT）、UPDATE2012/SELECT18は不変。全Turn3355→2245で1110往復を減らした。query.time合計の中央値はprocess_cells **1285.08→1017.05ms**。これはPHP QueryExecutedの経過時間合計で、PostgreSQL CPUそのものではない。

成熟の全Turn wall中央値は約5.2%下がるが範囲が重なり、PHP CPU中央値は2166.899→2160.345ms（約0.3%）で、CPU支配コストの改善は支持されない。process_cellsのpeak増分はallocator単位の観測で4→8MiBとなり、1000row保持のmemory負担がある。絶対上限や本番値ではない。

growthでは監査255行が1batchとなり、全SQL3138→2884。一方protectionは監査が少なくSQL696で同じなので、見かけのwall差を本変更の効果に数えない。**優先は既存#340**。growthの全Turn中央値3093.306→1815.176ms、CPU2115.387→1331.519ms、SQL3138→744で、保存コストへの効果が大きい。本変更はその代替ではなく、監査の多いTurnのSQL往復を減らす追加候補。

## 回帰・原子性・組合せ

- 新規3代表は計54 assertions成功（別focused runの合算）。別recorder/recordMany、記録後のmetadata変更、時刻を進めてからflush、1000境界/tail、flush済1000行＋tailのrollbackと再利用を確認。初回の期待値のJSONB key順/default severity誤りはfixtureだけ訂正し、失敗logを保全。
- 正規Larastan level6でruntime3filesエラーなし、変更PHPのPint成功。全体回帰は最終headのCIを正本にする。
- 実C1・既存6件と#340との組合せの詳細証拠は文書PR #358へ集約する。下記結果は同じsource bytesで追加確認した。

- [実C1比較](evidence/turn-audit-c1-comparison.json): clean1/29assert、fault1/43assert成功。canonical pipelineの最初の1000row audit INSERT直後に実SQLSTATE40001を一度発生させ、同PDOのroot rollbackで既存stateへ復元し、same run/ruleset/target/seed・別TurnStateで完了。89表は数値ID/全時刻込み一致。監査1131行はidだけ除き相対順・内容・全時刻が一致。raw audit IDはclean2–1132 / fault1010–2140で異なり、attempt診断も意図どおり異なる。非empty tail破棄は上の新規代表で補完する。
- 既存6head＋#340＋本変更の使い捨てtree `be836fa0bcdc4f8344439b6edca3254eec0ff68a` でmature/growth/protectionを各1回確認。91表・clock・audit ID/順/内容・RNG/metricsが基準と一致し、全SQLは1345/490/186。これは組合せの正確性・SQL確認で、統合版の速度分布を測ったものではない。
- 同じ組合せで、コマンド登録→bulk→Turn→実地底戦闘→再送を実行し、既存の同fixture baselineと25表・全応答値/型/list順が一致（1test24assert）。JSON object member順だけは既存#354の変更として別扱い。

SQL数だけで全Turn/本番の速度倍率を約束しない。高並行負荷、実本番PHP8.5.12、全world/全branchの動的網羅、将来のobserver/readback導入は未検証。既存#340のSQL hook/共通updated_at/model-event差は別の採用境界として原本に残し、本変更でその契約を拡張しない。

## English

Batch only process_cells audit rows, capturing each existing row snapshot and timestamp immediately. A bounded TurnState write set is shared across recorder instances, flushes at 1,000 rows and phase end, and discards pending rows on failure. Other phases keep immediate audit writes; the transaction and RNG paths are unchanged.

All 27 matched runs across three fixtures preserved 91-table state, timestamps, ordered audit rows including IDs, phase metrics and recorded draws. Mature-turn SQL fell from 3,355 to 2,245. Wall-time ranges overlap and whole-turn PHP CPU barely changed, so this is a measured SQL-roundtrip reduction, not a production speedup promise. Existing PR #340 remains the higher-priority persistence optimization. Global sequence interleaving and failed-attempt gaps are not guaranteed to match the old version. Detailed rollback, retry, combination evidence and final-head CI are recorded in the associated PR documentation.
