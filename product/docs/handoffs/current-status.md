# hakoniwa-world 現在地・独立レビュー引継ぎ

更新日：2026-09-14 JST。OwnerからWeb版ChatGPTへ「PRレビュー、handoff更新コミット、関連横断レビュー」の依頼を受けて更新する。

本書の対象は4.0.0までの到達点とForgejo PR #6の4.1.0候補。対象範囲の現在地は本書を先に読み、[旧統合handoff](development-history-and-current-handoff.md)の「3.9.3 hotfix候補」「3.9.0現在地」等へ戻さない。旧本文は過去の判断・事故・配布記録として、削除・整形せず保持している。詳細仕様の正本は固定SHAのcode・release文書・Owner補足である。

## 1. 固定refと現在地

| 項目 | 確認結果 |
|---|---|
| 正本remote | Forgejo `https://git.pbwlove.com/Mamiki765/hakoniwa-world.git` |
| main | `319ea20165a7e7e111749c4f5d9f337f1045a1a0`。`product/config/hakoniwa.php`のapplication versionは4.0.0 |
| 対象PR | #6 `feat: 4.1.0 夢の女王との決闘・宝物庫ソート・障壁表示` |
| 作業branch | `release/4.1.0` |
| PR base | `319ea20165a7e7e111749c4f5d9f337f1045a1a0` |
| 初回レビューHEAD | `3c7cc5434b811aeea1e848c2b2f19503d3fe7e47` |
| 今回独立レビュー済み実装HEAD | `a9150caa94ccfc1034b3152a0ff24597d07e24fd` |
| レビュー記録 | 初回#52、Owner訂正#53、実装自己確認#55、今回独立再レビュー#56（formal review ID 4） |
| 判定 | 今回確認した修正差分・関連横断で新規P0/P1/P2なし。ソースレビュー上のmerge blockerなし |
| 未実施 | PR #6 merge、4.1.0 deploy・本番migration、production data変更。この引継ぎcommitは文書のみであり、本番反映ではない |

この文書を追加するcommitのSHAは自己参照で記入せず、PR HEADを再解決する。実装検証対象の`a9150ca…`と文書追加後HEADを区別し、その差分が文書だけであることを確認する。#55の自己検証を独立レビューとして扱わない。

PR #5 `hotfix/4.0.1` / `dd4b6c9ac9b91f66d181089fad0a00e51a72f14c` は、PR本文で#6へ統合済みと明記されている。照会時点ではopen・未merge。取り込み先は#6であり、#5を別途merge/deployする必要はない。

## 2. 本番観測の範囲

MCP `production_status`の生成時刻は2026-09-14 11:29:28 JST（02:29:28Z）。Web/DB healthy、Turn474、未解決Turnなし、Surface Ruleset v25 / ID40、稼働checkoutについてpending migration 0。checkoutはmainの`319ea20…`、imageは`sha256:df328acdb3a834966cee0fdd94ab4c4ee58a15287d26266d4dd8579c9348baa7`。

ただし`deployed_sha`と`application_version`はnull、deployment statusはunknown。checkoutのversionを稼働imageの厳密な証明に読み替えない。pending 0も、未mergeのPR #6 migrationが適用済みという意味ではない。本レビューではread-only snapshot以外の本番操作をしていない。

## 3. 4.0.0までの到達点

前チャットでPR #4は`f44c57bfca18b96627d48f9f649549b9f1cf2fdc`へfast-forward merge成功が確認され、その後mainのapplication versionは4.0.0へ変更されている。3.10.0名のrelease文書は4.0.0に至る設計・測定の記録であり、別の未公開機能として再実装しない。

