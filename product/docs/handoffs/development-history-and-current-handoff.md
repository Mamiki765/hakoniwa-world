# hakoniwa-world / hakoniwa-mcp 開発経緯・現行引継ぎ

更新：2026-09-21 JST。Ownerの明示依頼によりWeb版ChatGPTが更新。

主資料はOwner添付 `hakoniwa-handoff-delta-4.3.2-review-2026-09-19(1).md`。これは初回レビュー受領時点の記録なので、その後の本会話の修正確認・追加レビュー・Owner指示で現在地を更新した。資料由来の過去報告と、今回のGitHub観測を区別する。

再開時の入口は [current-status.md](current-status.md)。この2文書を同じ更新単位で整合させる。旧統合handoffの全履歴を新たに再監査した文書ではない。従来の本文・4.2.0現在地は更新前commit `66bfa5b4ab42ad3303f7667156c2b5ea4458550f` の同じパスからGitで参照できる。歴史の全文を毎回の作業contextへ再投入しない。

## 1. 現在地と証拠

### 1.1 今回GitHubで確認した状態

| 対象 | 状態 |
|---|---|
| World main / #156 base | `a45c313979e34ef40c4b93819423eecc2fdf9760`、4.3.1 |
| World #156 | `release/4.3.2 → main`、open、未merge |
| 最後に独立レビューした実装HEAD | `66bfa5b4ab42ad3303f7667156c2b5ea4458550f`、45 changed files。このhandoff更新commitは含まない |
| 初回レビューHEAD | `ad21ffe24cd4bebb5404153133811ab9bf8847ce`、41 changed files |
| World同期 #157 | open、未merge、HEAD `6b4cbcee2aefe2964f6bca1477c11c32ce526232` |
| MCP GitHub同期 #1 | open、未merge、HEAD `90734d7d1a786f9de5574d9a89fa4f6f1853fefc` |
| MCP GitHub main基準 | 同期PRのbase `802dfb7ba0fbd9e6d68e28811a2f48b690b32e1f` |

GitHub復帰はOwner報告済みで、この会話では実際にGitHub sourceを取得できている。ForgejoのrefにGitHub PRがないことを、PR不存在やGitHub再凍結の証拠にしない。添付MDの別会話に出た`FORBIDDEN: This conversation is restricted to developer MCPs`は、その会話の接続制限の履歴。

production checkout・稼働image・DB・Turn・PC stashは今回再観測していない。Owner共有のcheckpointではPhase 4はstashへ退避、未追跡ファイル混入なし、merge・deploy・本番操作なし。これを独立した実機観測へ付け替えない。

### 1.2 根拠の区分と権限

Owner決定、実装側報告、固定SHAのsource読取、実行検証、Assistant提案、未確認を分ける。CI緑・mergeable・Secret登録・同期準備はmerge許可ではない。`OPEN`とOwnerが明示延期した`OWNER-DEFERRED`も別。

handoffはOwner／Web版ChatGPTの管理文書。Codexは通常read-onlyで、通常実装・レビュー修正のついでに編集しない。今回許可されたrepository変更はhandoff更新・commit。R1〜R10の追加実装やAGENTS更新はこの文書commitでは行わない。

通常のbranch作成・commit・push・PR更新・review投稿の権限は、現行AGENTSと最新Owner指示を参照する。添付MDにある別会話固有の投稿制限を、全会話の新しい恒久禁止へ一般化しない。main直接変更、merge、deploy、本番DB操作、配布は別の明示許可が必要。

## 2. 最新Owner方針：小規模運営に見合う保守

Ownerは約20人の個人運営ゲームで、30日以内の復元に備えたバックアップを用意していると説明。今回その実体や復元動作を再監査したわけではない。

- 無意味な現在値固定や、念のための過剰ガード・重複テストで開発を固めない。通常プレイ、資産・所有・進行、二重決算、全体Turn停止等の具体的影響を優先する。
- 横断レビューは歓迎されている。ただし発見した不整合をすべて現在のrelease blocker、全経路の恒久テスト追加義務、Full CI反復へ変換しない。
- Rulesetは定期的に歴史再現をcurrent treeから退役させている。必要ならGitから当時のcodeを取り出す。migration・upgradeテストにも同じ考え方を適用できる。
- Ownerの説明では、過剰なFull反復とGitHubの約2週間の凍結が開発停滞の背景。GitHub側の正式な検知理由をこの文書が独立確認したとはしない。
- 実害のあるデータ破壊と、一時的・局所的な不具合と、未実施の設定変更でだけ起きる問題は区別する。バックアップがあることを、無断の本番破壊・復元の許可にはしない。

