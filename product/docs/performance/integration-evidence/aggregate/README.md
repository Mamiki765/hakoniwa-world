# 6変更の結合静的チェック / Combined static checks

Base: `2e93ec918774ad64d787aba99fc59a7bc3e54d16`。
Combined tree: `99585a57f9d48007a5581ddf6ae1b42554841c9e`。

これは6固定headのpatchを同じ基準mainへ順次適用した使い捨てtreeの結果。結合commit/merge/pushは作らず、個別PRのCI成功と結合runtimeの検証を区別する。6headと変更26fileのhashは [source-manifest.json](source-manifest.json) に記録した。shared製品treeは変更していない。

## 実行結果

| 確認 | 結果 | 証拠 |
|---|---|---|
| `composer analyse` | **成功、exit 0**。正規composer scriptがcanonical schemaを静的解析用へ変換し、正規 `phpstan.neon` / Larastan level6でapp全526 PHPを解析。No errors | [log](composer-analyse.log) |
| 変更PHPのPint `--test` | **15 files成功、exit 0** | [log](pint-changed.log)、[正確なargv](pint-command.json) |
| `php tests/scripts/test_shards.php verify 16 full` | **161 fileの割当成功**。union161、duplicate/missing/unexpected各0 | [log](shard-assignment-16.log) |
| `bash tests/scripts/verify_test_identifier_equivalence.sh 16 full` | **serial/assigned/union各1,337識別子で一致**。duplicate/missing/unexpected各0、exit 0 | [log](identifier-equivalence-16.log) |
| 実行source | host全変更26fileがroot manifestと一致。runtime app526+check入力11の計537hashが一致 | [runtime hash](runtime-source-manifest.json) |
| synthetic treeとのdiff | 差分なし。`git diff --check`も成功 | [空diff](source-tree-diff.txt) |

識別子検証の既存scriptが呼ぶPHPUnitは **`--list-tests` のみ**。テストbodyを実行するfull PHPUnit、16 shardの実試験、frontend全test、CI新規起動/再実行は行っていない。app全体の解析は今回1回だけ行った。

## 隔離・限界

- Container: `perf-int-php-aggregate-combined-20261031`。PHP8.5.8、current composer.lock由来vendor、readonly combined source、独立storage/cache、network none。正規構成のPHP8.5.12との環境差がある。
- `composer analyse`の生成schema/cacheはruntimeの独立storageへ置いた。製品source、既存DB/volume、本番/OCI、資格情報、security設定には触れていない。
- [results.json](results.json) の各exit/resultは個別PR CIや実行回帰試験の代用ではない。結合runtime・before/after測定はroot/各担当の別成果物を参照する。
- ブロッカーなし。merge/approve/auto-merge/manual retry/commit/pushは行っていない。

English: The synthetic combination of six fixed-head patches passed the exact Composer analysis script against all 526 application PHP files, Pint for 15 changed PHP files, complete 16-shard file assignment, and serial/sharded identifier equivalence (1,337 identifiers; no duplicates, omissions or unexpected entries). Runtime source hashes matched the recorded combination. Identifier discovery used PHPUnit `--list-tests` only; no full test bodies or new CI runs were executed. This is distinct from each PR's CI and from combined-runtime regression/performance evidence. PHP8.5.8 differs from the configured PHP8.5.12 runtime.
