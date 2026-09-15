# hakoniwa-world 現在地・4.2.0引継ぎ

更新日：2026-09-15 JST。4.2.0 test suite再設計のPhase 2 push時点に合わせて更新する。

本書の現在地はmain 4.1.2と`release/4.2.0`のtest suite再設計。4.1.2までのincident・仕様記録は過去の判断と運用contractとして下に保持する。詳細仕様の正本は固定SHAのcode・release文書・Owner補足である。

## 0. 4.2.0 test suite再設計の進行契約

4.2.0の現在の主作業はtest suiteのゼロベース再設計である。Astra Xhighが作成した[設計図](../testing/test-suite-rebuild-plan.md)を正本として、SolがそのPhase順に実装を進めている。OwnerがSolへ渡した作業範囲を優先し、この会話で後から提示された追加条件は作業契約へ盛り込んでいない。repo文書またはOwnerの明示指示へ反映されていない会話上の追加案を、後から必須条件へ昇格させない。

開始時にForgejo mainが`00182bd0eaee52f34194c7b40bc5e98712108718`で遅れていないことを確認し、そこから`release/4.2.0`を作成した。Draft PR #9で継続中で、Phase 2 push時点のHEADは`0e6db357e603f42f92e0f18f4f6549775a600218`である。HEADは今後進むため、作業再開時はPR #9を再解決する。

- Phase 0a：AGENTS §8のtest増殖防止規則を整理。新設・拡張の必要性、重複matrix、確認終了条件を明文化した。
- Phase 1：Aランク不要保証を削除・縮小。mainの1,058 casesから1,052 casesへ6件純減し、このPhaseでは新規caseを追加していない。focused 68 tests / 2,680 assertions、変更frontend 2件、Pint・ESLintはPASS。
- Phase 2：Shared / Surface / Undergroundのscopeとdispatcherを実装。Full=Shared+Surface+Underground、Surface=Shared+Surface、Underground=Shared+Undergroundとして、同じplanner/runner/DB manager/evidenceへscopeを通した。Sharedへの移動と既存SP migration caseの分離を行い、scope validationの1 method追加後は117 files / 1,053 cases。Full 1,053、Surface 835、Underground 291のserial/4-shard identifier一致を確認している。
- Phase 2ではrepository-wide Fullはまだ実行していない。planner/dispatcher/Shared migration等のfocused確認を実施。development image buildはGitHub archive timeoutで完了せず、既存containerでComposer entrypointまで確認した。
- 次は設計図Phase 3の通常map case用reusable fixture、初回generation、case rollback、独立性とfixture回数・時間の計測。Phase 3以降も設計図の順序を正本とする。

このtest再設計だけを理由にruntime、Ruleset、application schema、production dataを変更しない。merge、deploy、production migration/data/Turn操作は別のOwner許可が必要である。

## 1. 固定refと現在地

| 項目 | 確認結果 |
|---|---|
| 正本remote | Forgejo `https://git.pbwlove.com/Mamiki765/hakoniwa-world.git` |
| main / 4.2.0 base | `00182bd0eaee52f34194c7b40bc5e98712108718`。application versionは4.1.2 |
| 作業branch | `release/4.2.0` |
| Draft PR | Forgejo PR #9。Phase 2 push時点HEAD `0e6db357e603f42f92e0f18f4f6549775a600218` |
| 設計正本 | `product/docs/testing/test-suite-rebuild-plan.md`。Astra Xhighが設計し、SolがPhase順に実装中 |
| 実装済み | Phase 0a、Phase 1、Phase 2 |
| 次段階 | Phase 3：map再利用fixtureと独立性・生成回数計測 |
| production | 4.2.0 test作業ではdeploy・production DB・Turn操作を行わない。mainのversionからproduction適用状態を推定しない |

## 2. 本番観測の範囲

Owner提供のincident記録では、Turn478 attempt 1が`finalize_turn`中のPostgreSQL `40P01`で失敗した。Turn側のSecretary batch `FOR UPDATE`と、地下PT snapshot側の`underground_party_members.secretary_id` FK参照が、Leaderと借用Secretaryを逆順に待っていた。Ownerは同じTurn・Ruleset・seedでmanual retryしたattempt 2の完了と、`current_turn=478`を確認済みで、追加recoveryは不要である。

4.1.2作業ではproduction状態を再照会・変更していない。上記はOwner提供記録であり、このbranchの検証は隔離したPostgreSQL test DBだけで行う。

### 2.1 4.1.2のlock contract

`SecretaryTurnService::flushExperience()`がSecretary本体で更新するのはnon-keyの`monster_experience`である。`FOR NO KEY UPDATE`は他のwriterを直列化したままFKの`KEY SHARE`と共存するため、lost update防止を維持しつつ今回のcycleを切る。Secretary skill EXPは別tableを従来どおり`FOR UPDATE`する。Underground側のsnapshot・profile・equipment・skill整合性、4.1.1のお問い合わせlock修正、manual retry契約は変更しない。

中央銀行・中央穀倉は、ともに既存の`ordinary` quantity経路を使用する。同じセルでは1Turnごとにquantityを1減らして新設から増設へ進み、別セルの同種2施設目は既存の1Nation 1施設制限で拒否される。現行contractで意図を満たすため、この調査によるcode変更は行わない。

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

`release/4.2.0`はDraft PR #9で継続する。再開時はPR HEADを再解決し、[test suite再設計図](../testing/test-suite-rebuild-plan.md)と各Phase implementation記録を先に読む。Phase 2まではpush済みで、次はPhase 3のmap再利用fixture。Phase 2以降の進行でこのhandoff自体が古くなっていれば、設計図とPR HEADの実装記録を優先する。

4.2.0のtest suite再設計はAstra Xhighの設計図をSolが順に実装する作業であり、この会話だけで提案された追加条件を暗黙に混ぜない。Ownerが別途scopeを変更した場合は、その明示指示またはrepo文書を正本として更新する。

push/初回PR/修正PR/レビュー投稿は承認済みrepositoryの通常作業として個別確認不要。GitHubかForgejoかで権限を分けず、CIコスト管理は別扱い。現在のForgejoへのpushを、以前のGitHub CI反復の事情で止めない。実行環境側の送信先承認で止まる場合は、その層の制限とOwner方針を区別して報告する。

merge、deploy、production migration・data・Turn操作はOwner明示承認なしに行わない。`OPEN`は延期済みではない。決定済み仕様を再び未決に戻さず、逆にAssistant案をOwner固定仕様として昇格させない。既存testが存在するだけで恒久仕様としない。
