# process_cells監査write-setのC1再試行確認

基準main: `2e93ec918774ad64d787aba99fc59a7bc3e54d16`。対象は親が作成した同版audit write-set prototypeで、実行時8ファイルのSHA-256を各JSONへ記録した。今回の担当は製品source・共有tests・設定を変更していない。

**結果: 正常実行と、最初の1,000行監査INSERT直後に実SQLSTATE 40001を1度起こした実行は、許容する診断/sequence差を除き一致した。** 各runは初回cronの単一`TurnRunner::run()`呼出。manual retry・別起動からの再開・full suite・CI・本番操作は行っていない。

## 確認方法

- 専用isolated PHP 8.5.8 / PostgreSQL 18.4で、既存mature 60×60=3,600セルfixtureを使用。時刻、Faker、登録UUIDをsetup前に固定し、master seed入力を64桁0にした。
- 本物の`app(TurnRunner::class)`とAppServiceProviderの12個の`GameplayTurnPhase`を利用した。phase wrapperや偽pipelineを導入せず、各phase class/順序、RNG実装のsource hashも保存した。固定seed以外のRNG処理は変更しない。
- `RefreshDatabase`の外側transactionではC1のroot rollback条件を満たせないため、既存`TurnRunnerTransientRetryTest`と同じ`DatabaseMigrations` + `UsesForwardOnlyDatabaseMigrations`を使用。run開始時transaction level 0を確認した。
- 外部の`DB::listen`だけで、QueryExecutedのINSERT shape/16,000 bindingsと、call stackの`CompleteTurnEngine::execute('process_cells', $context)`を確認。PostgreSQLが最初の1,000行INSERTを実行した後、そのtransactionから1,008監査行を読めることを確認してから `DO ... RAISE ... ERRCODE='40001'` を1度実行した。
- C1がrollback後に自分のrunを再読取する時点を観測し、transaction level 0、同じPDO、初期gameplayと既存監査の全復元を確認した。TurnRun/admission slotはgame transaction外に作るcontrol recordなのでこのrollback checkpoint比較から除いた。

## 結果と比較境界

| 確認 | 結果 |
|---|---|
| clean focused case | 1 test / 29 assertions成功 |
| fault-v2 focused case | 1 test / 43 assertions成功 |
| 比較script | 19 checksすべてtrue |
| 同じ入力・code・lockfile・seed | 一致 |
| fault後のrun | 同じrun/ruleset/target/seedのままattempt_count 1→2、status completed |
| context/state | identityは同じ、2つのTurnStateは別object。失敗scope/成功scopeとも最後はbatch inactive、buffer 0 |
| process_cells INSERT | clean `[1000,112]`。fault第1attempt `[1000]`、第2attempt `[1000,112]` |
| 最初の1,000イベントの再現 | audit IDだけを除き、metadata/subject ID/全時刻が一致 |
| 最終captured table数 | 91表 |
| raw差分 | `audit_events` と `turn_runs` の2表だけ |
| 残89表 | 全row・数値ID・時刻を保持したまま完全一致。資産・人口・地形等の二重適用なし |
| ordered Turn audit | 双方1,131行。`id`だけを除き、行順・全metadata・subject ID・occurred_at・created_at・updated_atが完全一致 |
| 最終12 phase metrics | 一致 |
| raw audit ID範囲 | clean 2–1,132、fault 1,010–2,140。**数値IDの完全一致ではない** |

PostgreSQL sequenceはrollbackされない。失敗attemptで実際に挿入した行と、その前のphaseの監査IDは欠番になる。TurnRunのattempt_count/failure_contextも再試行診断として異なる。比較では監査の`id`とTurnRunの該当診断、共通snapshot採取のphase durationだけを区別し、全table/all-ID一致と主張していない。認証・User・session・cache表とattachment_tokenは採取していない。

ordered audit（id除外）のSHA-256は双方
`06cee31faffa96f5eeb81781edcad8dd9771886d8084aa9b5e1e9c72587fa231`。

## tail・時刻についての限界

fault位置は`takeCellAuditRows()`後の最初のINSERT直後なので、その瞬間の未flush bufferは0。したがって、この一例だけで「非empty tailを捨てた」とは主張しない。本probeではfinallyによるscope解除、root rollback、新Stateでの同じ先頭batchと末尾112行の再構成を確認した。

非empty tail（1,000行flush後に追加1行）を例外で破棄する性質は、親の既存focused `TurnCellAuditBatchTest::test_failed_cell_batch_rolls_back_flushed_rows_and_drops_its_tail_before_replay` が補完する。記録時刻/可変metadataのsnapshot保持も親の別focused代表が補完する。本probeの時計は固定であり、実時間の経過や性能は検証していない。

実40001だが、競合writerが自然に引き起こしたserialization raceではない。C1のSQLSTATE判定・root rollback・同session・新stateの経路を、待ちや競合タイミングなしで確実に通した確認である。

## 証拠と実行履歴

- `TurnCellAuditRetryProbeTest.php`: 外部focused probe。製品suiteへ追加していない。
- `clean.json` / `clean-junit.xml`: cleanの全入力/出力snapshot。
- `fault-v2.json` / `fault-v2-junit.xml`: 最終のfault/C1再試行成功証拠。
- `comparison.json` / `compare_retry.py`: 比較結果と除外項目を明示した比較処理。
- `fault.json` / `fault-junit.xml` / `probe-v1.php.txt`: 最初のfault確認もC1自体は完了したが、最後の外部assertSameがJSONB object key順だけで失敗した記録。外部の期待値/実値を既存canonicalizeで比較する修正を行い、元証拠を残して新しい合成DB/初回runのfault-v2を1caseだけ再確認した。製品の再試行処理は修正していない。

実行先は `perf-write-php-audit-20261031`、probeは `/evidence/retry-probe/TurnCellAuditRetryProbeTest.php`。`TURN_CELL_RETRY_MODE=clean|fault` と新しい`TURN_CELL_RETRY_OUTPUT`を指定し、各々PHPUnitでこの1ファイルだけを実行した。既存出力の上書きは拒否する。時間値を採用する測定ではない。

## English summary

The configured canonical TurnRunner completed a real PostgreSQL 40001 retry after the first 1,000-row process_cells audit INSERT. C1 rolled back the root transaction on the same session, rebuilt a fresh TurnState, and retained the same run, ruleset, target turn, and seed.

The clean and retried executions matched across all 89 non-audit/non-run tables, including numeric asset IDs and timestamps. Their 1,131 ordered audit rows matched in metadata, subjects, and timestamps after excluding audit sequence IDs. Raw audit IDs and retry diagnostics intentionally differ. A non-empty pending tail is covered separately by the existing focused test; this post-flush fault has an empty pending buffer. No timing or production claim is made.
