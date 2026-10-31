# Turn保存経路の再調査・実装・比較 / Turn persistence follow-up

> 後続の[全suite結合確認](full-suite-2026-10-31.md)で、固定8候補の全テスト集合をlocal実行済み。本書の「全suite未実施」等はこの先行段階の記録として保持する。本番・高並行負荷は引き続き未確認。

支配的なコストを下げる第一候補は**既存 #340 の二重保存削減・成長write-set**だった。重複PRは作らない。現mainに固定patchを適用して再測定し、成長中心fixtureでは全Turn中央値3093→1815msを確認した。

追加の独立案として [#359](https://github.com/Mamiki765/hakoniwa-world/pull/359) をdraftにした。`process_cells`の監査rowを記録時点で確定し、1000行/phase末にINSERTする。成熟fixtureのSQLを1110往復減らせるが、CPU改善はほぼ支持されず、phase memory増分もある。**全処理高速化・本番倍率・全branchの同値を結論しない。**

## 固定対象・既存案の照合

- main: `2e93ec918774ad64d787aba99fc59a7bc3e54d16`。実装前にopen PRを再確認。
- #340: `8509bd5c3c6666db580c6fda2a9c7ce986316642`。共通baseは12a4f242…。現在mainへ4fileの固定patchを適用し、古いtree全体や旧Rulesetと比較しない。
- 新規 #359: `43736136ea4d094fbdf1596be82316c20f3d3eab`。mainから独立、[個別記録](https://github.com/Mamiki765/hakoniwa-world/blob/43736136ea4d094fbdf1596be82316c20f3d3eab/product/docs/performance/turn-cell-audit-write-set.md)。[最終head Quality CI](https://github.com/Mamiki765/hakoniwa-world/actions/runs/41214204802)全22job成功。
- 既存6件はhead不変。6件+#340+#359の使い捨て結合treeは `be836fa0bcdc4f8344439b6edca3254eec0ff68a`（commitではない）。[source manifest](turn-write-evidence/combined-source-manifest.json)。main merge/統合commit/pushはしない。

#340の本文・旧計測・既存reviewも確認した。旧条件の値を今回の差分へ加算せず、現在の比較を以下に示す。#340に現supported gameplayを壊す具体的反例は見つからず、stage成長時version計+2と監査内容/順を保持する。ただし共通flush時刻のupdated_at、raw batchによるEloquent通知の迂回、SQL hookの将来条件は保証境界。現MapCellにobserverはなく、updated_atはownerへ公開されるものの、現consumerのゲーム判定/optimistic lockには使われない。現在のTerritoryInfluenceにもbulk共通時刻の先例があり、時刻差だけをP2と断定しなかった。

[保存契約](turn-write-evidence/analysis-contracts.md)・[#340境界](turn-write-evidence/analysis-turn.md)・[audit capture台帳](turn-write-evidence/analysis-audit-write-set.md)に、PG triggerのSET列、途中人口SUM/難民/refresh、監査read/write、source根拠を記録した。世界stateの末尾一致だけで安全と判断しない。

## 同条件27run

[詳細](turn-write-evidence/measurement/report.md)・[比較JSON](turn-write-evidence/measurement/comparison.json)・[samples CSV](turn-write-evidence/measurement/samples.csv)・[phase CSV](turn-write-evidence/measurement/phases.csv)。

PHP8.5.8/PG18.4/current lock vendor、専用internal network/tmpfs DB、CLI OPcache無効、readonly source。時計をmigration/catalog生成前から固定し、Faker/登録UUID/seed/fixture recipeも共通化。main / main+#340 / main+#359、3fixture×各3回をLatin順で逐次実行した（27run、99assertions成功）。CPU集中作業は止めたが共有ホスト外部負荷は制御していない。

全27runで、入力・最終stateの91表、audit ID/順序/metadata/全時刻、全12phase metrics、記録draw/seed/RNG source/依存が一致した。同版各3回も再現した。auth等5表とinquiries.attachment_tokenを取得せず、他のtimestampは残した。正規化した可変値はphase durationのみ。

| fixture | main SQL / 全Turn中央値ms | #340 SQL / ms | #359 SQL / ms |
|---|---:|---:|---:|
| mature | 3355 / 3290.066 | 2455 / 2768.924 | 2245 / 3118.641 |
| growth | 3138 / 3093.306 | 744 / 1815.176 | 2884 / 3019.768 |
| protection | 696 / 762.597 | 186 / 460.179 | 696 / 719.405 |

全sampleのmin/maxも上記JSON/CSVに残した。#340のgrowth wall中央値は約41.3%減、PHP CPU中央値2115.387→1331.519ms。process_cellsのUPDATE2654→260で、保存の支配的コストへ作用している。protectionも512UPDATE→2で、SQL696→186となった。

#359のmatureはprocess_cells SQL3143→2033、audit1112行が2batchとなりINSERT1113→3（別用途1を含む）。全Turn SQL3355→2245、process_cells query.time合計中央値1285.08→1017.05ms。行を集約/削除する案ではない。すべての監査rowを保持する。

一方、mature全Turn wallはmain3272.297–3483.816ms / #359 2705.691–3307.110msで分布が重なり、PHP CPU中央値2166.899→2160.345ms（約0.3%）。process_cellsのallocator単位peak増分は4→8MiB。これはSQL往復削減の追加候補で、CPU支配コストを解決したとは言わない。growthはSQL3138→2884、監査の少ないprotectionは696のままなので、後者の見かけwall差を効果へ数えない。query.timeはPHP経過時間でありPostgreSQL CPUではない。

## #359の範囲と原子性

既存`TurnEventRecorder::row()`でJSON・subject ID・visibility/severity/message・記録時刻を即snapshot化し、TurnStateのFIFOを共有する。別recorderとrecordManyを捕捉し、1000行でflush、phase末にtailをflush、finallyでpendingを破棄する。他phase/WorldEventContextは即保存する。

process_cellsに現在到達するaudit readback/ID依存/成功する独立INSERTは未検出。MonsterDropとtreasureの直接inventory-full監査はlock内の前検査で通常成功経路から遮られる。開発コマンドのaudit readbackはあるためwhole-Turn bufferを棄却した。SQL文字列hook、schema、Ruleset/RNG変更、独立commit、並列phaseは導入しない。将来このphaseにaudit reader/独立writerを追加する場合は明示flush境界の再検討が必要。

新規3テストでsnapshot/記録時刻/metadata変更隔離、別recorder、recordMany、1000境界/tail、rollbackと再利用を確認し、54assertions成功（focused run合算）。fixture期待値のJSONB member順/default severity誤りだけを訂正し、元logも保全。正規Larastan level6のruntime3file、Pint成功。[固定head独立レビュー](turn-write-evidence/review-and-validation.md)は現supported経路で追加P0/P1/P2なし、merge許可ではない。

### 実C1と同版replay

[詳細](turn-write-evidence/retry-probe/README.md)・[19check比較](turn-write-evidence/retry-probe/comparison.json)。canonical app(TurnRunner)の最初の1000行audit INSERT直後に実PG SQLSTATE40001を1度発生させた。clean1test29assert / fault1test43assert成功。root transaction level0で始め、同PDOでroot rollbackし、同run/ruleset/target/seed・新TurnStateで完了した。再接続・manual retryではない。

失敗時には同TXから1008監査行を読め、rollback後にgameplayと既存監査が復元した。最終89表は数値ID/全時刻を含め一致。監査1131行はidだけを除き内容・相対順・subject ID・全時刻が一致。生ID範囲はclean2–1132 / fault1010–2140で、sequence gapとattempt診断は異なる。旧版のglobal ID割込や未使用ID消費量まで同一とは主張しない。post-flush fault時のpendingは0なので、非empty tailの破棄は新規focused代表で補完する。

通常の同版replayは27run内で各fixture3回一致した証拠と区別する。fault-v1の最後の外部assertはJSONB object member順だけで失敗し、比較器の正規化を修正した新しい合成run fault-v2を1case再確認した。製品の再試行処理は変更していない。

## 既存6件・#340との結合回帰

mature/growth/protectionを結合treeで各1回実行し、同recipe main基準と91表・全timestamp・audit ID/内容/順・RNG/metricsが一致した。SQLは1345 / 490 / 186。これは単回の組合せ正確性・SQL確認で、統合版の速度分布測定ではない。

コマンド登録→bulk→Turn→実地底戦闘→再送も既存同fixture baselineと25表・全応答値/型/list順が一致（1test24assert）。既存#354によるBoost JSON object member順差は継続して別扱い。[比較](turn-write-evidence/combined/cross-flow-comparison.json)。

正規dispatcherで4識別子だけを選び、4tests40assertions成功。[log](turn-write-evidence/combined-domain-focused.log)。missile＋monster kill/発射源順とship＋overflowの2代表は実process_cells、MonsterDrop満杯とtreasure容量preflightの2代表はサービス直呼びでbuffer非active。後者をactive bufferの満杯保証と呼ばない。full local suite/結合CIは起動していない。#359単体の最終head CIと、このlocal組合せ結果を混同しない。

## 地底の残候補と見送り

[測定](turn-write-evidence/battle-measurement/report.md)・[JSON](turn-write-evidence/battle-measurement/measurement.json)・[比較選定](turn-write-evidence/analysis-battle.md)。同一応答の装備row snapshotを共有するmemory-only prototypeを3短pairで比較し、equipped SELECT3→1/総SQL40→38、全state hash一致を確認した。

ただしpairごとのwall差は+0.6535/−0.0004/−0.2432ms（削減を正としており、差中央値ほぼ0）。inclusiveな方法内時間は重複加算せず、型解決18回/戦闘loadout2回は不変。安定した応答時間改善を示せず、mutation後/借用owner/画像lease/公開秘匿/HTTP境界は未検証なのでBU02は今回は製品実装しない。BU03も既存AI選択削減の時間寄与が小さくboss/覚醒/free-action境界の証拠が不足し、見送る。Turn保存の採用判断を優先した。

## 着手順・残件・保存

1. #340の採用判断を優先。stageのversion+2と現在のread barriersを保持し、共通updated_at/raw model通知/SQL hookの境界を理解する。
2. #359は独立して適用でき、#340の代替ではない。監査の多いTurnのSQL往復を減らす追加案として、memoryと維持コストも含めて判断する。
3. 既存#342/#343のCPU/hydrate改善、食料/油田/capacity反復read等は別候補。今回の保存比較へ混ぜず、将来の変更でなく現在の支配コストを再計測して選ぶ。

高並行負荷、本番PHP8.5.12、全world/全boss/全乱数draw trace、非固定時計の全世界結果、将来のobserver/reader導入は未検証。DB大改造/実移行・実データ変更は行わず、既存の設計比較を維持する。main merge、approve、auto-merge、本番/OCI、認証/セキュリティ変更、manual retryは行っていない。

[完全証拠ZIP](turn-write-evidence-20261031.zip)にprobe・全sample・raw state・失敗した外部期待値のlogも保存した。[manifest](turn-write-evidence/MANIFEST.json)。閲覧用copyのCSV改行/ログ末尾空白/Pythonリンクは調整し、完全bytes照合はZIP展開後に行う。以前の監査/実装/結合ZIPは更新せず保全。測定・文書の独立確認を終え、今回作成した10container・5internal network・使い捨て3treeは[撤去済み](turn-write-evidence/cleanup.json)。元の証拠とPR作業treeを保持した。

今回ZIP: 173files / 13,584,837 B / SHA-256 `1047011611f2cf4be8e80891bdb182dc75ec695ad4bd57427bccf1791213db00`。

## English

Existing PR #340 is the primary persistence improvement, not a reason to duplicate its implementation. On the current main with its fixed patch, three matched runs per fixture reduced the growth-heavy turn median from 3,093 to 1,815 ms and SQL from 3,138 to 744. All 27 baseline/writer/audit runs preserved 91-table state, timestamps, ordered audit rows including IDs, phase metrics and recorded draws.

New independent draft #359 batches captured cell-phase audit rows. Mature SQL fell from 3,355 to 2,245, but wall ranges overlap, PHP CPU barely changed, and phase peak memory rose by an observed 4 MiB. This is an additional roundtrip optimization, not a production speedup promise. Real PostgreSQL C1 rollback/retry, attempt-local lifetime and limited combination checks passed. Failed-attempt audit IDs differ because sequences do not roll back; audit content, relative order and timestamps matched.

The remaining underground equipment snapshot prototype saved two SELECTs but did not establish stable latency gains; it and the AI-selection candidate were deferred. Full combined-suite/production/concurrency coverage is not claimed. Evidence, code boundaries, rejected approaches and adoption order are recorded above. No merge or production operation was performed.
