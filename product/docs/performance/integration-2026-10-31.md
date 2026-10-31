# 6件の結合runtime検証 / Combined runtime validation

> 後続の[全suite結合確認](full-suite-2026-10-31.md)で、固定8候補の全テスト集合をlocal実行済み。本書の「全suite未実施」等はこの先行段階の記録として保持する。本番・高並行負荷は引き続き未確認。

6固定headの統合treeで、静的集約、公式Turn、コマンド登録から実地底戦闘までの横断実行、cache/scope寿命を確認した。今回の実行範囲では、個別変更の組合せによる資産・状態・抽選結果の不一致を検出しなかった。**全経路の完了・本番同等・全Turnの高速化を意味しない。**

## 固定対象と隔離

mainは `2e93ec918774ad64d787aba99fc59a7bc3e54d16`、6実装の各headと文書PR #358のheadを着手時に再確認し、前回から不変だった。対象は #357 / #353 / #356 / #352 / #355 / #354。完全head一覧・26変更fileのSHA-256は[source manifest](integration-evidence/source-manifest.json)。

6patchを同じmainへ順に適用した **tree `99585a57f9d48007a5581ddf6ae1b42554841c9e`** を固定した。これはcommit SHAではなく、使い捨てdetached worktreeのGit tree ID。mainへのmerge、統合commit/push、新しい製品PRは作らない。実装branchの変更もない。実行後にも[26fileのhash一致](integration-evidence/final-source-integrity.json)を確認した。

PHP8.5.8 / PostgreSQL18.4 / 同じcomposer.lock由来vendor。base/combinedと各担当を専用internal network・tmpfs DBへ分離し、host portは公開しない。製品sourceはreadonly mount、cache/storageは独立。productionのenv・既存DB/volume・資格情報を使わない。重い静的解析を先に終了し、Turnと横断比較は他担当のCPU実行を止めて逐次測った。共有ホストの外部負荷やCPU周波数は制御していない。設定されるPHP8.5.12 runtimeとの環境差は残る。

## 集約静的チェック

[実行結果](integration-evidence/aggregate/README.md)と[JSON](integration-evidence/aggregate/results.json)。統合版に対し各1回実行し、すべてexit0。

- 正規 `composer analyse`: schema準備とproject `phpstan.neon` / Larastan level6、app全526 PHPでNo errors。
- Pint `--test`: 変更15 PHP成功。
- `test_shards.php verify 16 full`: 161file、重複・漏れ・余分な割当なし。
- 既存serial/sharded identifier検証: 1,337識別子で一致。ここは `--list-tests` だけで、テストbodyを実行していない。
- runtimeのapp526＋check入力11の537hash一致、synthetic treeとのdiffなし。

個別PRのexact-head CIと、この統合treeのlocal集約を混同しない。統合treeに対する新規CIや全PHPUnit bodyは起動していない。frontend変更がないためfrontend全試験も反復していない。

## 成熟ワールドの公式Turn

[詳細とphase表](integration-evidence/turn/report.md)、[比較JSON](integration-evidence/turn/turn-comparison.json)、[phase CSV](integration-evidence/turn/phase-comparison.csv)。既存60×60成熟fixture（3600cell・1Nation）を外部copyで使い、時計・Faker・登録UUID・seedを固定し、同じprobeでbase→combinedを各1回実行した。両版とも **1 test / 5 assertions成功**。

入力84表のhash、全12phase完了後の84表のhashが一致した。セルversion・所有者・資産・秘書item・進行を保持し、Turn監査1131行のid順/metadata、記録draw字段211件、seed、RNG source、全phase metricsとSQL分類数も一致した。auth/users/sessions等12表は取得していない。clock列6種とphase durationだけを正規化して除いた。完全な除外表・列はraw evidenceに記録する。

| 測定 | base | combined |
|---|---:|---:|
| 全Turn SQL | 3355 | 3355 |
| wall ms | 3633.968 | 3751.407 |
| PHP user＋system CPU ms | 2362.832 | 2491.693 |

