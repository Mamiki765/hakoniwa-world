# 6差分統合版の公式Turn比較

**同じ初期state・seedから全12phaseを完了し、84表の正規化した最終state、Turn監査1131行、記録された乱数draw字段211件が基準版と一致した。一方、このfixtureで全Turnの高速化は観測できなかった。** 各版1回の測定で、wallは3633.968→3751.407ms、PHP process CPUは2362.832→2491.693ms、SQLは3355→3355だった。単回から性能改善や統計的な回帰を断定しない。

## 固定対象と条件

- 基準main: `2e93ec918774ad64d787aba99fc59a7bc3e54d16`。
- 統合tree: `99585a57f9d48007a5581ddf6ae1b42554841c9e`。merge/commitを作らず6差分を順次適用した使い捨てtree。固定head・26対象ファイルのhashは [source-manifest.json](../source-manifest.json)。
- 対象head: コマンドresponse `86243ae01f7671b9dad9fd450591a04b68afaec8`、FND02 catalog `c33263dc018a4611d1973e8be327cdcd4835ac5e`、地底catalog `7aa89e1058416da5b7cc3b65b65a7cb3816deb83`、bulk plan `bc285270183123ea76e89a03462f7d38dfcdb37e`、T02消防 `69a5a038a21b7b2219d9ae87f125e8e0cb6c564e`、boost projection `60d65c66f5ee0b6798bc68351201aa233b04a3f8`。
- PHP8.5.8、PG18.4、同じlockの依存、各版専用internal network/tmpfsの合成DB。基準と統合版は同じDBを使わず、逐次実行した。親と他担当のCPU計測窓を調整し、追加反復はしていない。
- 既存 `TurnRuntimePerformanceTest::test_production_mature_world_has_bounded_process_cell_queries` の60×60成熟fixtureを使用。外部copyでnamespace/class名を分離し、時計/Faker/登録UUIDだけ固定した。公式Turn実装はそれぞれのtreeをそのまま読み込む。製品/共有treeは変更しない。
- 入力・出力snapshotを計測窓外に採取し、PHP `getrusage()` によるuser/system CPUを同じprobeへ追加した。sourceとなる既存testのSHA256は `7aa6415809a2b355b0a8b93cd65652c5a2ae4932990aec56ac14c4c1e396267a`、外部probeは `87e265729f33a78f857d43b1d7eef89fc471152383e841c52a2b625a6afdb279`。

## 正確性の比較

基準・統合とも既存case **1 test / 5 assertions成功**。`TurnRunner` がrun/Worldを完了し、全12phaseを実行した。

| 比較対象 | 結果 |
|---|---|
| 入力84表の全体hash | 両版 `2b675d65bf639d36b2fb9a338498fb60d26afd3876f91f28f8fa94b10529c14a` |
| 出力84表の全体hash | 両版 `ddc0745ee94c9d9f6fa565fdd10a2d0a222ef0b86521549e06bd8d249ee0a109` |
| 全表の行数・表別hash | 一致。30表に行があり、残り54表は両版とも空 |
| map_cells / map_chunks | 3600行 / 16行。typed値・owner・versionを含め一致 |
| 資産・進行 | Nation、資源7行、queue、秘書/skill、item、award、collection、monster/occupancy、NPC出品等を含め一致 |
| Turn監査 | 1131行。idを除外せずid順、type/visibility/subject/metadataが一致 |
| 全12phaseのgameplay metrics | 一致。900 stage変化、207集落出現、人口増491146、地上火災4件等も同じ |
| SQL分類数 / hydrate数 | 全phaseで一致 |

snapshotから除いたのは各行の `created_at,updated_at,occurred_at,started_at,completed_at,generated_at` と、`turn_runs.phase_results[*].duration_ms`。資産、id、version、座標、seed、metadata、phase順、gameplay metricsは除いていない。`obtained_at` や `registered_at` 等も保持した。auth/users/sessions/cache/migrationsおよび通信・運用向けの12表は最初から取得せず、認証情報を読み取っていない。除外表・列の完全な一覧は各raw JSON内に記録した。

監査ではTurnRun IDに紐づく1131行を抽出して比較し、監査全体1132行も表別hashに含めた。JSON objectのkey順だけcanonical化し、配列やeventのid順は保持した。比較器は [compare_turn.py](compare_turn.py.txt)、結果は [turn-comparison.json](turn-comparison.json)。

## 乱数と統合版の代表チェック

両版のTurn seedは64桁の0。`DeterministicRandomStream` と `TurnRandomStreamFactory` のsource SHA256も同一だった。監査に残るdraw関係211字段と全永続stateの一致を確認した。全drawのtrace取得や、同じ版の全Turn再反復は実施していないため、その証拠と読み替えない。

成熟fixtureには海底都市/消防施設がなく、T02の近傍検索は到達しない。FND02のcatalogは秘書snapshot・capacity等から実経路で利用されるが、このpaired測定は6差分統合であり、FND02単独の全Turn寄与を分離しない。補完として統合版で次の必要代表だけを実行した。**4 tests / 42 assertions成功**。

