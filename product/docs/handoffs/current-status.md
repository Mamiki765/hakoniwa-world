# hakoniwa-world 現在地・4.2.0引継ぎ

更新日：2026-09-16 JST。Ownerの差分レビュー・横断レビュー・handoff更新コミット・マニュアル更新の依頼に基づく。

**4.2.0は海洋アップデートとテスト基盤の改善で区切る。PR #9は `release/4.2.0 → main` の最終release PRであり、まだマージしない。** 統合HEADの横断レビューでP1 1件・P2 1件が残っている。詳細は[4.2.0最終レビュー](../releases/4.2.0-final-review.md)。

この文書を旧統合handoffより先に読む。以下のHEADはレビューしたcodeの固定点であり、この文書のcommit後も常に最新という意味ではない。再開時はForgejo PR #9のHEADを再解決する。

## 1. 固定refと作業境界

| 項目 | 確認済みの状態 |
|---|---|
| 正本remote | Forgejo `Mamiki765/hakoniwa-world` |
| #9 base | `main` / `00182bd0eaee52f34194c7b40bc5e98712108718` |
| レビューした統合HEAD | `478ea7628577ca48f431c947e4a7c50ab85a2c39` |
| #9 | head branchは`release/4.2.0`。open・draft・未merge |
| #11 海洋ループ | reviewed HEAD `4c7a73b07dab0c5d6e8e9a4a4d37bcaad9de52ec`。merge `e85893a206ac4d1fd92e435d4887a907f832af72`でreleaseへ反映済み |
| #12 focused高速化 | reviewed HEAD `2825c3fb5cfba5c9223c9458593c5403a981c3d5`。merge `478ea7628577ca48f431c947e4a7c50ab85a2c39`でreleaseへ反映済み |
| application / Surface Ruleset | codeは4.2.0 / `hakoniwa-2s-plus-v26` |
| production | 今回は再照会・変更なし。releaseへのmergeやcode versionを本番適用済みの証拠にしない |

#9の`mergeable:false`表示だけからtext conflictの原因を断定しない。#11・#12の個別mergeと、#9のmainへのrelease判断を混同しない。

## 2. 4.2.0のscope

含めるものは、海賊船・宝船、戦艦、海軍技能、埋蔵宝、探索船回収、キラッ表示、あおいのら近海化、わくわく／ドキドキチケット、および必要なv26・migration。test suite再設計とfocused反復高速化も同梱する。

**含めないもの**：ログ圧縮、地下battle/member snapshot削減、秘書個室・100,000Gの日記帳・自キャラ統計、地上audit集約、画像lease prune改善、オリジナル記念碑、地下UIの5画面再編、チケット消費効果、AF本体、汎用海戦/PvP/actor framework。

ログ圧縮等は会話で「将来#13」と呼んだ次版の案件。番号を実装完了・PR作成済みの証拠にせず、4.2.0の完了条件へ戻さない。過去DBの削除・変換・cron変更は別の明示承認が必要で、文書更新から許可を推定しない。

## 3. 海洋のOwner契約と実装到達点

