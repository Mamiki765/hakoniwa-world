# Turn 保存最適化の同条件比較（2026-10-31）

**main／main+#340／main+監査行バッファの 3 条件・3 fixture・各 3 回、計 27 回を完了した。全 12 phase が完了し、固定時計の時刻列を含む 91 表、監査の ID 順・metadata・occurred_at、version・資産・seed・phase metrics が全比較で一致した。#340 は成長が多い fixture で大きく短縮した。監査バッファはログが多い場合の SQL 削減を確認したが、全 Turn の PHP CPU 短縮は今回ほぼ観測できず、時間差も控えめだった。**

27 tests / 99 assertions、失敗・追加反復・自動再試行なし。最初の main / audit mature 比較を sample 1 に含め、差分ゼロを確認してから残りへ進んだ。測定窓は終了して親へ CPU 使用再開を通知済み。製品・共有 tree の編集、commit/push、CI、production、実データ、認証情報の取得は行っていない。

> これは27run担当の測定終了時点の記録。後続のC1・結合回帰・独立レビュー・CIは[総合追補](../../turn-persistence-2026-10-31.md)を参照。測定値と限界はそのまま保持する。

## 対象と測定方法

| 条件 | ソース |
|---|---|
| main | `2e93ec918774ad64d787aba99fc59a7bc3e54d16` |
| writer | 同 main に固定 PR #340 head `8509bd5c3c6666db580c6fda2a9c7ce986316642` の 4-file patch を適用した使い捨て tree |
| audit | 同 main に親が準備した process_cells 限定の typed audit-row buffer prototype（3 runtime files）を適用した tree |

audit prototype は commit 前の測定である。実際に測った各 runtime file の SHA-256、同一の runner / pipeline / RNG source hashes は [measured-manifest.json](measured-manifest.json) に固定した。全 run で variant 内の source hash 不変を確認した。元 repo は最後も M の clean status だった。

環境は各 variant 専用の内部 network / tmpfs PostgreSQL18.4、PHP8.5.8、Laravel13.22.0、PHPUnit12.5.32。同じ locked vendor、composer.lock SHA-256 `0703880618c3d85c9aee120e3066897b4ca686b6be1ba3cdf2d043fb28d95fba`、framework Model source hash も一致。CLI OPcache は全 3 container で 0。PHPUnit は共通 phpunit.xml の memory_limit=512M。PG18.4 は親の隔離 runtime setup に基づく。configured PHP8.5.12 の実測ではない。

共通の [外部 probe](TurnWriteSnapshotTest.php.txt) は既存 TurnRuntimePerformanceTest の測定器を再利用した。W にある `processCellProfileWorld` helper 全文を全条件で同一コピーし、growth recipe を main にも同じように適用した。fixture recipe hash は `a5da792d4a9b84bd51546f9f6a5c749a7bb57c84211e2b5cf951462322a11170`。probe と生成手順の固定値は [probe-manifest.json](probe-manifest.json)、[prepare_probe.py](prepare_probe.py.txt) にある。

- 時計は `2026-10-31 12:00:00 UTC` に **parent::setUp より前から**固定し、migration に伴う catalog installation と全 fixture 行の timestamp も揃えた。Faker seed と登録 UUID も固定。
- 既存の official TurnRunner、全 12 phase、64 桁 zero seed を使う。RNG の呼出しや実装には instrumentation を加えていない。
- 各 sample は別の PHP process で単一 test だけを実行。RefreshDatabase による専用 DB の fresh baseline、同じ fixture を使う。入力 hash が一致することを性能比較の前提にした。
- 他担当の CPU を使う runtime と重ねず逐次実行。順序は round ごとに `[main,audit,writer] → [audit,writer,main] → [writer,main,audit]` として各 variant の位置を均等にした。[run-plan.json](run-plan.json) と [run_samples.py](run_samples.py.txt) に記録した。
- wall と getrusage の PHP process CPU を全 Turn と各 phase で別に取得した。query hook / hydration hook / metadata 処理は全条件同じ。PostgreSQL process の CPU は含まない。
- snapshot の read/hash/write は測定窓の外。入力 snapshot は全条件で保持したまま測定する。各 phase の同一 probe による query 集計時間は測定値に含まれる。

3 fixture を維持したのは支配コストが異なるためである。

| fixture | 実際に到達した process_cells の状態 |
|---|---|
| mature 3600 | 段階変更 900、新村 207、実火災 4、森成長 0。二重 UPDATE と大量 audit を通る |
| growth 3600 | W の既存 recipe：町 / 森 / 空地を交互に置く。森成長 1199、新村 254、人口増あり、段階変更・実火災 0。成長 writer の主経路を通る |
| protection 1024 | 工場 / 森の既存 recipe。森成長 511、火災抑止による実火災 0、新村・段階変更 0。小島・audit 少の対照条件 |

## 同値性と同版再現

[comparison.json](comparison.json) の全 27 sample 比較が pass。各 fixture の main-r1 を基準として、他版と同版の残り 2 回を照合した。最初の main/audit mature 比較だけを通して他の fixture の失敗を無視する実行ではなく、各 run の比較が非ゼロなら直ちに停止する gate を使った。