| 既存代表 | 確認 |
|---|---|
| NationCapacityTestのcurrent capacity / bounded credit | catalog基底読取後のskill/装備変更、最新capacity、資金・食品のbounded付与、未知modifier拒否。20 assertions |
| 逐次海底火災 | 同owner資金の枯渇、foreign消防、index構築後の消防破壊、火災/監査順。12 assertions |
| 海底消防の火災免疫と地上不保護 | 火災と巨大隕石の地形/施設破壊の相互作用。7 assertions |
| TurnRandomStreamTestの固定vector | 同seed/labelの整数列とshuffle。3 assertions |

ログは [combined-focused.log](combined-focused.log)、case単位の結果は [combined-focused.xml](combined-focused.xml)。個別版で通った9caseの単なる全再走査はせず、共有capacity/消防/地形/RNGの境界に絞った。

## SQL・CPU・所要時間

CPUはPHP processのuser+system時間で、別containerのPostgreSQL CPUを含まない。query timeは既存probeが受け取るQueryExecutedの合計であり、wallからCPUを引いた値をDB待ちと見なしていない。

| phase | 基準wall ms | 統合wall ms | 基準PHP CPU ms | 統合PHP CPU ms | SQL 両版 |
|---|---:|---:|---:|---:|---:|
| prepare_turn | 15.910 | 15.715 | 9.750 | 7.762 | 17 |
| calculate_terrain_context | 56.175 | 65.393 | 53.210 | 61.310 | 2 |
| resolve_territory_influence | 261.505 | 247.112 | 247.604 | 233.218 | 7 |
| nation_economy | 105.967 | 110.610 | 94.117 | 98.193 | 24 |
| resource_sales | 11.720 | 8.844 | 7.075 | 4.409 | 8 |
| development_commands | 13.324 | 11.387 | 7.369 | 5.160 | 14 |
| process_cells | 2815.304 | 2933.883 | 1709.593 | 1837.407 | 3143 |
| settle_deferred_effects | 0.811 | 1.014 | 0.577 | 0.651 | 1 |
| global_disasters | 141.926 | 146.681 | 93.292 | 96.358 | 27 |
| aggregate_nations | 83.918 | 82.577 | 72.719 | 71.719 | 21 |
| enforce_capacities | 14.917 | 15.896 | 7.144 | 6.704 | 14 |
| finalize_turn | 71.533 | 64.029 | 26.283 | 31.061 | 63 |
| 全Turn（runner込み） | **3633.968** | **3751.407** | **2362.832** | **2491.693** | **3355** |

全Turnの観測差はwall **+117.439ms（+3.23%）**、PHP CPU **+128.861ms（+5.45%）**。この1組から改善とは言えず、同様に統計的な回帰とも確定しない。特にT02は到達せず、他のAPI/地底/boostの最適化もこのfixtureでは主な処理ではない。process_cellsの3143SQL（INSERT1113/SELECT18/UPDATE2012）と900件の二重保存形はそのままで、別PRのdeferred writer等を含めていない。

同じprobeでもhost scheduling、allocator/GC、cache、CPU周波数等のばらつきは残る。入力snapshotを保持したまま計測する共通instrumentationもあり、PHPUnit peak129MBをnativeな本番Turnのmemory値へ外挿しない。以前の別条件の3977msという測定とは差分を計算しない。性能の詳細値は [phase-comparison.csv](phase-comparison.csv)。

## 保存物と限界

- `baseline-mature.json` / `combined-mature.json`: 入出力のraw正規化state、表別hash、seed/source hash、全phase測定。
- 各 `*-mature.log` / `*.xml`: 既存caseの実行結果。baseline/combined各1回のみ。
- `TurnIntegrationSnapshotTest.php` / `prepare_turn_probe.py`: 製品を編集しない共通probeと生成器。
- `turn-comparison.json` / `phase-comparison.csv` / `compare_turn.py`: 読みやすい比較と再計算器。

この確認は固定6head・固定tree・合成成熟world1caseと4focused代表の結合検証であり、全suite、全fixture、本番、PHP8.5.12、failure injection、同版の全Turn replayを保証しない。既存testが専用DBへschemaと合成fixtureを構築した。製品/共有treeやDB設計は変更せず、commit/push/CI、production/OCI、認証情報の操作は行っていない。

## English summary

One matched baseline/combined run completed all 12 official-turn phases. Normalized input and final state matched across 84 gameplay tables, including cell versions and assets. All 1,131 turn audit rows, 211 recorded draw fields, seeds, RNG source hashes, phase metrics and SQL counts matched. Four additional combined-runtime checks passed (42 assertions), covering current capacity, sequential undersea fires, terrain destruction and the fixed RNG vector.

This mature fixture does not exercise the optimized undersea-fire lookup. Total SQL remained 3,355. Measured wall time was 3,633.968→3,751.407ms and PHP CPU 2,362.832→2,491.693ms. These single paired observations show no whole-turn speedup and do not establish a statistical regression. No full random-call trace or repeated same-version whole-turn replay was performed.

閲覧用コピーでは比較器リンクを `.py.txt` としている。完全原本は統合証拠ZIPを参照する。