- NPC船は`ships.nation_id = null`。ダミーNationやID=0を作らない。Player船の所有・建造制約は維持する。
- NPC船出現は港保有のactive Nationを先に選ぶ。港の数で抽選回数を増やさず、選んだ港から4～6hexに出現する。10%はWorldの面積換算された各抽選機会の確率であり、各島が毎Turn10%ではない。成功時の種類比率は海賊85／宝船15。
- 海賊船は出現時HP1～3・最大HP3、人口5,000～10,000。宝船はHP1。両方ランダム漂流し、NPC自身の港・石油・航行報酬・船舶運営EXPを要求しない。
- 海賊は各Turn50%で半径2内から有効な対象をランダムに1件選ぶ。集落の人口を半減させ実減少を船人口へ加算、首都は100人を下回らせない。Player船には1damage、自分の真下の海底施設には破壊。難民を増やして平和賞を目指す遊びはOwner意図であり、不正養殖として禁止しない。
- 撃沈した海賊人口から、ミサイルなら50%、戦艦なら100%を難民受入候補にする。人口上限等による未受入分と実受入数を区別する。**受入後のturn-local cell同期は下記F1が未解決。**
- 戦艦は3,000億円、HP3、移動時石油3万バレル。通常は停止、進路指定時に一Turn最大1hex。自国の港が必要。射程5、最大1発、費用20億円、damage1、着弾ブレなし、防衛施設の迎撃を経由しない。怪獣の硬化は既存damage経路で有効。NPC船・あおいのら・自領の怪獣を自動攻撃し、Player船を自動攻撃しない。
- 宝船を沈めて宝を得るのは意図した仕様。戦艦の自動攻撃対象から除かない。
- 戦艦の経験値は秘書の海軍へ。怪獣は実HP damageに応じ、海賊は所持人口に応じ、宝船は7EXP。海軍の次Lv必要EXPは30、60、90…と増える。固有効果は未実装。
- 宝はterrain/facilityと別のstate。1セルに最大5個、超過時は最古を無報酬で除去。探索船の侵入または領土取得で回収し、所持枠不足なら宝を残す。新規島の上書き対象の宝は無報酬除去し、島破棄・再登録による回収をさせない。
- 通常宝はわくわくチケット1枚、上位宝はドキドキチケット1枚。わくわくはRegular・500億円、ドキドキは**High Quality・1,500億円**。HQというrarityは実装済みであり「HQを入れない」と言い換えない。チケットを消費する機能はまだない。
- 宝生成時のitem/quantity/rarity/固定売価をsnapshotし、回収時のitem instanceにも解決済みrarity/売価を保持する。売却・表示・交易場はその経済値を参照する。
- 通常視界内の宝は常時表示。遠方のキラッはNation×Turn×セルで20%、宝の個数で確率を増やさない。completed TurnRunのseedから再現し、reveal履歴tableを作らない。
- 遠方キラッはプレイヤー向け表示で、探索船AIへの遠隔座標配信ではない。Owner指定は自船の周囲3マスの宝を自動追跡すること。**他の自国船・領土の視界までAI候補に混ぜるF2が未解決。**
- 公開落下ログは「海賊船沈没／宝船沈没／隕石等に由来する」というsourceを表示してよい。落下event自体には宝の座標とチケット種類を出さない。別の沈没・災害情報から場所を推測できることはOwner許容済み。
- あおいのらは人口10万人以上のactive Nationを選び、所有陸地からちょうど4hex、他の陸地から3hex以内を除く候補へ出現する。アイテム補正は対象Nationの選出weightへ反映。候補がなければ遠方へfallbackしない。
- 新規島生成ではNPC船・退避可能なあおいのらを安全な海へ退避し、退避不能時は無報酬で除去する。Player船は安全退避できない候補を採用しない。

詳しい操作説明は[港と船](../manual/ships.md)、[地上の秘書](../manual/secretary.md)。マニュアルの自船半径3と難民受入の説明はOwner契約を表す公開用原稿であり、F1/F2の実装修正完了を意味しない。公開前に両者を一致させる。

## 4. 統合レビューの残件

| ID | 優先度 | 修正すべきこと |
|---|---|---|
| F1 | P1 | 海賊船撃沈時の難民受入先を、process_cellsの共有MapCellへ同期する。戦艦とミサイル双方で、後続の人口成長・攻撃に古い人口を使わせない |
| F2 | P2 | 探索船の自動目的地を自船からの半径3へ制限する。別船や領土が見ている遠方宝を、通常visibilityという理由で追跡しない |

詳細・source行番号・最小の回帰確認は[最終レビュー](../releases/4.2.0-final-review.md)。今回は文書のみを更新し、runtime修正は行っていない。新しい枠組みや全map再読込を追加するのではなく、変更cellの同期と近傍候補の限定として直す。

#11の前回レビューでF2を見落とした理由は、remote reveal条件の削除を確認して、通常visibilityがNation全体のunionである点を十分区別しなかったこと。Ownerが3マス条件を撤回したという意味ではない。

## 5. Migration・asset・検証

移行契約はexact v25→v26。source checksumは`c03af0ca57f167207740ad5bc5e201568335b9c45d417c0c865440a9548967de`、target checksumは`791ec7754ba794660fff3e27c1b287a481096055cf125d1080a6fe31c6f3d10b`。

必要な追加migrationは次の2本で、片方だけの適用を完成扱いにしない。

