# hakoniwa-world 現在地：4.4.0 / PR #167 全体独立レビュー

更新：2026-09-23 JST。Ownerの明示依頼による文書のみの `[skip ci]` 更新。実装・merge・本番適用・検証結果を区別する。

## 0. 次のチャットはここから

**最優先は `release/4.4.0 → main` の PR #167 の独立レビュー。#158〜#166はreleaseへ取り込み済み。旧記録の「#161未merge」「E/F/G/H未実装」を現在地として扱わない。**

| 固定点 | 値 |
|---|---|
| Repository | `Mamiki765/hakoniwa-world`（GitHubが開発正本） |
| Release PR | [#167 Release 4.4.0](https://github.com/Mamiki765/hakoniwa-world/pull/167)、この更新開始時はopen・main未merge |
| PR base | `main` / `17501b8ba42c7b6450f14f82764996e75a728c1d` |
| 実装の固定HEAD | `0a3eb98508812bb5f95e904267d56c54390f6715`（#166 merge） |
| 作業branch | `release/4.4.0`。今回の文書commitは上記実装HEADの子孫 |
| Release CI | [Quality #584 / 35850787651](https://github.com/Mamiki765/hakoniwa-world/actions/runs/35850787651)、実装HEAD `0a3eb985...` / PR #167に対してSUCCESSをGitHubで確認 |
| 統合前migration PR | #166、レビューHEAD `2c54c29162a9d9173c15fa15573807f4489d8195`。Quality `35849287596` SUCCESSは前会話で確認 |
| Productionの最後の確認 | 実装担当のOCI read-only報告：checkout `3f49819fb764b5ac2e9fc49f95f6795ded7754d2`（4.3.2）、migration最新 `2026_09_18_000000_allow_configured_trial_reward_lengths` / batch 42。今回の文書担当自身がOCIへ再照会した値ではない |
| 本番適用 | 4.4.0未適用との上記観測。deploy直前に再確認する。GitHubのmain SHAとDB適用状態を同一視しない |

この文書を追加したHEAD自身は `[skip ci]`。`0a3eb985...` のCI成功と文書commitの差分を分ける。現在HEADが動いていたら、最初に差分を確認してレビュー対象SHAを明示する。CI成功だけで全体独立レビュー済みとしない。

## 1. Releaseの範囲

| 区分 | 現在地 |
|---|---|
| B / #158 | Secretary surface state分離、地上/地下のロック境界を整理 |
| C1 / #159 | 同一起動内の限定的なPostgreSQL競合retry |
| D1 / #160 | 地下receiptの恒久rollup・verified境界 |
| D2〜D4 / #161 | 資産・初回事実の恒久化、24時間request受付、明示purge |
| E / #162 | 管理ページ、配布倉庫、島整理、ログpreview、World予定起点と手動Turn |
| G / #163 | 共鳴結晶・竜ユニーク武器の基盤 |
| H / #164 | バハムル、魔石研磨、入場用輝石とショップ等 |
| F / #165 | 地上Item、チケットガチャ、オリジナル記念碑。最終レビューHEAD `d182e7468a88a3b6f3a6f56302c04095affbcf30` |
| Release内rebase / #166 | 未適用のmigration17本を `2026_09_23_030000_install_4_4_0.php` 1本へ統合。Rulesetは元からv26→v27の1世代で、追加世代は作っていない |

#166で旧17本→統合1本のPostgreSQL schema/代表データ比較、fresh、注入エラー時rollbackを実施したとの担当記録がある。先行案 `13a80e6` を取り込んだmerge `2c54c29` は、検証済み `36539d8` から文書1枚だけのtree差分だったことを前会話のGitHub比較で確認。runtimeを先行案へ巻き戻したものではない。詳細は[統合記録](../releases/4.4.0-migration-consolidation.md)。

## 2. 取り違えを再発させないOwner確認

- **ドキドキはHQ70% / Artifact30%。Relic30%ではない。** わくわくはRegular68.95% / HQ29.55% / Artifact1.5%。これは現在の調整値であり永久固定のassertionにしない。Relic景品・合成は今回未実装。将来のドキドキRelicは数%程度の激レア構想であり、30%の仕様は存在しない。
- **ニョワミヤリボンの出現率bonus、服の上位派生、シヴァの機械弓系0.4倍トドメはOwner許可済み。** 旧表の「未決」「候補」だけからP2へ戻さない。服のフレーバーは後で変更できる。
- 白旗は防衛施設owner側の装備効果。攻撃者の装備効果ではない。空parametersも正規の無引数effectとして受理する。
- #165の新弓RNG許可リスト漏れは `d182e746...` でstable keyの形式検証へ修正。`old_bow`の旧stream identity分岐は現状保持。全弓処理の掃除は今回の追加仕様にしない。
- お守りは開始snapshot→TurnStateで回数消費→終盤batch反映。被害セルごとのItem SELECT/UPDATEへ戻さない。
- バハムルは専用の「着弾済み」AI条件を追加しない。既存条件の融通の利かなさも攻略の一部。低Lvの敗北は主に全滅、HPによる死闘感も許容する。

詳しい経緯・現在仕様・留保は[実装handoff](development-history-and-current-handoff.md)。

## 3. 並行レビュー

Ownerは別チャットでの独立レビューも行う予定。双方が同じ実装SHAを固定し、通常は読み取り専用で行う。handoff編集・runtime修正・CI起動を並列に重ねない。結果はP0/P1/P2、具体的な到達条件、該当箇所、確認方法、未確認を分けてPRまたはOwnerへ返す。全体レビューの結論は、この文書の更新時点では未確定。

PRごとの成功を読むだけでなく、B/C1のlock/retryとD/E/F/G/Hの追加経路、receipt削除後の再送・装備出所、報酬と入場消費、snapshotのshape、owner主体、新keyのconsumer/RNG/validator、N件read/update、統合migrationとfreshを接続して読む。旧案に沿った誤指摘や、設定値の写経test増殖はしない。

## 4. 本番切替と先送り

**4.4.0は計画停止cutoverが必要。** 旧Secretary列をDROPするので、旧webのGET・旧Turn・関係workerが動いたままmigrationしない。backup/preflight/進行中・未解決Turnの確認、旧処理停止、統合migration、新image切替、smoke確認、復帰を運用手順で行う。PRのmerge許可と本番操作許可は別。

Worldの予定起点は自動推測しない。本WorldのOwner確認値はTurn1=`2026-08-06T00:00:00+09:00`。schedule-initのpreview/applyと本番承認は[管理運用](../operations/4.4.0-admin-operations.md)を参照。今回設定していない。

| 先送り | 境界 |
|---|---|
| C2 | 後続cronによるfailed/blocked Turnの自動retry。時刻/Turnずれの方針未決。C1とは別 |
| 公開後baseline rebase | **4.4.0公開後**に旧version互換・累積migration・旧upgrade-chainを整理する別作業。今回の17→1統合と混同しない |
| test/validator大掃除 | 過去事故の重複再現、catalog全件・balance値の写経等。今回のreleaseへ全面整理を混ぜない |
| A / Agent・CI全面改修 | 別作業。CIを探索器として反復起動しない |
| 定期preview・purge | 実装と本番cron有効化を区別。自動DELETEや本番purgeは未承認・未実行 |
| Future content | Relic合成/景品、試練3、共有HP raid等。試練3の想定はLv650・初級3結晶で、中級1攻略必須にしない |
| 別system/既知残件 | MCP-F1/M8/M9/M10、Safari N19は別件 |

## 5. 読む入口

[current-status](current-status.md) → [実装handoff](development-history-and-current-handoff.md) → 必要なcode/architecture/operationsのみ。9/22の[会話記録](conversation-2026-09-22.md)・古いblueprint・停止チェックポイントは履歴で、現在の実装を未完成へ戻す根拠にしない。

今回の許可はhandoffの文書commitと独立レビュー。main merge、deploy、DB変更、purge、補填、cron登録は行わない。