テストは不足する意味を代表確認する。既存caseの拡張・置換を優先し、件数やassertion数自体を目標にしない。必要なfocused確認後は、新たな変更・失敗・具体的な不足がなければ終了。local Fullを同じCI Fullへ重複させず、小修正ごとの全件再実行も要求しない。

## 3. PR #156：R1〜R10レビュー台帳

### 3.1 経緯

1. `ad21ffe…`を独立レビューしR1〜R5（P2）を投稿。
2. `66bfa5b…`の修正を再確認。R1〜R5は確認返信のうえresolved。
3. 同HEADの追加横断確認でR6〜R10（P2、設定変更時の不整合）を投稿。
4. Ownerの運用方針を受け、R6〜R10をすべて直すまで現行設定のreleaseを一律に止める判定は見直した。技術的注意は残し、未修正をresolvedにはしていない。
5. 最新Owner依頼は、**すでに修正したR1〜R5も含め、migrationを1世代許可する前提でR1〜R10の実装プロンプトを作ること**。許可前の『DB固定式を守り直すしかない』へ戻さない。

| ID | `66bfa5b`時点の状態 | 故障条件・確認内容 |
|---|---|---|
| R1 | 修正確認済み・resolved | tooltip起点のpointerとkeydownを親地図操作から除外。枠内scrollと通常panを両立 |
| R2 | 修正確認済み・resolved | 保存済み装備の`definitionForRow()`と`combatLoadout()`で現在の生成品質範囲を強制しない。新規抽選は現在設定を使う |
| R3 | 修正確認済み・resolved | 同種中央施設の個数を`maximum_per_nation`で確認し、許可された全施設のレベルを怪獣HP補正へ合算 |
| R4 | 修正確認済み・resolved | 災害損失0は`CentralFacilityDamageService::apply()`の前でno-op。正数必須のapply契約を維持 |
| R5 | 当時の方針で修正済み・resolved | DB総量制約へ合わせた`PERSISTED_STP_PER_LEVEL`の5/5/5/6ガードを復元。今回のmigration許可を受け再検討対象 |
| R6 | 設定変更前の注意・未修正 | drop付き11戦以上の試練を回数指定なしの単発skip APIで実行すると`settleSkippedVictory()`の`rewardIndex > 10`で決算rollback |
| R7 | 設定変更前の注意・未修正 | 中央施設maximum_scaleを100等へ変えLv91以上にすると、被害適用先の`$before > 90`が拒否 |
| R8 | 設定変更前の注意・未修正 | 船の航行報酬に非食料資源を設定できるが、`settleReward()`が食料専用`creditFood()`へ渡す |
| R9 | 設定変更前の注意・未修正 | 船の移動地形`shallow`を許可するが、DB identity triggerはdeep sea限定。建造候補にもsea条件がある |
| R10 | 設定変更前の注意・未修正 | 初期最小座標1等をvalidatorが許すが、Production生成の`InitialWorldBounds`は原点0必須 |

R1だけは初回候補の現行設定で到達する操作不具合だった。R2〜R10を『現在本番で全部発生している事故』と扱わない。現在のUIは1回skipでも`execution_count`を送るため、R6の単発経路とは異なる。ただし単発API自体は存在する。

### 3.2 検証と限界

`66bfa5b…`のQuality run `35398745823`（#562）はfrontend、backend-static、PHPUnit全16 shard、集約backend等の22 jobs成功をこの会話で確認済み。初回HEADのrunは`35352383190`。文書commit後のHEADにこの結果を付け替えない。

R1は関連ハンドラ・CSSを切り出したChromium検証で修正前後を確認。実アプリ全体のVue E2E、認証済み本番、iPhone Safari実機を検証した意味ではない。R2〜R10は固定SHAのsource・schema/trigger・呼出経路を追った確認が中心で、独立Laravel/PostgreSQL実行とはしていない。

