# 隔離再現の案内

対象sourceは `2e93ec918774ad64d787aba99fc59a7bc3e54d16`。既存証拠の再実行は完了条件ではない。実装候補を採用するときに不足する代表だけを補う。全suite/CI/長期試験へfilterを外して広げない。本番・既存DB・既存コンテナ・認証情報を使わない。

## 証拠だけ読む

- `turn-current-mature.json`: 全12phaseのwall/query/hydration/metrics。
- `evidence/command-serialization-queries.json`: Q1とQ30のSQL形状・対象件数。
- `evidence/api-boost-series-queries.json`: 1series/3occurrenceの反復SELECT。
- `battle-probe-comparison.json`: baselineとAI試作の結果hash/RNG呼出/選択回数。
- `turn-*-results.json`、`asset-probe.json`、`evidence/*microbench.json`: 入力と限定CPU/メモリ測定。
- `frontend-bundle-results.json`、2本のbuild log、`frontend-prototype.diff`: 現行とisolated split。

## 使用環境と分離

今回のlocal imageは `hakoniwa-world-hakoniwa-dev:latest`、image ID `sha256:3ce451260a2a66cf1e49aa5170bc4cb09de8edfb69669bd701cf8a10e2ad8310`、PHP8.5.8。sourceのDockerfile8.5.12とは相違する。PGは `postgres:18.4-bookworm`。Docker daemonはlocal socketを明示し、外部contextを解除して利用した。元imageのvendorはcomposer.lockが現行と異なるためDB測定へ流用しなかった。

`runtime/`は固定sourceのapp/bootstrap/config/database/routes/tests/resources/publicとcomposer.json/lock/artisan/phpunit.xmlの隔離copy。`.env*`/archive/vendor/node_modules/public buildをコピーしない。必要な空storage/bootstrap cache directoryを作り、現行lockから別途vendorをinstallした。productsのsourceはreadonly参照か、この隔離copyだけを使用する。

依存installはcomposer2.10.2を新規containerで `--no-scripts --no-plugins`。HTTPS検証とセッションproxyを維持し、公開CA bundleをreadonly mountした。credential値を取得・表示していない。DB用containerと新規PG以外を接続しないinternal networkを作り、PG dataはtmpfs。host portを公開しない。DB名は `hakoniwa_test`、user `hakoniwa`、password `audit-only`（今回の合成fixture用に新規指定した値）で、既存の認証情報ではない。

再利用時も新規名で用意し、APP_ENV=testing、DB_DATABASEの末尾`_test`、DB_HOSTをその専用PG alias `hakoniwa-postgres` に固定する。phpunit.xmlのhost/database force指定も確認する。productionのenv/config/volumeをmountしない。既存検証が終了したため、今回作成したcontainer2個とinternal networkは親が最後に削除した。runtime copyと全証拠は保持する。

## 実行した最小DB代表

新規の隔離PHP containerで `/var/www/html` をruntime copy、`/audit` をこの成果ディレクトリにbindした。PHPUnitはcurrent lockのvendorを使う。

```sh
php vendor/bin/phpunit --filter 'test_production_mature_world_has_bounded_process_cell_queries' tests/Feature/TurnRuntimePerformanceTest.php
```

必要なphase JSONを出す実行だけ `REPORT_TURN_RUNTIME_PERFORMANCE=1` を指定。1test/5assert、fixture3600cells/1Nation/固定seed、全12phase。最初のassert確認とreport付きの2回を区別してlogを保存してある。

```sh
php vendor/bin/phpunit /audit/scripts/BoostSeriesSqlProbeTest.php
php vendor/bin/phpunit --log-junit /audit/evidence/command-serialization-junit.xml /audit/scripts/CommandSerializationSqlProbeTest.php
```

この二つは製品に追加したtestではなく、外部の測定器。fixture準備後にSQL listenerを付け、対象処理だけを計数する。DB共有によるfixture競合を避け、順番に各1caseだけ実行した。戻り値やquery count以外の速度保証をしない。

## 純粋probe

- `turn-rng-bench.php`: `/source/product/app/Domain/Turn` の二つのクラスを必要パスへreadonly bind。100k draw×3＋境界vector10k。DB/application bootなし。
- `turn-catalog-bench.php`: `/source/product/app` と `/source/product/config` をreadonly bind。v41/42items、500calls×3。
- `turn-undersea-index-bench.php`: 同じsourceのindexとGridCoordinateを使用。scalar model stub、9216cells×50候補選択×3。SQL/資金決算は含まない。
- `battle-probe.php baseline` / `battle-probe.php ai_reuse`: `/var/www/html/app`、config、resourcesのみreadonly bind、`/audit`へ出力。app boot/DBなし。32 laboratory＋8runtime solo＋8partyの短いfixture。変換はautoload時のメモリ上だけ。free-action/boss全分岐検証ではない。
- `scripts/api-metadata-microbench.php`: 現行classのdecoderをReflectionで呼ぶ。1000×1626B、3回限定。
- `scripts/command-bulk-plan-microbench.php`: runtimeのautoloadでReflectionによりbulkPlanだけ呼ぶ。空queue/10000候補/limit30/各方針1回。SQL・validation loopは含まない。
- `scripts/asset-probe.php`: 現行resolver＋config stub＋合成1px GIF、256回×3。単純memoの性能上限の補助であり、そのまま製品へ入れる案は反証で保留。

pure probesは新規container、network none、必要なsource directoryだけreadonly mountした。prefixや固定pathは各script冒頭にある。`.env`を含むcheckout全体のbindは不要。CPU結果をDB/HTTP結果と混ぜない。

## フロントと棚卸し

現行sourceで配置済みnode_modules（Node24.19.0/Vite8.1.5）を使い、出力先をこのディレクトリへ指定してbuildした。隔離copyはresources/publicとbuild設定だけ、node_modulesは同じものを参照。AppのUndergroundPanel import一つだけをasyncへ変えたdiffを保存した。製品には適用していない。gzip byteはPython gzipの同じlevel9を使用、Vite logのgzip値（別の圧縮設定）と混同しない。

`scripts/inventory.py`→`finalize-inventory.py`→`merge-coverage.py`は目録再生成用。`api_inventory.py`、`foundation-inventory.py`、`turn-inventory.py`は各担当の根拠生成器。これらの機械索引だけで全行精読/全分岐実行と主張しない。`source-census.csv`のhashとfixed SHA、`verification.json`で製品不変を確認できる。