取得したのは public の 96 表のうち **91 表**。認証・cache の `auth_identities,users,sessions,cache,cache_locks` の 5 表は SELECT しない。`inquiries.attachment_token` も列を SELECT する前に除外した。その他の表・全 timestamp 列を残し、除外する実測値は **turn_runs.phase_results[*].duration_ms のみ**。JSON object の key 順を正規化し、配列順と row の ID 順は保持した。除外一覧は各 raw JSON と manifest に記録した。

| fixture | 入力 hash 先頭 16 桁 | 出力 hash 先頭 16 桁 | Turn 監査行数 | 監査で記録されている draw 字段 |
|---|---|---|---:|---:|
| mature | `f6f5c1c1b814d7f4` | `b8a157ed56cec97d` | 1131 | 211 |
| growth | `7dc774a7f4fea62e` | `386d1a7a0be121b4` | 275 | 254 |
| protection | `d2abf472ea086788` | `b2f7690ccb11c0e2` | 16 | 0 |

同 fixture は 3 variant × 3 回の全入力・出力で hash が一致し、表別 row count / hash も全て一致した。map_cells の全列（id、owner、population、quantity、version、updated_at 等）、nation と資産、TurnRun、監査 ID / subject / metadata / occurred_at が比較に入っている。全 12 phase の順序と metrics、seed、RNG source hashes、依存情報も一致した。

**同じ版・同じ初期状態・同じ seed の完了結果が 3 回一致する再現確認も、この測定内で実施済み。** error injection、C1 rollback 後 retry、6 差分との結合はここでは行っていない。また全 RNG call trace は取っておらず、「監査に現れない全乱数呼出しまで一致」とは主張しない。

## 全 Turn の測定値

各条件 n=3、単位 ms。wall は中央値 `[min–max]`、CPU は中央値。全 sample は [samples.csv](samples.csv) に保持した。3 点だけの記述統計であり、統計的な有意差や production 倍率を主張しない。

| fixture | 条件 | 全 Turn wall 中央値 [範囲] | PHP CPU 中央値 | SQL（全 3 回同数） |
|---|---|---:|---:|---:|
| mature | main | 3290.066 [3272.297–3483.816] | 2166.899 | 3355 |
| mature | writer | 2768.924 [2694.048–3360.902] | 1835.465 | 2455 |
| mature | audit | 3118.641 [2705.691–3307.110] | 2160.345 | 2245 |
| growth | main | 3093.306 [3060.733–4017.685] | 2115.387 | 3138 |
| growth | writer | 1815.176 [1679.670–1859.459] | 1331.519 | 744 |
| growth | audit | 3019.768 [2856.822–3060.714] | 2108.585 | 2884 |
| protection | main | 762.597 [717.960–949.773] | 561.007 | 696 |
| protection | writer | 460.179 [453.158–466.455] | 362.681 | 186 |
| protection | audit | 719.405 [716.945–728.873] | 522.029 | 696 |

## process_cells の局所測定

| fixture | 条件 | wall 中央値 [範囲] | PHP CPU 中央値 [範囲] | INSERT / SELECT / UPDATE |
|---|---|---:|---:|---:|
| mature | main | 2567.641 [2554.419–2732.871] | 1583.009 [1519.343–1704.952] | 1113 / 18 / 2012 |
| mature | writer | 2029.420 [1973.263–2598.481] | 1253.719 [1242.189–1578.458] | 1113 / 18 / 1112 |
| mature | audit | 2327.465 [1959.639–2560.114] | 1528.727 [1323.229–1629.939] | 3 / 18 / 2012 |
| growth | main | 2435.462 [2407.741–3318.315] | 1597.819 [1549.726–2015.258] | 256 / 18 / 2654 |
| growth | writer | 1132.212 [1045.716–1192.812] | 810.585 [736.923–818.417] | 256 / 18 / 260 |
| growth | audit | 2344.266 [2206.733–2369.510] | 1575.142 [1497.508–1579.983] | 2 / 18 / 2654 |
| protection | main | 468.667 [445.260–634.756] | 339.925 [307.758–410.900] | 2 / 17 / 512 |
| protection | writer | 184.083 [178.773–186.069] | 152.321 [147.642–155.023] | 2 / 17 / 2 |
| protection | audit | 442.518 [436.331–448.352] | 310.994 [307.600–328.669] | 2 / 17 / 512 |

各 phase の wall / CPU / SQL type / query time は [phases.csv](phases.csv)。SQL は監査以外の weather upsert も INSERT に含む。mature の audit 行 1112 本が 2 batch にまとまり、weather の 1 INSERT と合わせて 3 本となった。growth は 255 audit INSERT が 1 batch になり、weather と合わせて 2 本。protection は audit INSERT が元から 1 本なので SQL 本数が変わらない。

