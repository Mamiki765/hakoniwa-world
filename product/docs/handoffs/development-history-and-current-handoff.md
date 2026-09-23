# hakoniwa-world / hakoniwa-mcp 実装・運用handoff

更新：2026-09-23 JST。Ownerの「#167のhandoffをCI skipで更新し、独立レビュー」依頼による文書更新。ゲームコード・本番状態・運用権限を変更しない。

## 0. 正本と現在地

再開入口は[current-status.md](current-status.md)。本書はrelease境界とOwner判断、既知の運用残件を保持する。詳細実装はcode、architecture、operationsへ戻る。古いproposal、停止チェックポイント、過去レビューを現在の仕様や未解決findingへ自動昇格しない。

GitHubで確認したPR #167は `release/4.4.0 → main`、base `17501b8ba42c7b6450f14f82764996e75a728c1d`、実装HEAD `0a3eb98508812bb5f95e904267d56c54390f6715`。#158〜#166を取り込んだrelease全体をレビューする段階である。#167の[Quality #584 / 35850787651](https://github.com/Mamiki765/hakoniwa-world/actions/runs/35850787651)はこの実装HEADでSUCCESS。この文書を足す `[skip ci]` HEAD自身のCI成功とは区別する。

今回の全体独立レビュー結果は更新時点では未確定。Ownerは新チャットでも並行レビューを予定している。双方は実装SHAを固定し、結果を独立に報告する。文書編集、実装修正、CI起動を重ねず、main merge/deployを先取りしない。

