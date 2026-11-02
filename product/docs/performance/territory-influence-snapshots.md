# 領地感化の読み取りsnapshot

2026-10-23。基準は[PR342](https://github.com/Mamiki765/hakoniwa-world/pull/342)の固定HEAD `730a4dcda609db85e8e96e1e85de33f9c97465b3`。PR340・342の内容は変更しない。PR338のtimeout/recovery、版上げ、Ruleset変更、migrationは含めない。

## 全体プロファイルから選んだ範囲

PR342後の合成ターンでは `process_cells` がCPUの約58–59%、領地感化が約15–16%だった。JSON decodeは約0.6%で、先行cache修正後に同じ大きなhotspotは残っていない。inclusive比率は重複するため合算しない。

既存の60×60-mature fixtureは人口1,000の都市900マスを置き、初回に900件の段階変更を発生させる負荷試験である。今回は都市人口を現行ルールの都市最小人口（v41では10,000）に設定し、食料stockを十分にした60×60の合成fixtureも測った。段階変更0件、seed0で新しい村205件・火災9件。設定や本番データは変更していない。

このfixtureでも領地感化は約15%のCPUを使った。全3,600マスのEloquentモデルを作り、関係を解決してから属性を読む費用が大きいため、この1フェーズだけを対象にした。

## 変更と維持する境界

地形・施設のkeyをjoinした行snapshotを取得し、所有者の逐次変更をそのsnapshotへ反映する。ID順の取得、既存surface処理順、隣接snapshotの共有を維持するので、先に感化されたマスは後のマスのsourceとして新しい所有者を示す。

ロックは `FOR UPDATE OF map_cells` と明示し、従来どおり対象マスだけをロックする。定義表は読み取る。面積判定は既存 `NationLandAreaCalculator::isLandTerrain()` を使い、取得上限・首都周辺・休眠/回復保護・乱数抽選を変えない。

変更した行だけ既存MapCellモデルを生成し、イベントsubjectと宝物回収に渡す。join専用の2列はモデルへ渡さない。既存のまとめ書き・宝物回収・イベント順序・SQLの更新列は変えない。TurnRunnerのtransaction・retry・World lock・二重実行防止も変更しない。

## 計測

専用internal network、公開portなし、tmpfsの合成PostgreSQL18.4。PHP8.5.12、Laravel13.22.0、Linux amd64、Excimer1ms CPU sampling。CLI OPcache off。測定・sourceはtask-6専用領域、task-3は読み取り参照。本番接続・DBコピー・backupは使用しない。

初回クラス読み込みの揺れを分けるため、同じseedの通常TurnRunnerを1回warmupし、外側transactionをrollback、sequenceを復元、Worldを再読込してから測定した。測定対象にはQuery/model計数と乱数traceの共通負荷が含まれる。

| 合成60×60 | 基準 | 変更後 |
|---|---:|---:|
| seed0 領地感化 | 225.490ms | 39.673ms |
| seed a 領地感化 | 225.520ms | 39.520ms |
| seed0 全ターン | 1,616.459ms | 1,536.731ms |
| seed a 全ターン | 1,681.227ms | 1,422.873ms |
| 領地感化SQL | 7 | 5 |
| 領地感化MapCell生成（所有者変更0件） | 3,600 | 0 |

seed0は基準→変更後、seed aは変更後→基準の順で測った。領地感化は両方で約186ms減ったが、他フェーズには揺れがある。全ターンの値はこの2組の合成測定であり、本番の短縮率や常に同じ短縮量を保証しない。cold測定は2.043→2.429秒と初回読み込みの揺れが大きく、全体の効果判定には使っていない。

## 内容・イベント・乱数

時計を `2026-10-23T00:00:00+00:00` に固定し、同じ開始DB・同じseedから全96テーブルの開始/終了内容を比較した。JSON objectのkeyとDB行の順だけを整え、配列順序・型・`{}` / `[]`・IDを保持した。除外はfixtureの `users.remember_token` と計測 `turn_runs.phase_results.*.duration_ms` のみ。実時計での日時精度を検証したものではない。

64桁のseed0とseed aで、全内容・イベントpayload・全9,044 / 9,049回の整数乱数呼び出し列（stream key・上下限・結果・呼び出し順）が一致した。終了時内容のSHA-256は順に `de6d77bf1d9c9cddc9affb11517f93b61ffc6c7a840909b4da005b4dfb6b3855`、`9b762f80c126f1a87565ce974a429a7db9852bf69ff57316bd45d486535dc14f`。

さらに既存領土テストのhelperから、2国の3マスを使う逐次変更fixtureを作った。中間マスの所有者変更が左隣へ伝播する2回の感化と、付属する宝物1件の回収・item付与を実行し、全96テーブル・イベント・2回の乱数呼び出しが一致した。このケースだけは、既存の `persistMutations()` がCarbon時計ではなくPostgreSQL `CURRENT_TIMESTAMP` を使うため、**変更した2マス（合成ID1・2）の `updated_at` だけ**を追加除外した。raw出力には実値を残し、それ以外の差分がないことを確認した。更新SQL自体は変更していない。

逐次変更harnessの初回traceはfixture準備のdrawを含んだため、計測開始前にtraceをclearして同じ開始行で取得し直した。初回artifactは残すが同等性の証拠には使わない。

raw snapshot、乱数trace、CPU sample、harness、比較hashはtask-6の `evidence/`、集約は `territory-comparisons.json` に保持する。既存の領土policy・逐次解決・保護・上限・大量変更テストを選び、既存性能テストにはモデル生成が変更マス数を超えない上限を加える。固定HEADのQuality CIとCodex reviewを最終確認に使う。

ローカルの `TerritoryInfluencePolicyTest`、`TerritoryExpansionAndInfluenceTest`、`TerritoryInfluencePerformanceTest` は **33 tests / 163 assertions成功**。変更PHP2ファイルのPint確認、領地感化ファイルのPHPStan level6、`git diff --check` も成功した。
