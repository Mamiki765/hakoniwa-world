# release/3.8.0 review fix checkpoint

記録時刻: 2026-09-09T08:15:14+09:00  
状態: **READY_FOR_SOL_VALIDATION**

## Git境界

- release base: `368eadf919b599f104e1cbc35d3d8604733f5284`
- reviewed base: `9f2449640f510b57130f8d144c0ef5a5b588b90b`
- focused検証済みimplementation commit: `785afc14c62b1740fe748aa4a9e14df595560eff`
- branch: `release/3.8.0`
- push先: `oci-offline` の `refs/heads/offline/release/3.8.0`
- `offline/main`は変更しない。

変更は54ファイル。正確な一覧は次で取得する。

```bash
git diff --name-only 9f2449640f510b57130f8d144c0ef5a5b588b90b..785afc14c62b1740fe748aa4a9e14df595560eff
```

主な範囲はparty combat/runtime/projector、貸出snapshot/cache/候補取得/lock、画像6枠と保持参照、VueのPT表示、migration、focused tests、parallel-test証跡処理。`product/docs/manual/`はreviewed baseから変更していない。既存の未追跡`.codex-tmp/`、`product/tests/.codex-tmp/`、`artifacts/`はcommit対象外で、削除もしない。

## Review項目

| ID | 状態 | 根拠 |
|---|---|---|
| R01 | fixed | 固定actor情報とinitial/previous-round-end/final stateを分離。時点表示も明示。 |
| R02 | fixed | 実engineのdecision/action keyを技名、actor/target、MP cost、回復・蘇生へ投影。 |
| R03 | fixed | 反撃・吸収を含む副作用が生成元のactor/target IDを保持。 |
| R04 | fixed | 標準PTヒーラーは味方HP条件を使用。custom `own_hp_lte` とsolo意味は維持。 |
| R05 | fixed | leader Lv/slot IL上限等に結び付く同期済みprojection cacheを追加。表示変更では数値再生成しない。 |
| R06 | fixed | 通常stateから候補全件読取を外し、20件cursor pagingの公開概要APIへ分離。 |
| R07 | fixed | source snapshotを短い整列lockで確定後にleader transactionへ渡す。相互借用と同一秘書同時利用を確認。 |
| R08 | fixed | request準備からbattle log期限まで画像path leaseを保持し、参照消滅後だけ削除。永久保存しない。 |
| R09 | fixed | 保存済み画像も現在viewerのAI画像/fallback設定でfilterし、内部pathはresponseへ返さない。 |
| R10 | fixed | 選択済み枠と候補を分離し、貸出OFF・候補0件でも解除可能。 |
| R11 | fixed | request ID、狩場、borrowed IDsを同じpending intentとして固定。確定回収後だけ新intentを開始。 |
| R12 | fixed | solo/trial/partyの大絵をstart/awakening/final eventへ限定。通常roundはcompact card。 |
| R13 | fixed | skip結果へ複数drop、獲得/取り逃し、残ticketを既存settlement結果から表示。 |
| R14 | fixed | ゲームUIの所持・解禁進捗・消費数表示を実契約へ整合。ゲーム内manual本文は今回変更しない。 |
| R15 | fixed | 覚醒表示可能なcombatantだけゲージを表示し、ReadyとAwaken!を区別。 |
| C01 | fixed | test DB/config cleanupと結果artifactを分離し、SHA/dependency/shard exit/time/count/log/JUnitを保存。secret環境値は保存しない。 |

## Focused検証

下記はdevelopment containerまたはlocal frontendで、implementation tree `785afc14…` と同一のsourceに対して実行した。出力はこのCodex task transcriptにあり、repository内へ全CI証跡を偽装していない。

| 対象 | 結果 |
|---|---|
| party combat/projector + solo laboratory | 21 tests / 244 assertions PASS |
| 画像slot/credit/visibility/retention | 7 tests / 130 assertions PASS |
| borrowed cache/candidates/supported upgrade | 5 tests / 120 assertions PASS |
| old v1/v2 log + fresh install | 2 tests / 174 assertions PASS |
| reciprocal borrow + same-source concurrent borrow | 2 tests / 17 assertions PASS |
| skip settlement/idempotency | 5 tests / 32 assertions PASS |
| parallel evidence manager | 17 tests / 33 assertions PASS |
| focused Vitest (`App`, image editor, party presentation, pending request) | 4 files / 54 tests PASS |
| reviewed `9f244964…`とのsolo `toArray()`比較 | 4/4 cases exact match |
| Laravel Pint | changed PHP 38 files PASS |
| ESLint | changed TS/Vue files exit 0 |
| Bash syntax | `tests/scripts/run_parallel_tests.sh` exit 0 |

初回のWindows sandbox内VitestはVite子processの`spawn EPERM`で起動失敗し、同じfocused commandを許可済みlocal環境で再実行した。旧expectationと画像path保持のfocused failureは、pending intent・時点表示・内部path非公開の契約へ修正後に上表の結果まで再実行済み。

実ブラウザではreal-engine fixtureを使い、320×812・文字125%でplayer 4枚が2×2、enemyが下、長いHPが省略されず、MP上限非表示、Ready/Awaken!分離、大絵9 event（start 4 / awakening 1 / final 4）を確認した。previewはtest用で、production assetやplayer dataを使用していない。

## Persistenceとidentity

- forward migrationを2本追加: lending buildのbounded projection cache、battle log期限付き画像参照lease。
- fresh installとsupported 3.7.3 upgradeをfocused確認。既存migrationは変更していない。
- Ruleset version/payload変更なし。content reward authorityとenemy countは分離したまま。
- solo combat式/RNG/結果identityは変更なし（4ケース完全一致）。既存presentation log v1/v2は再計算せず、party v3を維持。
- 画像lease keyはprofile-scoped request identity、cache fingerprintはsource build・leader Lv・対応slot IL上限・計算世代に限定。

## Phase Bで未実行

Phase Aの停止条件に従い、repository-wide PHPUnit、全frontend suite、PHPStan全体、typecheck全体、production build、大規模simulationは未実行。Solはまずこのmemoのimplementation SHAと実HEADを照合し、source変更なしで次を一巡する。

```bash
HAKONIWA_TESTED_SHA=<candidate-source-sha> bash tests/scripts/run_parallel_tests.sh 16
composer analyse
npm test -- --run
npm run lint
npm run typecheck
npm run build
```

失敗時は該当範囲だけ修正・再検証し、新SHAの結果へ旧SHAの結果を付け替えない。production deploy、migration apply、player data変更、Turn run/retry、補填は行っていない。既知の未解決P0/P1/P2はないが、全CIとWeb ChatGPT独立差分レビューは未実施でありdeploy-ready判定ではない。
