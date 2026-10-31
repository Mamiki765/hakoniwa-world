# PR #340 状態と現mainに未包含の差分

確認時刻: **2026-10-31 15:39:20 UTC**。GitHub APIのread-only取得と、既存local Git objectの `merge-base` / `diff` を照合した。製品・branch・runtime・CI・外部投稿は変更/実行していない。archive本文は閲覧していない。

**#340は既存の未merge PRであり、今回の確認を採用済み・main統合済みの証拠にはしない。** 固定headの成長保存改善2件は、以下の現mainにまだ含まれていない。

## 現在のGitHub表示

| 項目 | APIで確認した値 |
| --- | --- |
| 正確なtitle | ターン高速化: 成長マスの保存をまとめ書き（二重UPDATE解消・DeferredMapCellWriter） |
| URL | [PR #340](https://github.com/Mamiki765/hakoniwa-world/pull/340) |
| state / draft / merged | `open` / `false` / `false` |
| merged_at / closed_at | `null` / `null` |
| head.ref | `claude/project-thread-nq4b7c` |
| head.sha | `8509bd5c3c6666db580c6fda2a9c7ce986316642` |
| PR API base.ref | `main` |
| PR API base.sha | `12a4f242028e4d0bd8b8db118097762c4214b264` |
| 独立取得した現在のrefs/heads/main | `2e93ec918774ad64d787aba99fc59a7bc3e54d16` |
| API changed_files / additions / deletions | 4 / 271 / 4 |

取得根拠: [PR API](https://api.github.com/repos/Mamiki765/hakoniwa-world/pulls/340)、[files API](https://api.github.com/repos/Mamiki765/hakoniwa-world/pulls/340/files?per_page=100)、[main ref API](https://api.github.com/repos/Mamiki765/hakoniwa-world/git/ref/heads/main)。これらのURL自体は可変なので、上の観測時刻と固定SHAを伴って読む。

"merge_commit_sha"には `d0f395af12d3ed9e0e2db8dd72684684dd8c7cca` が返るが、`merged=false` / `merged_at=null` を確認しており、この値の存在をmerge済みの根拠にはしない。CI成功・今回の測定成功・ローカル結合も採用やmergeの証拠ではない。

## API base.shaと現mainは異なる

`git merge-base 2e93ec918774ad64d787aba99fc59a7bc3e54d16 8509bd5c3c6666db580c6fda2a9c7ce986316642` の結果は **`12a4f242028e4d0bd8b8db118097762c4214b264`**。これは今回APIが返したbase.shaと一致するが、現在のmain tipとは一致しない。

- base.refは引き続き`main`。API base.shaを現在のmain tipと黙って同一視しない。なぜこの値を返すかは今回の読取結果だけでは断定しない。
- 現mainには共通baseより後の #351 / 5.8.3 があり、PR headにはない。逆にPR側には成長保存2commitとmain取り込みmergeがあり、現mainにはない。
- `git merge-base --is-ancestor <head> <main>` と逆方向はいずれもexit 1。共通baseから両方へのancestor確認はいずれもexit 0。

現mainだけにあるcommit:

- `77ffc70bd0ef2d72a965d7bdb7dc16b47427d189` — fix: release 5.8.3 dormant nation charm turn completion
- `2e93ec918774ad64d787aba99fc59a7bc3e54d16` — Merge pull request #351 from Mamiki765/release/5.8.3

PR headだけにあるcommit:

- `5f9d67d2f14fe1a6c23f7e1b94371184bd06b700` — Skip the second map cell UPDATE when grown settlements change stage
- `ec963ef3374b2858b93b14983f682d1dbb6697eb` — Batch population and forest growth map cell UPDATEs
- `8509bd5c3c6666db580c6fda2a9c7ce986316642` — Merge origin/main (5.8.2) into claude/project-thread-nq4b7c

## 現mainに未包含のPR差分

`git diff <main>...<head>`（共通base→PR head）のnumstatはfiles APIと一致し、**4 files / +271 −4**。この4pathについて `git diff <merge-base> <current-main> -- <path>` はすべて空なので、PRの実差分が現mainで既に同じ形へ実装された、という状況でもない。

| path | PR変更 | 内容 |
| --- | --- | --- |
| `product/app/Application/CompleteTurnEngine.php` | +33 −3 | 通常人口増加のversion加算をstage判定前へ移し、stageありの2回目saveを省略。成長stage時version計+2を保持。process_cellsのbegin/flush/finally endと人口・森成長のdefer呼出を追加 |
| `product/app/Application/DeferredMapCellWriter.php` | 新規 +136 | population/terrain_quantity/versionの成長dirty行を最大500件ずつ保存。audit INSERT・非pending別cell単一UPDATE以外はbeforeExecutingで先にflushし、失敗scopeでpendingを破棄。flush共通updated_at |
| `product/tests/Feature/DeferredMapCellWriterTest.php` | 新規 +63 | 途中query前のflush、pure growthのbatch保存を確認する代表 |
| `product/tests/Feature/TurnRuntimePerformanceTest.php` | +39 −1 | population/forest growth fixtureの保存数・測定用代表を追加 |

固定file根拠:

- [product/app/Application/CompleteTurnEngine.php](https://github.com/Mamiki765/hakoniwa-world/blob/8509bd5c3c6666db580c6fda2a9c7ce986316642/product/app/Application/CompleteTurnEngine.php) — blob `a9de7f846e2749af11b98deac7deb4fb52222494`
- [product/app/Application/DeferredMapCellWriter.php](https://github.com/Mamiki765/hakoniwa-world/blob/8509bd5c3c6666db580c6fda2a9c7ce986316642/product/app/Application/DeferredMapCellWriter.php) — blob `d571e2192d90d541b6d13d5fa9619dcccc0eb272`
- [product/tests/Feature/DeferredMapCellWriterTest.php](https://github.com/Mamiki765/hakoniwa-world/blob/8509bd5c3c6666db580c6fda2a9c7ce986316642/product/tests/Feature/DeferredMapCellWriterTest.php) — blob `321c40d223fba5b16ba6b91f3608893ff6cd460c`
- [product/tests/Feature/TurnRuntimePerformanceTest.php](https://github.com/Mamiki765/hakoniwa-world/blob/8509bd5c3c6666db580c6fda2a9c7ce986316642/product/tests/Feature/TurnRuntimePerformanceTest.php) — blob `f385b95bf7a49ec0f540454407f3742e60290cc6`

RulesetVersionのJSON解読再利用は5.8.2として既にmain側にあり、#340の残る4file差分には含まれない。`git diff <current-main> <head> -- product/app/Models/RulesetVersion.php` も空。残っている改善は二重保存削減と成長write-setである。

head treeと現main treeを直接比較する `git diff <main> <head>` は9pathを返す。上の4pathに加え、現main側の5.8.3による `SecretaryTurnService.php`、`config/hakoniwa.php`、`docs/releases/5.8.3.md`、`DisasterAndOilTurnTest.php`、`SecretaryItemEffectsTest.php` の差を含む。この5pathはPRの新規保存改善ではない。古いhead全体へ置換すると現mainの5.8.3を失うため、今回の限定比較では共通base→headの4file patchを現mainへ適用する方法を採った。

## 掲載用の短い文書案

> #340「ターン高速化: 成長マスの保存をまとめ書き（二重UPDATE解消・DeferredMapCellWriter）」は、2026-10-31 15:39 UTC時点でopen・non-draft・未mergeです。固定head `8509bd5c3c6666db580c6fda2a9c7ce986316642` の二重保存削減と成長write-setは、現main `2e93ec918774ad64d787aba99fc59a7bc3e54d16` に未包含です。今回の調査はこの既存案を再検証したもので、採用やmerge済みとは扱いません。PR APIのbase.shaは共通祖先`12a4f242028e4d0bd8b8db118097762c4214b264`で、現在のmain tipとは異なります。

English: PR #340 remains open, non-draft, and unmerged at the observation time. Its pinned head's double-save removal and deferred growth writes are absent from the current main. The API's base SHA is the verified merge base, not the current main tip. Verification and local combination do not mean acceptance or merge.

## 限界

この文書はPR状態と差分の確認であり、新しい安全性review・runtime試験・全suite・CI結果を追加するものではない。GitHubの状態は観測後に変わり得る。本番適用状態、採用判断、merge許可は確認対象外。
