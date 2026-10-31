# 8候補を結合した全suite / Full combined test-set verification

**既存6件＋未merge #340＋#359を結合し、Qualityと同じ全テスト集合のローカル実行を完了した。backendは163ファイル・1,343テスト・19,097 assertions、failure/error/skipと識別子の欠落/重複はすべて0。frontendは39ファイル・301テスト成功。** 正式GitHub統合CIの成功、本番適用、全分岐や高並行負荷の保証とは区別する。

## 固定ソースと #340 の未merge状態

mainは `2e93ec918774ad64d787aba99fc59a7bc3e54d16`。以下の固定差分を順に適用した使い捨てtreeは **`e14d371105d717d3df18aa2fc862deafe2b38dd3`**。commit/push/mergeはしていない。全8件のremote headとmainを実行前後に照合した。

| PR | 固定head |
|---|---|
| [#357](https://github.com/Mamiki765/hakoniwa-world/pull/357) | `86243ae01f7671b9dad9fd450591a04b68afaec8` |
| [#353](https://github.com/Mamiki765/hakoniwa-world/pull/353) | `c33263dc018a4611d1973e8be327cdcd4835ac5e` |
| [#356](https://github.com/Mamiki765/hakoniwa-world/pull/356) | `7aa89e1058416da5b7cc3b65b65a7cb3816deb83` |
| [#352](https://github.com/Mamiki765/hakoniwa-world/pull/352) | `bc285270183123ea76e89a03462f7d38dfcdb37e` |
| [#355](https://github.com/Mamiki765/hakoniwa-world/pull/355) | `69a5a038a21b7b2219d9ae87f125e8e0cb6c564e` |
| [#354](https://github.com/Mamiki765/hakoniwa-world/pull/354) | `60d65c66f5ee0b6798bc68351201aa233b04a3f8` |
| [#340](https://github.com/Mamiki765/hakoniwa-world/pull/340) | `8509bd5c3c6666db580c6fda2a9c7ce986316642` |
| [#359](https://github.com/Mamiki765/hakoniwa-world/pull/359) | `43736136ea4d094fbdf1596be82316c20f3d3eab` |

#340の正確なtitleは **「ターン高速化: 成長マスの保存をまとめ書き（二重UPDATE解消・DeferredMapCellWriter）」**。観測時点で **open / non-draft / merged=false**、head branchは`claude/project-thread-nq4b7c`。既存の未merge改善であり、現mainに未包含。Ownerの採否判断はAPIから推測しない。他者branchは変更していない。

PR APIはbase.ref=`main`、base.sha=`12a4f242028e4d0bd8b8db118097762c4214b264`を返した。これはGitの共通祖先と一致するが、現在のmain tipとは異なる。なぜAPIがこの値を返すかは断定せず、現在mainを別ref取得で確認した。共通祖先→#340 headの4file/+271−4だけを現mainへ適用し、古いhead全体で5.8.3を置き換えていない。

現mainに未包含なのは、CompleteTurnEngineのstage二重保存削減と成長保存境界、新規DeferredMapCellWriter、および対応2テストfileの差分。stage時version計+2は保持する。4pathは共通祖先→現mainで差分なし、headはmainのancestorでもない。RulesetVersionのJSON解読再利用は5.8.2として既にmainにあり、今回#340の残る差分ではない。[正確な状態・path・blob根拠](full-suite-evidence/pr340-status.md)。

今回treeの変更は37file。以前の限定結合tree `be836fa0bcdc4f8344439b6edca3254eec0ff68a` にあった33fileはすべてbyte hash一致し、追加は#359の文書/証拠4fileだけ。今回の全件試験は前回の限定回帰を置き換えて消さず、同じruntime差分の確認を広げた。[manifest](full-suite-evidence/source-manifest.json)・[実行後source/remote照合](full-suite-evidence/final-source-integrity.json)。

## 正式な集合と実行方法

`.github/workflows/quality.yml`と現行dispatcher/fixture guardを正本とした。`test_shards.php plan 16 full` → `plan-verify` → 各indexの`plan-profiles`に従い、`standard` / `reusable_surface` / `individual`を別PHP processで実行。PHPUnitは既存の512MiB、無filter、追加引数は色無効/JUnit出力のみ。sourceやtest条件は変更していない。

16のindexを、専用PG・storage・bootstrapを持つ4slotで順次担当させた。各indexの開始前にそのslotの合成`hakoniwa_test` DBをfreshにし、他slotと共有しない。slot内ではstorage/bootstrapを次のindexへ再利用する点が、独立した16 GitHub jobとは異なる。正式なCIサーバー上の統合runではなく、**CIと同じテスト集合のローカル結合実行**である。

4slotのplan割当は一致し、163fileのunionに重複/欠落/余分なし。fixture内訳109 standard / 22 reusable_surface / 32 individual。serialと16分割の宣言済み識別子は1,343で一致し、さらに終了後のJUnitから実行済み識別子を抽出して完全照合した。ファイル数だけの確認ではない。

| 確認 | 結果 |
|---|---|
| 全matrix index / fixture process | 16 / 42、すべてexit0 |
| 実行file / 一意file | 163 / 163 |
| 宣言 / 実行test identifiers | 1,343 / 1,343 |
| assertions | 19,097 |
| failure / error / skip | 0 / 0 / 0 |
| missing / unexpected / duplicate identifiers | 0 / 0 / 0 |
| matrix全経過 | 約350.129秒。性能比較や改善率の根拠には使わない |

[照合JSON](full-suite-evidence/execution-comparison.json)、[matrix結果](full-suite-evidence/matrix-results.json)、[宣言](full-suite-evidence/declared-identifiers.txt)・[実行済みID](full-suite-evidence/executed-identifiers.txt)、[外部driver](full-suite-evidence/run-ci-matrix.py.txt)・[比較器](full-suite-evidence/compare-execution.py.txt)。このdriverは測定/検証用で製品runtimeに組み込まない。

## その他のQualityチェック

| 正規チェック | 結果 |
|---|---|
| composer validate --strict --no-check-publish | 成功。current lockの既存vendorをreadonly利用、PHP依存の再installはしない |
| Pint --test（変更PHP） | 22file成功 |
| composer analyse（正規level6） | app527file、errorなし |
| shard verify 16 full / identifier equivalence | 成功、1343識別子一致 |
| documentation contract validator | 成功、85 unique IDs |
| npm ci | task内cacheでfresh install成功 |
| npm run typecheck / lint / build | 成功。lintは既存6warnings/0errors |
| npm run test（test:all） | 39files / 301tests成功 |

[static](full-suite-evidence/static-results.json)・[frontend](full-suite-evidence/frontend-results.json)。resources/package類はmainから差分なしなので、frontendの同じ全件をmainでも重複実行しない。PHP8.5.8、PostgreSQL18.4、Node24.19.0/npm11.9.0、CLI OPcache無効。[環境・lock hash](full-suite-evidence/environment.json)。Dockerfile指定PHP8.5.12での結合実測ではない。

## 初回の失敗とmainでの切り分け

最初に正式`composer test:parallel -- 4 full`を同じstorageのローカルrunnerで起動したが、私の環境準備でproduct/dockerとDockerfileをコピーし忘れ、純粋test2件が不足fileを読んで失敗した。root Composeも不足し2caseがskipした。このrunは全件成功に数えない。

別にHotfixCommandQueueCorruptionTestの初回登録が503だった。mainにも存在するBoostScheduleTestは共有`storage/framework/down`を一時的に作る。未変更mainの同Hotfixcaseで同じ共有maintenance状態を置くと、同じassert位置の503が再現した。初回の失敗HTTP応答本文やmaintenanceファイルの瞬間traceは採っていないため、そのrunの因果の断定は避ける。DBだけ分離するlocal4と、filesystemも分離するCIの差を切り分けた。

追跡済みDocker/Compose fixtureを復元し、独立storage・maintenance解除で、未変更mainの対象3file/7tests/77assertionsは成功。その後、上の独立slot方式で結合版の全1343testが成功した。製品や他者branchを修正して失敗を消していない。local4 runnerの共有maintenance競合を製品修正済みとも扱わない。

[初回全件log](full-suite-evidence/full-suite.log)、[mainで503再現](full-suite-evidence/main-maintenance-reproduction.log)、[mainの7case成功](full-suite-evidence/main-environment-focused.log)。npmの最初のinstallも既定cache directoryへ書けず失敗し、task内cacheへ変えた。ネットワーク/認証/security設定は変更せず、元logをZIPへ保全した。

## 結論と残る境界

今回の固定8候補について、全suite未実施だった穴は上記の範囲で閉じた。追加の製品回帰は確認できず、draft branchのコード修正や新規PRは不要だった。個別PRの最終head CIと、このlocal結合全件、文書#358のCIは別の証拠として扱う。

単一host/container条件、slot内filesystem再利用、PHP8.5.8、外部source-manifestによるpin（plan自身のsource/lock metadataはunknown）が限界。本番PHP8.5.12、高並行負荷、実job配送、全world/全乱数分岐、本番データ・運用状態は未確認。既存#342/#343等を含む別の組合せまで保証しない。前回の速度比較を加算したり、全suite時間から本番倍率を予測したりしない。

実データは変更せず、固定8候補への追加schema・Ruleset・製品/test修正や認証・security変更も行わず、merge/approve/auto-merge/本番操作も行わない。作成した環境だけを撤去し、証拠を保持する。[cleanup記録](full-suite-evidence/cleanup.json)・[独立照合](full-suite-evidence/independent-review.md)。[完全証拠ZIP](full-suite-evidence-20261031.zip)は171 entries / 456,974 B / SHA256 `0629ea08ff5a6ecaf322d602bbb25bb9839687d7b6d4b90b1c0939bd5c41d5cb`。[manifest](full-suite-evidence/MANIFEST.json)はZIP内の原本bytesに対応し、閲覧用textは末尾空白/末尾空行のみ調整した。前回までの証拠ZIPは不変。

## English

All 1,343 backend tests (19,097 assertions) in the combined eight-candidate tree passed, with zero failures, errors, skips, missing or duplicate identifiers. All 301 frontend tests and the remaining repository quality checks also passed. This was a local execution of Quality's full test set: 16 planned indices in four isolated slots, with a fresh database per index and storage/bootstrap reused within each slot. It was not a GitHub combined CI run or production verification.

PR #340 remains open, non-draft and unmerged; its double-save removal and deferred growth writes are absent from current main. Its API base SHA equals the merge base, not the current main tip. The fixed four-file patch was applied to current main without replacing 5.8.3. Initial missing-fixture failures and a shared-maintenance 503 reproduction on unmodified main are preserved separately. No product fix or additional PR was needed. Production, high concurrency and other PR combinations remain unverified.