- 地下の戦技・護身・祝福3ツリー、取得後のactive5枠、旧SP割当・custom AI解除と装備保存による再開。成長方針と取得ツリーは別軸で、回復能力はskill/effectに帰属する。
- 通常combat v5 / skill tree v2。Surface Ruleset v25は維持。未適用だったSP返還・レンタル状態・お知らせbody_formatのschema変更は4.0.0候補内の1本へ統合されたが、適用済みmigrationを今後自由に書き換えてよいという許可ではない。
- レンタル確定・更新で借用者全員HP全快・覚醒0。その後は借り手側でHP・覚醒を持ち越す。再計算時はHP割合維持、宿はHPだけ回復。貸出公開設定自体を解除したわけではない。
- 王国の通常12種weapon_powerは旧値の2倍、強敵2種は2.5倍、レア据え置き。共通定義を使用する宝物庫側にも反映される。最終被ダメージの倍率や全ステータス倍率ではない。PTのtarget分散・挑発・回復・範囲技を含む再評価後にOwnerが許容した値なので、旧solo勝率だけへ合わせて戻さない。
- 木人の基準は100round、200roundはMP持続確認用。旧構成相当の役割感を守り、攻撃枠を増やした構成を一律80%に抑えない。技巧は武力・精神の両方で上振れを確認済み。ここを4.1.0の会話修正で再調整しない。
- お知らせはplain_text/markdownを区別し、旧記事の形式・日時・本文を保持。Markdownは共通rendererでraw HTMLを除去し、危険リンクを許可しない。プレビューは保存とは別。

お詫びについてOwnerは全島へ輝石300・スキップチケット100という決定と、配布済み表現の告知文を提示した。本レビューでは4.0.0のgrant登録状況を照会していない。未配布と決めつけて再実行せず、必要なら既存grantを先に照合する。旧handoffにある3.9.0の29件登録済み配布も別件であり、再配布しない。

## 4. 4.1.0の確定仕様と修正

詳細は[案内人との決闘](../releases/4.1.0-guide-duel.md)を正本とする。元の添付案のHP1,001,254・毎round10,000回復ではなく、その後のOwner決定としてHP351,400・毎round1,254回復が記録されている。差だけを誤実装としない。

### 決闘・記念品

女王は固定1体・固定能力で、PT人数による補正なし。確定済みの通常編成で挑み、決闘専用のPT切替を作らない。無料・通常探索cooldown不使用。開幕はHP0・覚醒未解禁・ゲージ不足も含む全員を全回復・強制覚醒させ、その後に女王の開幕奥義。25%以下到達を記憶して当該actionの残りhit中はHP1を保護し、action終了後に2回目の奥義。それ以降は実ダメージで撃破可能。100round未決着は決闘の敗北扱い。

通常のHP・MP・覚醒・通貨・経験値・探索cooldown・貸出参加・日課へ結果を書き戻さない。決闘履歴・専用clear回数・初勝利記念品は保存する。初勝利はLeader自身のSecretaryごとに判定し、魔剣グラムを1個だけ付与。装備/個別売却/一括売却はserver側でも不可、能力説明は表示のみ・所持効果なし。満杯時の一度限り1個超過はOwner採用済み。通常の購入/drop容量制限は維持する。

### 会話と隠し台詞――Owner #53と後続の説明を優先

| 状態・選択 | 意図した処理 |
|---|---|
| 真剣な話root | 本名を聞く → 抱き締める → 勝負を挑む（解禁時）→ 戻るの順 |
| 本名を拒まれた直後 | それでも教えて欲しい / あなたについて知ることが私の夢だと伝える / 彼女に自分がつけた名前を呼ぶ / 立ち去るの4択 |
| それでも教えて欲しい | 既存の名付け時branch_identityにより、付けた名前の応答またはリカ・苗字・魔王・種族の話 |
| 私の夢だと伝える | 「………………」「リカ。」 |
| 自分がつけた名前を呼ぶ | 「そう。それでいい。」。本名を明かさせる前の並列選択であり、明かした後の両sceneには重複配置しない |
| 本名/抱擁などのsubscene | 勝負を挑むを共通ボタンとして出さない |
| 決闘未勝利 | 何度負けても初挑戦会話。挑戦回数で分岐しない |
| 勝利済み＋今回PT | 第二形態・第三形態のおふざけ会話 |
| 勝利済み＋今回ソロ | 上記会話を隠し皮肉会話へ丸ごと差し替える。過去PT勝利を要求しない |
| やめておく | 取消会話のみで、戦闘・勝利フラグを作らない |
| 初勝利/再勝利 | 共通勝利会話＋それぞれの追加台詞。初勝利だけグラム。UUID再取得で勝利/記念品を増やさない |