**この単回比較は全Turnの高速化を示さない。** wallは+3.23%、PHP CPUは+5.45%だった。統計的な性能回帰とも断定しない。fixtureに海底消防の対象火災はなく、API/地底/Boost最適化も主経路ではない。process_cellsの3143 SQLと900件の重複保存形は今回の6件では残る。別の #340 等は統合していない。

到達しない境界の補完として、統合版で最新capacity/装備変更・bounded credit、逐次海底火災の費用枯渇/foreign owner/施設破壊、巨大隕石による地形変更、固定RNG vectorの既存代表だけを実行し、**4 tests / 42 assertions成功**。同じ版の全Turn再反復や全draw traceは取っていない。

## コマンド→Turn→実地底戦闘の横断実行

[全比較結果](integration-evidence/root/cross-flow-summary.json)。同じapplication/containerで次を連続実行した。

1. 名前付き秘書と32×32 World/Nation、地上ring、解放済み地底profile/初期装備をfixtureで用意し、両catalogを温める。item戻り値の呼出元変更が元定義に戻らないことも確認。
2. financeをHTTP登録、追加28計画をcanonical serviceで登録。31候補のbulkをHTTPで送り、30件挿入・29既存計画取消・1候補skipを確認。30計画のGETも実行。
3. 同seriesの3開催回を持つBoost管理projectionを取得し、公式Turn全phaseを実行。先頭コマンド1件がcompletedとなり、queueを再GETする。
4. 標準のcanonical combatを使う地底探索HTTPを実行し、同requestを再送する。戦闘記録/ログが保存され、再送で資産・HP・cooldown・battle件数が重複更新されないことを確認。

時計・World seed・Turn seed・UUID・Faker・visitor codeを固定。地底seedは固定profile ID/request IDと合成APP_KEYから現行のprivate seed導出をそのまま使った。各最終runは **1 test / 24 assertions成功**。戦闘は両版ともvictory・12round、同snapshot/hashだった。戦闘stubで勝敗を置き換えていない。

base→combinedの初回pairに加え、単回時間が大きく上振れした箇所を切り分けるため、同fixtureでCPUを加えた **combined→baseの診断pairを1組だけ**追加した。診断pairの入力5表hashは一致。最終25表の全行hash、Turn metrics、戦闘snapshot、デコードした全応答値は、各版・両instrumentationの計4成功runで一致した。初回の外部probeだけはCarbon日時objectの参照同一性を誤って要求し失敗し、値比較へ修正してから両最終pairを実行した。失敗logも保全し、製品の不具合と扱わない。

Boost管理応答だけはJSON objectのmember順が異なり、raw JSON hashは一致しない。`occurrences`が`current_turn`等の後へ移る差で、デコードした値・occurrence配列順は同じ。現在の管理UIはfield名で参照しており、応答全体のmember順に依存しない。raw hashが全件一致したとは主張せず、比較器でobject順とlist順を区別した。

同じSQL件数は両pairで再現した。下のwall/CPUは診断pairの1組で、改善率の推定ではない。

| 区間 | SQL base→combined | wall ms base→combined | PHP CPU ms base→combined |
|---|---:|---:|---:|
| 初回コマンドHTTP登録 | 51→51 | 79.041→76.398 | 59.491→56.894 |
| bulk HTTP登録＋応答 | 185→127 | 165.397→119.166 | 98.043→69.067 |
| 30計画GET | 74→16 | 81.235→37.654 | 54.143→30.600 |
| Boost管理projection | 7→3 | 5.486→3.885 | 4.098→3.141 |
| 32×32公式Turn | 228→228 | 330.112→290.169 | 238.751→205.371 |
| Turn後queue GET | 72→16 | 74.771→30.885 | 49.560→24.198 |
| 実地底探索HTTP | 39→39 | 75.785→59.265 | 62.612→47.411 |
| 同request再送 | 20→20 | 14.982→13.704 | 11.327→9.363 |

初回pairでは32×32 Turnが340.277→764.005ms、地底探索が77.399→164.989msへ上振れしたが、逆順診断では上表の通りで約2倍の増加は再現しなかった。共有ホスト・cold/warm/allocator/計測のばらつきを分離する統計試験ではなく、これ以上の反復はしない。成熟fixtureの結果と合算した倍率も出さない。