Owner共有の修正checkpoint：focused backend 4/4・32 assertions、HexMap Vitest 17/17、Pint・ESLint・typecheck・PHPStan 449 files PASS。これは実装側報告。

追加で履歴cursorのowner scope・`finished_at DESC, id DESC`に対応する比較、再振り、探索request再利用、bulk skip決算、船建造、領土感化の対象を読んだ。確認範囲で現行通常プレイの新たなP1/P2は見つけていない。全repository無欠陥の保証ではない。

### 3.3 1世代のmigration許可をどう扱うか

Ownerの許可はR1〜R10の整理を一つの移行単位へまとめるためのもの。本番migration適用、全履歴rebaseline、バランス数値変更、浅瀬航行等の新機能をまとめて承認した意味ではない。

SQL migrationファイル数、migration ledgerのbatch、Surface Ruleset世代、地下combat/equipment identityは別。必要なDB変更は一度のrelease移行に集約する。既存PR内のmigrationが未適用と確認できるなら統合候補、適用済みなら書き換えず追加差分にする。レビュー指摘ごとに別世代・別移行基盤を作らない。

Surface Rulesetはsemantic changeが不要ならv26維持。必要な場合だけOwner許可の範囲と現行AGENTSに従いN→N+1の一世代へ集約し、安定化のたびN+2以降へ増やさない。DB CHECK整理だけで全identityを自動更新しない。

**本回答で検討する実装方向（Assistant提案、実装済みではない）：** R1〜R4の良い修正は維持。R5はSTPのゲームバランス式をDBから外し、負数・未選択状態等の構造制約を残す。移行時に既存profileの配分・未使用STPを再計算しない。成長選択・LvUP・再振りの既存意味を確認し、将来の全付与履歴を扱う台帳を新設しない。R6/R7は下流の固定値を整理。R8は既存の食料／保存可能な非食料の加算処理を再利用可能。R9/R10は未依頼の仕様拡張を必須化せず、実装済みの航行範囲／初期生成契約と受付を一致させる。

R5の既存再振りは`stpEntitlement()`で選択系統・現在Lvから総量を再計算する。設定変更時にはここも影響するが、今回の制約整理だけで既存残高やバランスを変える必要はない。Ownerが具体的にSTP配布・再配分を決定したことにはしない。

## 4. migration・schema baseline・Ruleset退役

前会話が同じ`66bfa5b…`で数えた内訳：schema dump内ledger 55、現行migration PHP 34、その重複3、identity和集合86。ファイル名の差引きでdump後の対象は31本。『歴代migrationが34本だけ』ではない。production ledger実数、歴代の未deploy試作も含む総数、実行時間の実測とは別。

新規／test DBはschema dump＋後続差分、既存DBは未適用差分、という経路を分けられる。現行repoは既にbaseline方式を使用しており、tailが伸びたら次の区切りで更新できる。ファイル本数だけから現在の遅さ・改善倍率を断定しない。

schemaの完成形にはtable・index・FK・CHECK・function/trigger等と適用済みledgerを揃える。Rulesetやcatalogの初期データはschema-only dumpと同じものではないので、fresh installer/publisherの役割を見落とさない。既存gameplayデータを丸ごとtestへ複製する話ではない。

Ownerは古いRuleset再現の退役を既に運用している。移行PHPや旧upgradeテストのretireも、サポートする起点を決め、必要時は当時のGit版で中間版へ上げる方式を取れる。古いバックアップを復元する場合は対応するcode/tagも使う。30日バックアップがあることだけで、最新codeからどの旧DBにも直接upgradeできると仮定しない。

今回、baseline生成・古いmigration削除・production ledger変更は行っていない。

## 5. GitHub復帰と同期は別案件

Owner共有の復旧報告ではWorldのlocal/GitHub/Forgejo/OCIが`a45c313…`、MCPのmain・配置元が`802dfb7…`で一致。これは稼働image・DBや二つのmountが正しく別実体を指すことまで証明しない。

同期はGitHub→Forgejoのmain/tagsだけ、一方向の通常push。履歴分岐・同名tag衝突は失敗し、force push・自動merge・逆同期・Forgejo固有PR branch操作はしない。`FORGEJO_PUSH_URL`の秘密値をログ・source・会話へ出さない。今回Secret登録や実同期成功は未確認。