リカは「一人で挑むなんて勇敢」と称賛するのではなく、あえて仲間を連れず来る縛りプレイを見抜いて皮肉る。隠し会話の存在・出し方を公開UI、manual、告知、実績で誘導しない。「初勝利もソロなら不自然」という以前のAssistantの懸念は撤回済み。PT勝利履歴や縛りプレイ意思フラグを追加しない。実際の確定編成を使い、未解決request再送中は送信時の編成を保つ。

王国解禁と過去1～5読了の条件は別で、今回変更していない。王国解禁・回想未読了ではrootの選択は決闘＋戻るのみ。既存の本名/抱擁の内容は過去1～5読了後。会話の場所を直すことを、新たな解禁制限を加える指示へ読み替えない。Owner台詞は勝手に改稿しない。

### 障壁・宝物庫・UI

PT projectorのbarrier amountは表示時に正数へ変換。solo/PTのHP数値は障壁がある場合のみ「現在HP +障壁 / 最大HP」。内部ログの符号・HPゲージ計算は維持する。他人への障壁では術者と受給者を区別し、#52の「護衛がレイへ張ったのに護衛が得た」表示も修正済み。

宝物庫は装備中5枠を武器→防具→アクセサリー1→2→3で先頭固定。未装備は入手の新旧・IL・レア度・種類順、全体をソートしてからページ分割する。選択順はlocalStorageへ保存し、不正値や保存不能時にも操作できる。グラムは固定品でもuniqueとして扱い、売却対象には入れない。

前回列挙した地下画面/宝物庫の英語eyebrowは削除済み。日本語見出し・HP/MP/Lv/IL・Ownerの英語を含む台詞まで一律に変換する依頼ではない。

## 5. 独立再レビューの証拠と限界

対象`a9150ca…`。前回`3c7cc54…`からの変更12ファイルを確認し、確定編成/同UUID再送、初勝利と報酬、装備禁止・一括売却、通常探索との決算分離、既存combat、migration CHECKの関連経路を横断した。#56へ記録。実装の自己確認だけで完了扱いにしていない。

- MCP隔離runnerで決闘2・蘇生1・反撃1・回復恩寵1、5 tests /46 assertions PASS。JUnitのerror/failure/skipは0。stdoutはfiltered。
- exact-SHA sourceの実PHP combat/projectorをローカル実行。通常の味方障壁について、実Vue文章関数まで通して「護衛の『護法陣』でレイは障壁を9267得た」を確認。
- 実Vue関数の抽出プローブで再戦12条件、root/subscene8条件、sort保存/不正値/利用不可8条件を確認。実configの会話分岐と、実sort closure/rarityKeyを用いる506件の並べ替え・装備5枠優先・ページ境界も確認。
- これらは合成の狭い確認であり、Vue全体のmount/browser E2EやDBからの取得を含むFeature testではない。
- 今回の独立frontend/browser実行は未完了。ローカルnpmはregistry.npmjs.orgのDNS失敗で依存取得不能。以前のMCP runnerのvitest Permission deniedについても解消を確認していない。375px実画面、PostgreSQL migration/concurrency、全suiteの独立再実行は未実施。
- 実装担当のPHP5件/172assertions、frontend23件、typecheck/lint等は#55の自己検証として別記録。以前の55 tests/661assertions・frontend30件等を最新HEADで全件再実行したとは言わない。

## 6. 次に進めるとき

PR #6はOwnerのmerge判断待ち。HEADを再解決し、今回のレビュー後にruntime変更があればその差分を確認する。文書追加だけなら、それを理由に全suiteを回し直さない。merge/deploy/本番migrationは今回許可されていない。

push/初回PR/修正PR/レビュー投稿は承認済みrepositoryの通常作業として個別確認不要。GitHubかForgejoかで権限を分けず、CIコスト管理は別扱い。現在のForgejoへのpushを、以前のGitHub CI反復の事情で止めない。実行環境側の送信先承認で止まる場合は、その層の制限とOwner方針を区別して報告する。

`OPEN`は延期済みではない。決定済みの回復scopeや今回の会話位置を再び未決に戻さず、逆にAssistant案をOwner固定仕様として昇格させない。既存testが存在するだけで恒久仕様としない。
