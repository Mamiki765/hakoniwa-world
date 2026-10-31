# 海底消防の近傍候補取得

基準: main `2e93ec918774ad64d787aba99fc59a7bc3e54d16`（5.8.3 / Ruleset v41）。

## 変更と維持する意味

`DisasterTurnService::protectUnderseaFire()` は、火災成立後に全Worldのcell indexをidでsortし、近くの消防施設を探していた。既存の座標indexから設定半径内のcellだけを取得し、同じid昇順で既存判定へ渡す。

`DisasterMutableCellIndex::cellsWithinRadius()` は同じMapCell参照を返す。owner・施設・Nation残高は各火災の時点で検査し、同じownerの最初の支払可能な消防だけが1回支払う。火災抽選、消費順、SQLのlock、indexなしのSQL経路、監査event、Rulesetは変えない。

全cell数をC、半径をr、半径内cell数をKとすると、候補処理は全sort/走査の `O(C log C)` から `O(r² + K log K)` になる。現在の半径2ではindex lookup対象は19cell。消防・ownerで先にcacheせず、直前の施設破壊も観測する。

## 検証

- 隔離PG18.4 / PHP8.5.8 / current lock依存。focused **9 tests / 62 assertions成功**。既存の範囲・費用・重複・foreign・地上不保護・海底都市case、追加のid順/live参照、逐次火災の資金枯渇・施設破壊・event順を確認。
- 追加した逐次火災caseは基準版の2runtimeファイルをreadonly preloadしても **1 test / 12 assertions成功**。
- 変更4PHPのPint、2runtimeの構文確認、project相当のLarastan level6、`git diff --check` 成功。隔離containerへproject PHPStan設定が未配置だったため、同じ設定のpathだけ絶対pathへ解決して使用した。

スケール確認は実際のindex・Eloquentモデルで、2消防候補のid順が一致する合成CPU probe。20回の候補取得・選別を3回測定した中央値:

| indexのcell数 | 従来の全sort/走査 | 半径lookup |
|---:|---:|---:|
| 1,024 | 542.288ms | 5.533ms |
| 9,216 | 7,429.289ms | 5.523ms |

PHP8.5.8、CLI opcache off、共有開発host。DB・支払・火災抽選・全Turn時間を含まない。実際の効果はこの経路に到達する海底火災数に依存し、0件なら効果も0。PHP8.5.12や本番時間の改善率を保証しない。測定script/JSONと試験ログは作業成果物の `turn/evidence/fire-station-*` に保全した。

## 既存PRと残候補

- #344固定head `ded9dc3edd50108aaa3ea9684c2522b973d1759d` の同service/Feature差分は中央施設爆発ログ周辺で非重複。両patchの `git apply --check` は成功した。統合後の機能試験は未実施で、今後のhead更新時は再確認する。
- #340（成長の重複保存/deferred writer）、#342（Turn順）、#343（領土処理）、#345（集落stage）を再実装しない。
- T12のchunk version一括更新は、現行model/providerに専用observer、schemaにchunk triggerがないことまで静的確認した。ordered lock・欠損確認・全件+1・affected rowsを守れば16→1 UPDATEの余地があるが、実測前後比較は未実施。#340/#345と同じCompleteTurnEngineへ変更を混ぜず別候補に残す。
- T03油田の残高再SELECT、T04食品残高batch、T09沈下のowner別snapshotは候補を維持する。資産lock順/最新状態や沈下freezeの検証が未完で、この近傍取得の変更には含めない。RNG変更も含めない。

## English summary

Undersea fire protection now reads only indexed cells within the configured hex radius, keeping the existing ascending cell-ID order and live model references. Ownership, facility state, current funds, payment, locking, fire order, RNG and events retain their existing behavior. The SQL fallback is unchanged.

Focused checks passed: 9 tests / 62 assertions; the new sequential-fire case also passed against the baseline runtime (1 test / 12 assertions). The synthetic CPU probe above measures candidate selection only, with PHP8.5.8 and actual Eloquent models. It does not predict whole-turn or production speed. PR #344 touches separate blast-log code; both overlapping-file patches passed an application check, but combined-runtime tests have not been run.