World #157とMCP GitHub #1は今回も未merge。MCP本体改修のForgejo #4と混ぜない。main/tags同期は未mergeの#156 HEADをMCPへ運ぶ仕組みではなく、取得の都合で#156を先にmergeしない。

## 6. 完了済み作業を巻き戻さない

以下は添付MDが引き継いだ作業報告・過去レビューであり、今回本番を再実行した記録ではない。

### 6.1 4.2.1本番整理

Forgejo #14 merge `711362479e139e5a991606e149e7159808617fe2`で4.2.1。最初の全量previewがPHP128MB OOMになり、#15 hotfix後production `531fdd916bb10a676a8a107ffb1a9f1c769ec291`でapplyまで完了した。

固定cutoff `2026-09-16T09:08:12Z`。地下45,903件のstatistics backfill、論理削減2,630,175,847 bytes。Routineはsummary10,216 groups、未帰属forest501 groups、source360,464 rows削除。森林95,735 rows／quantity9,744,780は未帰属aggregateへ保持。同cutoffで地下・routine残件0と報告済み。この分を未applyとして再開しない。cron変更・VACUUM FULLはこの作業で行っていない。

当時のbackupは数十MBだったが、最新backupサイズだけでbucket全体容量を証明しない。論理JSON削減量と物理DBサイズも別。

### 6.2 4.3.0 / 4.3.1

4.3.0はOwnerが別Astraの最終独立レビュー後にmergeしたと報告。中間レビューのAI usage二重計上・素材keyのドット拒否・`blessing_white`誤記を、証拠なしに再び現行未解決へ戻さない。

4.3.1 Forgejo #18は海賊襲撃TOP warningと覚醒切替位置。`9f2424b…`での320px重なりは`6b4f36c0f15f9f63b917b6c8e4be2d28fcbbddae`のcontainer queryで解消確認済み。狭幅ではSTPではなく人物側へ重ねるOwner意図。現main基準は4.3.1 `a45c313…`。

### 6.3 保持する統計の意味

DOT/HOT帰属は`source_combatant_id`。未知eventで戦闘決算全体をrollbackせず、該当統計をNULL/incomplete＋原因件数にする。不明を0へ変換しない。根拠のある旧soloはtotal=self、旧PT本人分は推測しない。

最大一撃は1 action・1対象。敏捷連撃は合計、AoEの別対象は合計しない。覚醒の変身時全快は回復統計から除外し、生命讃歌等の技の実回復は集計。完全ガードは既存damage_preventedと混ぜず別集計。制御命令と実技のusage二重計上は不可。貸出先詳細日記は求めず、レンタル回数でよいというOwner回答。

日誌の対象は4.3.0時点で探索・試練・力試し・初回tutorialを含み、命名story・案内人決闘を除く。battle/skipのSQL集計が恒久counter化済みと仮定しない。

## 7. 継承するゲーム・UI契約

### 7.1 地底UI・素材

16:9背景＋立ち絵のwebソーシャルゲーム風UI。大きな日本語に赤い英字を添える装飾はOwnerの要求ではない。ホーム／冒険／キャラクター／ショップ／交流場／別荘、内部切替は上部タブ。ショップ上部の宿ボタンは例外で、長い一覧の下へ別機能を積まない。

別荘100,000Gで日誌・既読回想へ。日誌を別商品にしない。透明な鏡1,000,000Gは別荘後。未読案内人過去話はショップ、既読回想は別荘。未読進行や決闘まで別荘条件へ巻き込まない。

自キャラ立ち絵と小さな同行者iconにⓘ不要。委託NPC・背景・スチルの必要creditは素材単位。AI OFFは無地化ではなくGit同梱の非AI placeholder、light/dark可読性を維持。非AI委託作品まで非表示にしない。作者情報やアップロード6枠まで全体設定へ移す意味ではない。

Ownerの配置例`/srv/bot-assets/hakoniwa/peridot/`をconfig既定`/srv/hakoniwa-assets/tiles`と違うだけで誤りとしない。env、host/container mount、配信URLを照合。第二asset rootの作成、無断移動、画像再生成はしない。背景・NPC・stillは既存manifest/resolverを使う。

### 7.2 案内人・PT・成長

