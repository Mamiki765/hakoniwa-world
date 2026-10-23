# TurnのマスID取得に伴うモデル生成の削減

2026-10-23。基準は未mergeの[PR340](https://github.com/Mamiki765/hakoniwa-world/pull/340)、固定HEAD `8509bd5c3c6666db580c6fda2a9c7ce986316642`。main `12a4f242028e4d0bd8b8db118097762c4214b264` の5.8.2とPR340の保存batchを前提とする。PR338のtimeout/recoveryは含めない。

## 原因と変更

`TurnOrderService::stableSurfaceCellIds()` はIDだけを取得するが、Laravel 13.22.0のEloquent `pluck()` はcast対象の列について `newFromBuilder()` を呼ぶ。MapCellの主キーは整数castを持つため、60×60で3,600モデルを生成していた。

SQLの絞り込み・並び順を維持したまま、取得を `toBase()->pluck()` にする。返すIDは既存の `(int)` 変換を通す。shuffleの入力値・順序・呼び出し、ゲームルール、保存処理、transaction、World lock、二重実行防止には変更を加えない。MapCellにglobal scope、retrieved observer、after-query callbackの登録がないことを確認した。Ruleset・application version・migrationは変更しない。

## 隔離測定

ローカルLinux x86_64 Docker、PHP8.5.12、PostgreSQL18.4、Laravel13.22.0。専用internal network、公開portなし、tmpfs DBに合成fixtureのみを作った。本番DB・本番接続・backupは使用しない。既存task-3の成果は読み取り参照とし、source・測定出力はtask-6の専用領域に置いた。

同一PHPプロセスで両実装をwarmup後、交互に7回ずつ取得した。基準クラスは固定sourceのクラス名だけを変更した一時コピー。毎回ID配列を型と順序も含めて比較した。

| 60×60のID取得 | 基準 | 変更後 |
|---|---:|---:|
| 中央値 | 49.235ms | 4.169ms |
| 範囲 | 47.794–56.078ms | 3.804–7.066ms |
| MapCellモデル生成 | 3,600 | 0 |

この段階の約45msの削減が確認できた。ターン全体の比較値は、seed0で3.296→4.534秒、seed aで2.641→2.660秒と他フェーズの揺れが大きい。本番ターンの短縮率や、14–16秒全体への秒単位の改善は主張しない。

## 内容と乱数の比較

60×60-growth（町1,200・森1,199・平地1,200・首都1の既存fixture）で、同じ開始行・同じ64桁seedを両実装に与えた。時計を `2026-10-23T00:00:00+00:00` に固定し、外側のtransactionをrollbackし、sequence値を復元した。

両seedともpublicの全96テーブルの開始時・終了時内容が一致した。ID・日時・map_cellsの全列・nation/resource・イベント本文とmetadata・turn記録を保持し、件数だけでは比較しない。JSONをオブジェクトとしてdecodeし、objectのkeyとDB行の順序だけを整える。配列の順序と `{}` / `[]` の型は保持する。

除外はfixtureの `users.remember_token` と計測値 `turn_runs.phase_results.*.duration_ms` のみ。日時自体は除外しないが、固定時計での比較であるため、実時計下の保存時刻差を検証したものではない。計測出力・CPU sampleも同値比較の対象外。

一時的な読み取り専用mountでRNGを計装し、成功した全 `integer()` 呼び出しのstream key・上下限・結果を呼び出し順に保存した。shuffle内のdrawも含む。計装ファイルは製品差分に含めない。

| seed | 乱数呼び出し | SQL数（両実装） | 終了時内容のSHA-256 |
|---|---:|---:|---|
| `0` × 64 | 8,443 | 744 | `dd50fd029db666d0d5be72203082f981b685c48b2c8c96d7801a4e2dd6ab04bf` |
| `a` × 64 | 8,448 | 698 | `2d39fcf666b51e7d68bed5bc5933f4a94eb416aa787e18592759bf5cf74306b1` |

乱数列のSHA-256は順に `f1f3df70583c0fb738081bfb2e1ab11c3f332ba40aa74abf1b467e842f86b749`、`3d746830fbf31d804ceb6aaaeda885f54b8a29aa2fed177ce5143c44dfd5400d`。同seedの基準・変更後で完全一致。開始時内容の共通hashは `2faee903fbd8eb51d8abf6863efb930480d2f478173bbcb6f114f6c1695ebaeb`。

再現用の一時harness・snapshot・比較結果はtask-6の `evidence/` に保持した。元fixtureは `tests/Feature/TurnRuntimePerformanceTest.php::processCellProfileWorld`。本PRの回帰検証は既存 `TurnOrderServiceTest` に、ID取得時のモデル生成が0件であるassertionを加える。既存のseed別shuffle・再現性検証もそのまま実行する。全体回帰は固定HEADのQuality CIで確認する。

追加assertionは基準版で1,024モデルを検出して失敗し、変更後は成功した。`TurnOrderServiceTest` と `TurnRunnerTest` の `stable_inputs|retry_reconstructs|completed_world_target|postgresql_lock` を選択し、4 tests / 23 assertions成功。失敗retry・二重適用拒否・World advisory lockもこのfocused検証に含む。