PHPUnit process 全体の peak memory は全 3 回で、mature 131MiB が全条件同じ、growth は main/audit127MiB・writer125MiB、protection は全条件86.5MiB。probe の保持 snapshot・fixture 生成・JSON 出力を含む値で、native Turn の memory には外挿しない。

## 解釈と推奨順

1. **#340 は既存候補として進める根拠が強い。** mature で 900 UPDATE の削減を現在 main でも再現した。growth では 2394 UPDATE を除き、全 Turn wall 中央値 -41.3%、PHP CPU -37.1%、process_cells wall -53.5% / CPU -49.3%。protection でも 510 UPDATE 減と短縮を確認した。mature の writer run には上振れがあり wall 範囲が main と重なるが、成長量別の SQL 削減と大きな fixture の改善は一貫する。別 PR に複製しない。
2. **audit buffer の価値は SQL 削減を中心に評価する。** mature の全 SQL は 3355→2245（-1110）、growth は 3138→2884（-254）で各 3 回一定。mature の wall 中央値は -5.2% だったが範囲が大きく重なり、全 Turn PHP CPU は -0.3%。growth も CPU -0.3%、wall -2.4% に留まる。今回の条件から大きな CPU 最適化とは言えない。実 DB 待ちが異なる環境への倍率換算はしない。
3. **protection の audit wall 差は効果の根拠にしない。** SQL が 696→696 で、変更された audit 保存の文数も同じ。main 初回の上振れ等を含む範囲の重なりがあり、見かけの中央値差を仕様由来の改善に数えない。
4. **次の新しい検証は交差に絞る。** 親が計画する six+#340+audit の結合、flush / failure / C1 原子性、blocked pirate / no-bow monster の pending-cell 境界は未確認。この 27 run と同じ成功 replay を繰り返す必要はない。#340 と audit は別々の条件なので、両者の短縮率を加算しない。

`updated_at` は固定時計下では一致したが、#340 が flush 時刻を共有する非固定時計下の挙動まで同じだとは証明していない。詳細な保存契約、モデル event の保証範囲、後続 same-object save の静的境界は [analysis-turn.md](../analysis-turn.md) を参照する。

## 成果物と限界

- [comparison.json](comparison.json)：全 27 sample の equality checks、最初の差分（今回は全て空）、n=3 の min/median/max。
- [measured-manifest.json](measured-manifest.json)：実測 source hashes、依存、入力・出力 full hashes、seed、fixture metrics、中央値差分。
- [samples.csv](samples.csv) / [phases.csv](phases.csv)：丸め前の各報告 run の全 Turn / phase 値。
- `main|writer|audit`-`mature|growth|protection`-`r1|r2|r3`.json / .xml / .log：raw 非認証 snapshot、測定、JUnit、CLI 記録。例：`measurement/main-mature-r1.json`（[完全ZIP](../../turn-write-evidence-20261031.zip)内）、[audit-mature-r1-check.json](audit-mature-r1-check.json)。
- [prepare_probe.py](prepare_probe.py.txt)、[run_samples.py](run_samples.py.txt)、[compare_samples.py](compare_samples.py.txt)：外部 probe 生成、逐次実行・停止条件、比較器。

今回未実施：production profile、PHP8.5.12、PostgreSQL server CPU、全 RNG call trace、error injection / C1 retry、six+#340+audit 結合、非固定時計の時刻契約、独立 review / final CI。3 fixture に ship / ordinary monster は存在しないので、その保存交差を測定済みとしない。拒否・不確かな実行結果・未解決の実行ブロッカーはない。

## English summary

All 27 matched runs completed the 12 official-turn phases: main, main plus pinned PR #340, and main plus the audit-row-buffer prototype; three fixtures and three runs each. Under a clock frozen before setup, all 91 captured non-authentication tables matched, including timestamps, cell versions, assets, audit IDs/order/metadata/occurred_at, seeds and phase metrics. Repeated successful runs of each code version also produced identical state. Authentication tables and the attachment capability token were never selected.

Median whole-turn wall time (main / #340 / audit, ms) was **3290 / 2769 / 3119** for mature, **3093 / 1815 / 3020** for growth, and **763 / 460 / 719** for protection. SQL counts were **3355 / 2455 / 2245**, **3138 / 744 / 2884**, and **696 / 186 / 696**, respectively. #340 showed a substantial growth-fixture reduction: whole-turn wall -41.3%, PHP CPU -37.1%, and process_cells PHP CPU -49.3%. The audit prototype reliably reduced SQL for audit-heavy fixtures, while whole-turn PHP CPU changed by only about -0.3% for mature and growth; wall distributions overlapped. The protection fixture offers no audit SQL reduction, so its apparent timing difference is not credited as an optimization effect.

These are three-point descriptive distributions from PHP8.5.8 / Laravel13.22.0 / isolated PG18.4 with identical instrumentation and rotating execution order. They are not production multipliers. The two candidate changes were measured separately; their gains cannot be added. Failure/C1 atomicity, the existing-six-patch integration, pending cells hit by pirates/monsters, and unfrozen timestamp semantics remain for the parent's focused checks. No product source, PR, CI or production data was changed by this measurement task.