案内人決闘は通常探索と別決算。通常HP/MP/覚醒/通貨/EXP/cooldown/貸出参加/日課へ結果を書き戻さない。初勝利の魔剣グラムは一度だけ、装備・売却不可の記念品。女王HP351,400、毎round回復1,254、25%到達を記憶するaction境界、100round敗北は当時のOwner契約。一般round数ガード整理で勝手に変えない。

初勝利後のソロ再戦は実際の確定PTがないと会話を自動差替え。別の意思flagや『一人で挑む』選択肢を作らず、隠し会話をmanualで誘導しない。

成長方針と取得スキル系統は別軸。味方回復はgrowth path限定ではなくskill/effectの能力。active5枠等のgameplay枠と無意味なcatalog総数固定を混同しない。レンタル更新は同行者HP全快・覚醒0、その後持越し、再計算はHP割合維持、宿はHPのみ回復という既存方針を無断変更しない。

### 7.3 海洋・運用

NPC船はnation_id=null、ダミーNationを作らない。海賊の難民受入で平和賞を狙うこと、宝船を沈めて宝を得ることは許容仕様。探索船はNationの通常視界を共有して宝を追い、20%の遠隔キラッは表示専用でAIへ遠方座標を与えない。

海洋の細かな4.2.0契約は当該release文書・manualと更新前current-statusを必要時に参照する。4.1.2のSecretary lock修正とTurn478 manual retry完了を古いdeadlock記録から未解決に戻さない。未解決Turnのcron自動retryはしない。manual retryは同Turn・同設定・同乱数。

## 8. MCP改修と最後の残件

ここは添付MD由来の最後の既知状態。今回Forgejo PRや稼働endpointは再確認していない。

Forgejo `hakoniwa-mcp` #4、branch `codex/review-workbench-pr16-followup`、既知HEAD `d9cf47afb0bf72b204187f1ac42afc860feacf70`。候補2.3.0/schema8、最後のlive観測2.2.0/schema7。GitHub同期#1は本体改修の採用証拠ではない。

M1 archive一意名、M2生成/転送状態、M3読取完全性、M4redaction誤検知、M5隔離DB focused、M6Vitest entrypoint、M7binary skipは改修PRで対応と記録。M8scratch、M9容量診断、M10大きいread量整理は未実装。M11は一意名で主要実害に対応、M12はrunner状態分類で多くを吸収し、別の汎用job/artifact基盤を必須化しない。

初回R1〜R5は修正確認済み。最後のP2 MCP-F1は`focused_runner_worker.py:330–365`のtimeout後の子プロセス残存。親だけSIGTERMで終了し、同process groupの子がSIGTERMを無視してstdoutを保持すると、親wait成功のためSIGKILLを通らない。推奨は既存cleanup予算内でprocess group最終終了を保証する局所修正。完了・merge・deployは未確認。

当時の非DB独立scriptは14+17+9+11=51 PASS。ただしその後の追加probeでMCP-F1を確認したので、PASSだけで残件なしにしない。

旧MCPを使う場合は受領archiveの実体・bytes/hashを確認しunique名へ保存。sanitized bytesと元blob hashを混同しない。binary問題はtext対象へ絞り、巨大取得の無制限retryをしない。startup/redaction失敗をアプリassertion failureに数えない。取得不足時は確定結果と未読範囲を残す。

## 9. 忘れない後続課題・未確定事項

### 9.1 30日領収書シュレッダー

Owner方針は詳細JSONを圧縮するだけでなく、battle receipt自体も概ね30日で整理できるようにすること。必要な生涯統計・報酬・進行・貸出・再送防止の意味を先に恒久保存先へ移す。全request IDを別の無限台帳に移すだけでは目的を満たさない。

未確定報酬・処理中・参照中・未集約を削除せず、再実行で二重加算/欠落を起こさない。日誌のSQL SUM/COUNT読取先も対象。詳細ログの1時間retention、snapshot圧縮、receipt30日整理、恒久counterは別。今回の4.3.2 blockerや単純DELETE/cron追加へ変換しない。

巨大な参考統計は指数表記・微小誤差を許容するOwner意向。IDや実残高の決算精度まで同じに扱わない。全ID BIGINT・全統計NUMERIC(30,0)への一括migration承認はない。

