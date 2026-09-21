# hakoniwa-world / hakoniwa-mcp 実装・運用handoff

更新：2026-09-21 JST。Owner明示依頼でWeb版ChatGPTが更新。今回の役割分離は文書の整理であり、gameplayや既存の権限を自動変更しない。

## 0. この文書の役割

再開入口は[current-status.md](current-status.md)。本書には実装済みの境界・完了証拠・既知の運用残件を置く。
[会話記録](conversation-2026-09-21.md)は判断の経緯、[未実装アイデア帳](../plans/unimplemented-ideas.md)はOwner要望・構想・未決事項、releaseの計画書は実装方法の提案を扱う。未実装案を現在のコードより優先される「実装済み仕様」へ昇格させない。

各項目をOwner指定、Owner提供ログ、GitHub固定SHA確認、実装担当報告、Assistant提案、未確認に分ける。handoffはOwner/Web版ChatGPTが管理し、通常のCodex実装ではread-only。Ownerが個別に依頼した更新は例外。

前版の長い履歴は[4.3.2 merge時の同ファイル](https://github.com/Mamiki765/hakoniwa-world/blob/3f49819fb764b5ac2e9fc49f95f6795ded7754d2/product/docs/handoffs/development-history-and-current-handoff.md)、さらに旧統合版は`66bfa5b4ab42ad3303f7667156c2b5ea4458550f`から必要時に参照する。全資料の毎回再読を要求しない。

## 1. 4.3.2の確定した開発結果

- [PR #156](https://github.com/Mamiki765/hakoniwa-world/pull/156)：レビューHEAD `374ee3c49c6abf49870969ad755e9e45e9e69d1c`、merge `3f49819fb764b5ac2e9fc49f95f6795ded7754d2`。
- [Quality 35571774926](https://github.com/Mamiki765/hakoniwa-world/actions/runs/35571774926)：exact-head成功を本会話で確認済み。最終独立確認はソース・DB制約・呼出経路・既存CIの照合。新規の実アプリE2Eや独立Laravel/PostgreSQL全件実行としない。
- OwnerはP0/P1なしならmergeを許可、P2は内容別に後続hotfixとしてよいと指定。確認範囲で新しいP0/P1・確定した追加P2なしとしてmergeした。
- プレイヤー向け変更は、地図tooltipの折返し/scroll/入力分離、地下戦闘履歴の20件cursor読込。宿代と銀行単位の表示も設定値参照へ。現行balance値を変更したreleaseではない。
- `2026_09_18_000000_allow_configured_trial_reward_lengths.php`一本で出所index/STP CHECKを整理。既存profileのEXP/G/STP再計算や削減はしない。本番適用完了出力は本会話にない。

### R1〜R10（完了済み。再実装しない）

| ID | 修正結果 |
|---|---|
| R1 | tooltip起点のpointer/keyboardを親地図操作から分離 |
| R2 | 保存済み装備の品質を現在の抽選範囲で拒否しない |
| R3 | 許可された同種中央施設の個数・レベルを自然発生HP集計へ反映 |
| R4 | 災害損失0はdamage serviceの前でno-op |
| R5 | DB/PHPのSTP総量5/6固定式を整理。非負・未選択時の構造制約を維持 |
| R6 | 単発trial skipの装備reward index上限10を撤去 |
| R7 | 中央施設被害もRuleset maximum_scaleで検証 |
| R8 | 航行報酬を食料/保存可能な非食料で既存加算handlerへ振分け |
| R9 | 今回の船移動は対応済みの深海に限定しDBと整合 |
| R10 | 初期原点0を維持し、最大座標は可変 |

最後のCI赤2件はテストfixtureの修正。skipは現行XP曲線のLv2直前を使用。ニョワミヤは移動後に初期装備の古びた弓で倒されていたため対象testだけ未装備化。移動runtimeや報酬をテストに合わせて変えたわけではない。

## 2. GitHub / Forgejo / OCIを混同しない

開発正本はGitHub。World同期#157はmerge `a374204f41f22c4bee69c85c422393c6dd56913c`、MCP GitHub同期#1はmerge `bc3744f50e3359186ad7b5ae74a77c0a6fd264e9`。Owner共有の成功報告と、4.3.2 merge後の[World mirror 35573726878](https://github.com/Mamiki765/hakoniwa-world/actions/runs/35573726878)成功を区別して保持する。

同期はGitHub→Forgejo main/tagsのみ、通常push。分岐時にforce push/自動merge/逆同期しない。未mergeのrelease branchはこの同期対象ではない。tag経路の実SHA照合は未確認。

OwnerのOCI出力では、checkoutは`/home/ubuntu/apps/hakoniwa-world`、composeは`/home/ubuntu/apps`、remoteは`github`と`forgejo`（`origin`なし）、main追跡先は`forgejo/main`。その後、Ownerはdeploy scriptのfetch/pull先を明示的に`github main`へ変更した。これはbranch upstreamを書換えた証拠ではない。

最初の`git fetch origin`失敗はbuild/stop/migration前。その後のdeploy手順は提供したが、成功ログは未提示。GitHub SHA一致を稼働image・DB・mount一致の証明にしない。remote URLに含み得るcredentialやSecretの値を記録・貼付させない。

## 3. Turn560：復旧済み、耐性改善は未実装

Ownerのログで2026-09-21 14:00 JSTのTurn560が`finalize_turn`、`secretaries ... ORDER BY id ASC FOR NO KEY UPDATE`、SQLSTATE `40P01`で失敗。通常manual retryでも再発し、maintenance下で同run560がcompleted、current_turn=560、attempts=4。`artisan up`の成功も提示済み。これを未復旧へ戻さず、手動で余分なTurnを進めない。

失敗時のWorldはv26 / ruleset_version_id=41。相手側SQLは未取得で、特定地下APIや実lock cycleの確定には不足。Turn前半の戦利品付与等が親SecretaryへFOR UPDATEを保持し、最後のflushと地下PTのFK確認が循環するH1は**設計上の候補**。

現行コードはTurn本体`DB::transaction(..., 1)`、failed/blockedを次回cronでも拒否。4.4.0では失敗種別を分けたretryとロック隔離を検討するが、今回コード・D-02の契約は未変更。`KEY SHARE`と`NO KEY UPDATE`は共存し、identity FKが存在するだけで別DB/User-root化が必要という過去の説明は訂正済み。

所有は`User → Secretary → UndergroundProfile`を保持。島再作成で秘書・地下進行を消さない。`nation_underground_facilities`はNation側の施設であり、地下RPGと名前だけで一括移動しない。

## 4. データ整理・統計で既に決まっていること

4.2.1固定cutoff `2026-09-16T09:08:12Z`の本番整理はOwner報告で完了済み。OOM hotfix後`531fdd916bb10a676a8a107ffb1a9f1c769ec291`で地下45,903件のbackfill、論理2,630,175,847 bytes削減、routine source360,464行削除、同cutoff残件0。未帰属森林も集計へ保存。再apply、過去の補填、VACUUM FULLやcron追加をこの記録から行わない。

保持する統計の意味：DOT/HOTはsource_combatant_id。根拠のある旧soloはtotal=self、旧PT本人分は未知。未知を0にせずNULL/incompleteと原因件数を残し、未知統計eventだけで戦闘決算をrollbackしない。

最大一撃は1行動・1対象で敏捷連撃は合算、AoE別対象は合算しない。覚醒変身時の全快は回復から除外、覚醒技の実回復は集計。完全ガードとdamage_preventedを混同せず、制御命令/実技の使用回数を二重計上しない。貸出先の詳細日記は不要でレンタル回数を残す。

日誌は探索・試練・力試し・初回tutorialを対象にし、命名story・案内人決闘を除く。今はbattle/skipのSUM/COUNTが残る。試練/女王クリア判定と既存トロフィーは既に恒久progress・記念品を利用する一方、装備の出所FK、貸出累計、日誌、回想、再送防止は30日整理前の変更対象。単純DELETEは未実装。

## 5. 既存のゲーム・UI境界（次の実装で誤変更しない）

- 女王決闘は通常探索と別決算。通常HP/MP/覚醒/G/EXP/cooldown/貸出参加/日課へ結果を戻さない。初勝利グラムは一度だけ、装備/売却不可の記念品。初勝利後ソロ再戦は実PTなしで会話差替え、誘導選択肢は作らない。
- 成長方針と取得skillは別軸。借用更新はHP全快/覚醒0、その後持越し。再計算はHP比率、宿はHPのみ回復。今回の設計だけで変更しない。
- 地底は16:9背景＋立ち絵、上部tabで機能切替。別荘100,000Gで日誌/既読回想、鏡1,000,000Gは別荘後。未読会話/決闘まで別荘条件にしない。
- AI OFFは非AI placeholder。作者creditは素材単位。既存env/mountと配信URLを確認せずassetを移動・再生成しない。ペリドットはエルフ耳以外の設定を全員共通へ強制しない。
- NPC船はnation_id=null。海賊の難民受入・宝船撃沈による取得は許容。探索船の20%遠隔キラッは表示用でAIへ秘密座標を渡さない。
- 4.3.0はOwner報告でmerge済み、4.3.1の狭幅重なりは修正確認済み。古い中間reviewを現行未解決としない。

## 6. 別件の残件と資料

MCP本体は最後の既知liveが2.2.0/schema7。Forgejo MCP #4の候補2.3.0/schema8、HEAD `d9cf47afb0bf72b204187f1ac42afc860feacf70`にはtimeout後の子process残存MCP-F1が最後の未確認残件。同期PR mergeは本体deployの証拠ではない。M8 scratch、M9容量診断、M10大きいread整理も別枠。今回live再調査していない。

Safari N19（User #23、海底都市拡張後「自島へ」で地図消失、後にWi-Fiで表示）は原因未確定。4.3.2tooltip修正と同一原因と断定しない。

添付`hakoniwa-handoff-2026-09-19(1).zip`は過去の監査/レビュー証拠。主MD、sources、evidence、SHA256SUMSを持つ。4.3.2未merge等の時点情報だけは本会話の後続結果を優先。ZIPのprobeを今回再実行したとはしない。ファイル名だけで別会話へbytesが自動共有されると仮定しない。

AGENTSのゼロベースrefineは4.3.2では未採用。旧提案ファイルとreview skill改訂は別成果。PC内のglobal AGENTS/override/実際のskill設定は未確認。4.4.0では短い入口・必要時参照・実行終了条件・CI分類を候補とし、モデル名を一括置換するだけの改訂にしない。