旧版の詳細な証拠は[更新前handoff](https://github.com/Mamiki765/hakoniwa-world/blob/0a3eb98508812bb5f95e904267d56c54390f6715/product/docs/handoffs/development-history-and-current-handoff.md)、[9/21会話](conversation-2026-09-21.md)、[9/22会話](conversation-2026-09-22.md)を必要時のみ参照する。本書で「担当報告」としたDB実測を文書担当の独立再実行と扱わない。

## 1. 4.4.0の到達点

| 区分 | releaseへ統合されたもの |
|---|---|
| B / #158 | Secretary surface state分離。旧 `secretaries.monster_experience/equipment_version` を専用rowへ移す |
| C1 / #159 | 同一起動内のDB競合retryとWorld lock sessionの維持 |
| D1 / #160 | 地下receiptの恒久rollup、verified prefixとraw後続の統計 |
| D2〜D4 / #161 | 装備出所・貸出累計・初回事実の恒久化、24h受付、明示purge |
| E / #162 | 管理UI、User単位の配布、島整理、ログpreview、予定起点・手動Turn |
| G / #163 | 共鳴結晶・竜ユニーク装備 |
| H / #164 | バハムル、魔石研磨、輝石入場・ショップ等 |
| F / #165 | 地上装備、チケットガチャ、個人記念碑 |
| #166 | 未適用4.4.0 migration17本を単一release migrationへ統合 |

#161の旧Quality #575（422/409のfixture/受付境界）や、Hの技欠落・未成功testは過去時点の記録。現在の未解決として再掲せず、今回の固定HEADと全体CIで確認する。E/F/G/Hを新規着手し直さない。

## 2. Productionと二種類のrebase

### 2.1 起点と今回の17→1統合

実装担当が2026-09-23にOCIをread-onlyで確認した報告では、production checkoutは `3f49819fb764b5ac2e9fc49f95f6795ded7754d2`（4.3.2）、migration台帳最新は `2026_09_18_000000_allow_configured_trial_reward_lengths` / batch42。4.4.0の17本は未適用。#166本文と[統合記録](../releases/4.4.0-migration-consolidation.md)に記録されている。今回の文書担当自身によるOCI再観測ではなく、deploy直前にも再確認する。

#166の対象は未適用の4.4.0追加分だけ。最終migrationは `product/database/migrations/2026_09_23_030000_install_4_4_0.php`。4.3.2以前のmigration/schema dumpは保持し、最後に既存 `Ver440RulesetUpgrade` でv26→v27を有効化する。Rulesetは元から+1世代であり、17本と17世代を混同しない。

担当報告では、隔離PostgreSQL18.4でfresh、同一4.3.2データから旧17本と統合1本のschema/代表資産/進行/v27 digest一致、注入エラー時rollbackを確認。focused5件87assertions等を実施。#166固定HEAD `2c54c29162a9d9173c15fa15573807f4489d8195` のQuality `35849287596` は前会話でSUCCESSを確認した。

先行案 `13a80e6` と検証済み `36539d8` をmergeした点は、前会話のGitHub比較で `36539d8 → 2c54c29` のtree差分が文書1枚だけと確認済み。検証済みmigrationを未検証案へ戻したものではない。#166 mergeは `0a3eb985...`。

### 2.2 公開後baseline rebaseは別

今回の「release内部rebase」は上記17→1で完了した。一方、**4.4.0公開後に4.4.0を新baselineとして、過去version互換、古いupgrade-chain、累積migration、不要test/validatorを整理する作業**は後日。今回v26以前を削除しない。

地下施設はWorld Rulesetの一部だが、地下RPGには既存のruntime/combat/equipment/generator/balance identityがある。今回のmigration統合を口実に、保存済み装備やreceiptの解釈に必要なidentity・legacy catalogを同一番号へ潰さない。

### 2.3 一度の計画停止cutover

旧Secretary列をDROPするため、旧web/旧Turnと新schemaの混在は避ける。maintenance表示だけでなく旧GET/worker/cronと進行中処理を止め、backup・preflight・未解決Turnを確認してから新imageで統合migration、切替、smoke、復帰を行う。統合migrationのSecretary排他lockはtransaction終了まで続く。runbook確定と本番実行は独立したOwner承認が必要。

4.4.0正常稼働の確認前にpurgeを走らせない。本PRのmergeは本番migration、配布、purge、cron登録を実行する許可ではない。

## 3. B/C1と過去のTurn560

2026-09-21のTurn560停止はOwnerログで復旧済み。maintenance下のmanual retryでrun560 completed/current_turn560/attempts4、`artisan up`成功が提示された。未復旧へ戻して余分なTurnを実行しない。

当時のSQLSTATE40P01と親Secretary/FK lock競合は設計調査の背景。相手側SQL未取得のため、特定地下APIの実lock cycleを確定したとはしない。`KEY SHARE` と `NO KEY UPDATE` は共存し、identity FKがあるだけで別DB/User-root化が必要という以前の説明は訂正済み。

所有は `User → Secretary → UndergroundProfile` を維持。島破棄・再作成で秘書/地下進行を消さない。`nation_underground_facilities` はNationの施設であり、RPG profileと混同しない。ロックの詳細は[secretary-lock-boundaries](../architecture/secretary-lock-boundaries.md)。

C1は同一起動のtransaction本体の40P01/40001を限定再試行し、完全rollback、同run/target/ruleset/seed、PHP状態再構成を守る。World lock取得write PDOを保持し、BEGIN後・game state前に照合し、接続変更時は再取得して続行せず停止する。後続cronがfailed/blockedを再開するC2は時刻/Turn遅延の方針が未決で保留。詳しくは[C1記録](../releases/4.4.0-turn-resilience-c1.md)。

## 4. Dの恒久化・統計・再送

- generated装備のsource ID値/出所CHECKを保持し、削除可能receiptへのlive FK/CASCADEを切り離す。既存装備の再抽選・再配布をしない。
- 貸出累計は既存券残高rowの `lifetime_participation_count`。初回growth/tutorial/Trial文は恒久progressへ保存。後日のrespecを初回選択と推測しない。
- D1は恒久rollup＋verified境界より後のrawを同じSQL snapshotで読む。D4はverified prefixの明示applyのみでpurge。pin/進行中/未準備/恒久事実不足で止まり、貸出participation/party snapshotの削除と経済ledger保持を区別する。
- D3はserver生成UUID・profile・HTTP operation・issued/expires/versionに結び付けた24h受付。期限後の既存結果retryと、未知/削除済みrequestを区別する。受付は入口とprofile lock後で確認する設計。実経路の妥当性は#167全体レビュー対象。
- DOT/HOTはsource_combatant_id。根拠のある旧soloはtotal=self、旧PT本人分は未知。未知を0にせずNULL/incompleteと原因を残す。最大一撃は1行動・1対象、敏捷連撃は合算しAoE別対象は合算しない。覚醒変身時の全快は回復へ入れず覚醒技の実回復は数える。
- 日誌は探索・試練・力試し・初回tutorialが対象。命名story・案内人決闘を除く。30日超の任意期間統計、月別bucket、全raw縮小コピー、無限UUID台帳はOwner採用範囲ではない。

[恒久化記録](../releases/4.4.0-receipt-durable-dependencies.md)とcurrent codeを参照。4.2.1の固定cutoff `2026-09-16T09:08:12Z` の本番整理は既にOwner報告で完了済みで、再applyしない。旧実測値は更新前handoffへ残っている。

## 5. Eの管理と本番で未実施のこと

既存Discord ID認可、サーバー発行の確認情報、既存配布/競売/島破棄/receipt serviceを使用。配布の全User・ID指定は確認時に解決し、実行時に対象を増やさない。島整理はUser/秘書/地下を保持し、対象競売の決算・地上島整理・公開ニュース・auditを一つの操作として扱う。[管理運用](../operations/4.4.0-admin-operations.md)を参照。

配布倉庫は受取Userを正本とし、島なしでもUser資産の受取が可能。地上資産は現在の島・容量を見て残額を倉庫へ保持。365日の期限導入は旧配布量/受取量を書き換えない。数値は現在の設定で永久不変の契約ではない。

World予定起点は遅れた実行完了時刻から推測しない。Ownerが本WorldのTurn1を `2026-08-06T00:00:00+09:00` と確認した記録がある。既存Worldはmigrationでnullのまま、schedule-initを独立承認下で一度だけ設定する。未設定時はWeb手動Turn不可。このhandoff更新では実行していない。

古いログのread-only previewと管理UIは実装済みだが、定期cron登録・通知稼働・本番purgeは別。wrapperがあるだけで自動運用済みとしない。

## 6. FのOwner確定とレビュー訂正

### ガチャ・Item

現在値は、わくわく Regular68.95/HQ29.55/Artifact1.5%、ドキドキ HQ70/Artifact30%。**Relic30%はAssistantの誤記であり、今回も将来もOwner採用の確率ではない。** 将来のドキドキRelicは数%程度の激レア構想で、今回景品として実装しない。三紋章はArtifact、ガチャ除外の特殊drop。合成は後日。

ニョワミヤリボンの出現率bonus、軍務卿/宰相の上位派生、シヴァの機械弓系0.4倍トドメ継承はOwnerに確認済み。旧Owner編集表の未決ラベルだけを見たP2三件は撤回した。服のフレーバーを後日変更することはblockerではない。

白旗は「装備島の防衛施設が怪獣をミサイルから保護しない」。攻撃側装備を条件にしない。お守りはLvが残り防御回数、1マス防御ごとTurnStateだけで消費、成功時終盤にbatch反映し0なら削除/slot解放。

個人記念碑は1User=1共有design。設置cellがdesignを参照し、差し替えは既設分にも反映。GIF32×32、既存volume/配信経路、旧画像の未参照確認後削除を使用する。

### #165で修正されたもの

白旗の攻守逆転、空parametersによるsnapshot停止、shot比例occupancy SELECT、怪獣dropの二重read/lock、記念碑selector全件写経assert、fresh旧Ruleset固定、旧版再現helperを修正した。新弓6種をRNG factoryが拒否するP1は `d182e7468a88a3b6f3a6f56302c04095affbcf30` で弓名ホワイトリストをstable key形式検証へ変更。旧 `old_bow` stream identity分岐は残す。

前会話の#165最終独立レビューでは追加P0/P1/P2なし。これを#167全体の無欠陥宣言にしない。新弓でニョワミヤを攻撃した公開ログのラベルが単に「攻撃」になる表示差は非blockerとして許容された。

## 7. G/Hの現在地と古い案の優先度

共鳴結晶は独立装備1枠・別倉庫、ILと固定能力/固有/ランダム効果を持つ。竜武器の専用1＋通常3枠と混同しない。割合成長は中級1/IL200以上で頭打ち・最大約30%を目安、固定数値はその先も成長する方針。当初の共通5能力の比較案だけで後続Hの4系統を上書きしない。現行の黒竜晶4系統は役割別の固定能力配分を持ち、現在のconfigと後続Hの採用記録を維持する。

研磨は固定能力の+0値に1段階30%加算、割合は1段階+0.1 percentage pointという採用条件。+2〜3は育てやすく、+5はGを投入する目標。詳細定義の正本は現在のconfig/codeであり、初期試算で上書きしない。

バハムル初級は黒爪/黒炎の息吹/竜翼/メガフレア、中級は深淵の咆哮を加える。竜翼の短期攻撃低下を落とさない。初級は通常予告、中級は咆哮から5→1、1で大予告、次round敵行動で発射。咆哮はpartyの覚醒ゲージを補充するが自動覚醒はしない。タンク発射前・ヒーラー着弾後の立て直しを既存AIで狙う。専用の「着弾済み」条件はOwner却下済み。

最新調整記録のHPは28,000/60,000/110,000/390,000、推奨Lv150/250/400/666。4人PTの基準は実測の戦技/護身/祝福配分3例＋想定の攻撃役1人（生命2:筋力7:技巧1）。均等5能力配分を現実的PTとして難度基準にしない。中級1はLv500では全滅、Lv666・初級3結晶+5・既存の対策AIで攻略する想定。勝敗/全滅/時間切れと延長によるジリ貧を分けた計測を行った担当記録がある。勝率は有限seed試算であり、恒久バランスassertや全seed保証にしない。

現在の報酬EXPは10,000/25,000/50,000/200,000。**旧「10分ごと」案は終了。** [H記録の最新追記](../plans/4.4.0-bahamul-polishing.md)と現行実装では歪んだ輝石を持つ間は再挑戦可能、勝利で1個消費。試練2後の案内人から日次4個まで0/10,000/50,000/100,000G、狩場3以降のレア敵dropを使う。日次直接勝利枠や600秒待機を復活させない。敗北/逃走側に高EXPを付けて無限取得できる経路は既に問題として修正されたもので、全体レビューでも報酬と権利消費の整合を見る。

初期Hで黒爪/竜翼を欠いたまま調整した停止チェックポイント・始末書は履歴。修正後の実装や最新計測を「未実装」へ戻さない。試練3は将来Lv650・初級3結晶を想定し、中級1勝利を必須にしない。

## 8. テスト・レビュー・先送り

Ownerは設定変更ごとに赤くなるcatalog全件/要素数/価格/確率/最大Lvの写経testを不要と明示。runtime validatorへのbalance literal焼付けも同じ整理対象。残すべき故障の契約と現在の調整値を分け、赤いassertを最新値へ合わせるだけにしない。既存代表で足りるなら追加しない。横断レビューを全経路の恒久test追加義務にしない。

中間修正ごとのFull CI反復は禁止。今回のhandoffは `[skip ci]`、既存CIを確認するだけで新規起動しない。レビューでは具体的な到達可能性と影響を示す。独立レビュー側も古い文書の誤記だけでP1/P2を作らない。

公開後の旧互換/事故test/validator大掃除、AのAgent/CI全面改修、C2、Relic合成/景品、試練3/raid等は別作業。新機能追加でreleaseを閉じられなくしない。

## 9. 既知の運用・別件

GitHub→Forgejoはmain・一階層release/*・tagの片方向同期。force/削除/逆同期はしない。`[skip ci]` は同期も止め得るため、SHA/実行結果と本番deployを分ける。OCI checkoutは `/home/ubuntu/apps/hakoniwa-world`、composeは `/home/ubuntu/apps`、remoteはgithub/forgejo。Ownerはdeploy scriptのfetch/pull先をgithub mainへ変更したと報告。originの存在やbranch upstreamを推測しない。credentialは貼付・記録しない。

女王決闘は通常探索と別決算、通常HP/MP/覚醒/G/EXP/cooldown/貸出参加/日課へ結果を戻さない。初勝利グラムは一度だけの装備/売却不可記念品。初勝利後ソロ再戦は実PTなしで会話差替え、誘導を作らない。成長方針とskillは別軸。借用更新HP全快/覚醒0、その後持越し、宿はHPのみ。表示は地底16:9、AI OFFは非AI placeholder、creditは素材単位。

MCP最後の既知liveは2.2.0/schema7。Forgejo候補2.3.0/schema8 `d9cf47afb0bf72b204187f1ac42afc860feacf70` のtimeout後子process残存MCP-F1、M8/M9/M10は別枠で今回未再調査。Safari N19（海底都市拡張後の地図消失、後にWi-Fiで表示）は原因未確定。今回のrelease blockerへ無条件追加しない。

古いZIPは過去時点の証拠で、別チャットへ自動的にbytesが共有されるとは限らない。再開担当は現在のrepoとこのhandoffを入口にし、必要な資料だけ取得する。