- `2026_09_15_000000_enable_npc_surface_ships.php`：NPC所有者nullable化・ship identity guard・exact v26 activation。
- `2026_09_15_010000_add_ocean_loop.php`：ship人口、宝、item解決済み経済値、海軍skill制約とbackfill。

既存Player船のRuleset provenance、queued request provenance、terminal履歴等は保護対象。実際にproduction使用したsnapshotは不変。未releaseのstabilizationを理由にv27を作らない。本番baseline・未解決Turn・適用結果はdeploy時に別途確認する。

追加assetの正式ファイル名は`ship-pirate.gif`、`ship-treasure.gif`、`ship-warship.gif`、`buried-treasure-sparkle.gif`。source参照は確認したが、外部asset directoryへの実配置・配信・スマホ実画面は今回確認していない。勝手に画像生成・配置・再作成しない。

テスト基盤はPhase 0a～6と#12まで実装済み。「次はPhase 3」へ戻さない。詳細は[設計図](../testing/test-suite-rebuild-plan.md)と[Phase 6・focused追補](../testing/test-suite-phase06-verification.md)。

- 過去のFull4は`49a632c2ca40ab10ae62182d8e9778c192a65b64`で121 files / 1,034 tests PASS、最長19分42秒。今回HEADの全件PASSへ読み替えず、以前よりFull全体が速いとも主張しない。
- focusedの39.13秒→8.80秒はPhase 6追補の同一代表の測定。初回template構築や今回の環境の測定と混ぜない。
- #12はfocusedかつreusable_surfaceのみのとき、検証済みtemplateからrun専用DBをclone。AppServiceProvider・InitialWorldBoundsを含むfingerprint、選択testのpath＋内容hash、既存rollback/cleanupを維持する。
- 今回の統合HEADでは、MCPでfingerprint代表1 test / 5 assertions PASSを独立確認。DB Feature、browser E2E、production、repository-wide Full/Full CIは実行していない。過去の#11/#12 focused結果は別のSHAの証拠として保持する。

## 6. 前releaseの判断を巻き戻さない

4.0.0～4.1.2の詳細は、Gitの`478ea7628577ca48f431c947e4a7c50ab85a2c39:product/docs/handoffs/current-status.md` §§2～5、および既存release文書に残っている。旧[統合handoff](development-history-and-current-handoff.md)自体は今回変更しない。そこにある3.9.3以前の現在地・未配布・未実装表示はhistorical evidenceである。

継承する重要点：通常地下combat v5 / skill tree v2、取得active5枠、成長方針と回復skillは別軸。レンタル確定・更新は借用者HP全快・覚醒0、その後は借り手側で持越し、再計算はHP割合維持、宿はHPのみ回復。王国の再調整済みbalanceを旧solo勝率へ戻さない。4.0.0等の配布を未配布と推定して再実行しない。

[案内人決闘](../releases/4.1.0-guide-duel.md)は通常探索とは別決算。通常HP/MP/覚醒/通貨/EXP/cooldown/貸出参加/日課へ結果を書き戻さず、初勝利グラムは一度だけ、装備・売却不可。女王HP351,400、round回復1,254、25%到達を記憶するaction境界、100round敗北というOwner決定を保持する。

決闘の初勝利後ソロ再戦は実際の確定PTの有無で隠し会話へ差し替える。過去PT勝利や「一人で挑む」意思フラグは不要。公開manual・告知・実績で存在や出し方を誘導しない。本名・抱擁等のsubsceneへ挑戦ボタンを増やさず、Owner台詞も改稿しない。王国解禁と回想1～5読了は別条件。

4.1.2のTurn/地下PT deadlockはSecretary本体の`FOR NO KEY UPDATE`でFKの`KEY SHARE`と共存させた。技能EXP側のwriter直列化は維持する。Owner報告のTurn478 manual retry完了を未解決へ戻さない。今回の資料から新しいproduction recoveryを開始しない。

## 7. 再開順と停止条件

まず#9のHEADを再解決し、F1/F2だけを修正する。実際に変えた境界の代表focusedと必要なstatic確認で止め、Full・Surface全件・Underground全件を自動再実行しない。修正後にこのレビューの残件を更新する。

その後に4.2.0のmigration/asset/source整合を確認し、Ownerの最終release判断を待つ。今回のhandoff・manual commitは、#9のdraft解除・main merge・deploy・migration適用・本番data変更・補填・次版実装の許可ではない。