この横断fixtureは地底解放済み状態を既存helperで用意し、既存のtrusted server-issued admission test adapterを使う。introの全UI操作、実admission発行、browser、HTTP負荷/競合を検証したとは言わない。比較対象25表には資産・日課・Pd ledger・queue・地底記録・監査を含め、auth情報は取得しない。

## 同process・workerのcache寿命

[詳細](integration-evidence/battle/report.md)、[scope比較](integration-evidence/battle/scope-comparison.json)。基準/統合で外部probe各15項目成功、catalog・通常HP戦闘・変更HP戦闘・itemの4hashが一致した。DB connectionは両版とも0件。

同じcatalogを使うA戦闘→別HPのB戦闘→A戦闘で、Aの同seed全結果が再現した。別Lvのplayer statsも最新値を反映した。item COW、個体経済値、動的subclass overrideが他instanceへ混入しない。

実際の `app('queue.worker')` にLaravelが登録したresetScope Closureを呼び、scoped markerとPlayerCatalogが破棄されることを確認した。frameworkのdaemon loopがjob予約前にこのcallbackを呼ぶ経路はsourceで照合した。手製のreset関数へ置き換えていない。一方、daemonの常駐・実job配送・失敗/restart/signalは実行していない。

統合版の既存freshness/authoring3代表＋item2代表も同一PHPUnit起動で実行し、**5 tests / 38 assertions成功**。同instance設定変更、同mtime/サイズのmanifest差替え、無効JSONのfail closedとscope切替を含む。

repositoryの起動構成はApacheとone-shot Turn cronで、Octane/Horizon依存・常駐worker起動指定は見つからない。これはsource構成上の確認で、本番process/cron観測ではない。定数由来SecretaryItemCatalog cacheはscopeを跨いで残してよいが、個人HP/RNG/所有rowを保持しないことを区別した。

## 残る限界と保存物

- 固定6head/上記fixture・代表だけの結合検証。全suite body、全command/全boss/全状態、性能の統計的評価、本番PHP8.5.12、高並行負荷、実job配送、実本番は未確認。
- 結合版の新規CIはない。個別6headのCI成功、結合local結果、本文書PRのCIは別の証拠。main・実DB・schema・Ruleset・製品コード・handoffは変更しない。
- 今回、実装branchを修正する不具合は確認しなかった。Boostのobject member順差とprobeの日時比較訂正は上記の通り明示した。
- [完全なprobe・raw状態・ログZIP](integration-evidence-20261031.zip)と[manifest](integration-evidence/MANIFEST.json)を保存。閲覧用reportのPythonリンクは`.txt`、CSV改行はrepository text正規化、ログは末尾空白だけを整える。完全bytes照合はZIPを展開して行う。

ZIP: 56files / 1,036,396 B、SHA-256 `2e8e44081e21c6fdbac4b95f37ea1936f76802665003b277d2bbb58b3af60a6b`。元の監査・実装証拠ZIPは更新せず保全した。

## English

The fixed six-patch tree passed full application static analysis, changed-file formatting, shard assignment and identifier checks. A matched mature-world turn completed all 12 phases with identical normalized inputs/final state across 84 gameplay tables, 1,131 turn audit rows and 211 recorded draw fields. Four focused combined checks also passed. SQL stayed at 3,355; the single wall/CPU measurements do not show a whole-turn speedup.

A single-application journey executed command HTTP submission, bulk insertion, an official turn, real underground combat and idempotent replay. Both paired runs preserved 25 final-state table hashes and all decoded response values. SQL reductions repeated; short wall timings varied, so no production speedup ratio is claimed. Boost admin JSON member order differs, while values and list order match.

Real Laravel worker scope-reset callbacks, mutable HP/level isolation, seeded A–B–A replay, catalog freshness and item copy isolation passed. Actual queue delivery, Octane, concurrency, production processes, a full-suite run and production PHP8.5.12 remain untested. No implementation branch or production data changed; complete evidence is preserved in the ZIP.