### 9.2 Safari報告を混同しない

小窓は『30行あるなら30行読める』が要件。128px clipだけ直す話ではなくscrollと親入力分離が必要で、R1で対応した。Safari実機固有原因を確定したわけではない。

別件N19、User #23 `midzukaze`、Turn500・4.2.0・2026/09/16 14:19の問い合わせは、海底都市で広げた領地で『自島へ』直後に地図が消えるという報告。後にWi-Fiでは表示できたとOwner補足。通信・描画等の原因は未確定で、小窓の非表示と同じ原因にしない。島データ削除やIPv6/HTTP3等への断定はしない。

### 9.3 討伐・別荘・管理ページ

Lv500推奨PTボス、Lv200〜300で触れる入口、初回救援無料、世界レイドの参加/貢献報酬は構想段階。具体敵名・IL・EXP・貢献式・無料の粒度は未確定。Owner報告では案内人をLv650程度でAIを詰めて倒せる人がいる。案内人撃破→試練3解禁が設計意図で、試練3実装済みや10,000戦周回必須とはしない。

重装・軽装・ローブの分岐は将来相談。鏡以外の別荘アイテム案は未採用の提案として扱う。ペリドットはエルフ耳以外設定自由というOwner意向で、Assistantの経歴・性格案を全員共通設定へ昇格しない。

管理ページは余力があればの後順位。Ownerの『お問い合わせはトップで見る』を、管理トップへ移設済み/承認済みと断定しない。着手・未着手とも未確認。今回のguard整理へ混ぜない。

## 10. AGENTS再構築の依頼と今後の入口

Ownerはモデル世代交代に合わせたAGENTSのゼロベース案を求め、旧Sol作成文の解釈に拘束されなくてよいが、出した案はOwnerが精査すると明示。これはAGENTSの書換え完了や具体案採用の証拠ではない。

今回読んだroot AGENTSには、既に過剰テスト・Full反復・過去互換の復活を避ける方針がある。注意書きの追記だけでなく、常時読込を減らし、可逆な実装の裁量・本番操作の承認境界・終了条件・task別参照先を明確にする案を検討する。

`product/composer.json`の`composer test`は`test:all`（Full）へ進む。`.github/workflows/quality.yml`はpull_request起動で、読取範囲には文書変更の除外条件がない。小さい確認のつもりでFullを呼ばない。AGENTSの改善とCIの起動条件改善は別の作業で、今回どちらの設定も変更していない。

再開時は最新Owner依頼→短いcurrent-status→対象PRのHEAD/必要差分→対象資料の順。実害と設定変更前の注意を分け、結果・証拠・未実施を報告して終える。review範囲を無限に広げない。

## 11. 追跡先

- PR #156: <https://github.com/Mamiki765/hakoniwa-world/pull/156>
- 初回R1〜R5: <https://github.com/Mamiki765/hakoniwa-world/pull/156#pullrequestreview-5252789381>
- 再確認・R6〜R10: <https://github.com/Mamiki765/hakoniwa-world/pull/156#pullrequestreview-5253101607>
- Owner方針反映・判定見直し: <https://github.com/Mamiki765/hakoniwa-world/pull/156#pullrequestreview-5255627772>
- 実装HEADのCI: <https://github.com/Mamiki765/hakoniwa-world/actions/runs/35398745823>
- World同期: <https://github.com/Mamiki765/hakoniwa-world/pull/157>
- MCP同期: <https://github.com/Mamiki765/hakoniwa-mcp/pull/1>
- MCP本体改修（別ホスト）: <https://git.pbwlove.com/Mamiki765/hakoniwa-mcp/pulls/4>
- 従来本文: <https://github.com/Mamiki765/hakoniwa-world/blob/66bfa5b4ab42ad3303f7667156c2b5ea4458550f/product/docs/handoffs/development-history-and-current-handoff.md>
- 従来4.2.0現在地: <https://github.com/Mamiki765/hakoniwa-world/blob/66bfa5b4ab42ad3303f7667156c2b5ea4458550f/product/docs/handoffs/current-status.md>

添付元の補助ZIPやprobeファイルは、ファイル名だけで次会話へ自動共有されると仮定しない。この文書は結論と境界を保持し、実行をやり直す場合だけ必要な証拠実体を取得する。
