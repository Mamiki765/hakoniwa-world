# 現在地：repository main 4.5.1 / 4.6設計準備中

更新：2026-09-28。GitHubの実HEAD、Ownerの今回の設計判断、過去にOwnerが提示したproduction証拠を分けて記録する。

## 開発の固定点

- 開発正本：`Mamiki765/hakoniwa-world` / GitHub。
- repository main：`8b65d320a97caa0ca99735fc20445592f95a9c84`、`fix(underground): remove skill investment gates in 4.5.1`。4.5.0 merge commit `00b7b6517fc31996403ef0b5f797dd4606fcf019` の次のcommitである。
- 4.6のrelease branchはまだ作成していない。設計・調査共有用の通常branchは `codex/4.6.0-chat-preparation`。
- 4.6準備の入口は [4.6準備：Chat/Codexの分担と装備・狩場の着手境界](../plans/4.6.0-chat-preparation.md)。現時点ではruntime、schema、application version、Ruleset、production dataを変更していない。
- handoff更新を含む準備branchのHEADは、この文書更新後の実HEADをGitHubで確認する。mainへの直接更新、PR merge、production deployは別Owner gate。

## Productionの最後の確認済み証拠

この文書更新ではproduction DB・health・稼働versionを再照会していない。repository mainが4.5.1であることを、production適用済みの証拠として扱わない。

最後にこのhandoffへ保存されていたproduction証拠は、2026-09-27 06:31 JSTのOwner提示静止画による **ver 4.4.0 / T628 / 正常**。最終更新9/27 06:00、次回予定08:00、24島、総人口10,761,185人と表示されていた。2026-09-23のOwner提示ログでは4.4.0切替、統合migration batch43、Ruleset v27 / DB ID42、T588のcron完走、予定起点設定まで確認されている。詳細は [deployment status](../operations/current-deployment.md) を参照する。

4.4.0適用・T588・予定起点設定をやり直さない。30日receipt purgeは運用開始未確認・未実施扱いで、保全とOwnerの別承認が先。1時間の詳細ログ保持とは別。

## 4.5.xでrepositoryへ入った主な地下変更

- 4.4.1で、Bahamul各stageの初勝利時刻保存、trophy表示、party表示・同行者icon・装備込み能力表示、日課modal、歪んだ石の初回未取得通知、skip ticket狩場初期選択などが追加された。
- 4.5.0で、試練3「天光の王城」、Trial3報酬、140SP帯のactive skill群、待ち時間中10Pdの即時再振り、Trial3 balance/versioning修正が入った。
- 4.5.1でskill investment gateを撤去した。4.5.0資料中の96SP gate表現をcurrent contractとして扱わない。
- Trial3以降の地下拡張、装備次世代化、転生などは自動的に実装済み扱いにしない。

## 4.6準備の現在地

Ownerとの2026-09-28相談で、次の方向を保持する。数値の最終仕様とrelease scopeはまだ確定していない。

- 通常装備はIL200を次世代倍率の基準 `×1.00` とし、200超は10ILごとに約 `×1.10` で伸ばす方向。
- 中級1 Bahamul武器はIL210のご褒美として残す方向。旧来のweapon power等の加算値は当面内部値として残し、主表示から隠す案。
- 黒竜の共鳴結晶はIL210から200へ正規化する方向。現在の実性能・型・affix・研磨を失わせない。
- 防具も世代倍率でHP等を伸ばし、HPだけが伸びて回復が相対的に弱くなることは避ける。回復の倍率適用位置は実装前に確定する。
- 通常装備のmodifier affixはrating表示へ移す案。例：`クリティカル率 +431（+7.8%）`。必要ratingは武器・防具ILに応じて上がる。旧IL101〜200の横ばいを勝手に再計算して埋めない。
- 低IL鎧を残して高いaffix実効値を優先し、その代わりHPを失う構成は許容する方向。
- 鉱石・素材市場案は一旦保留。装備のプレイヤー間売買は「欲しい武器種が出ない」問題への解決候補として残す。market transactionと不正対策は `UG-05` のOpen gateを迂回しない。
- 狩場4は「夕凪の帰港地」、装備帯は200〜220の方向。以後は狩場/試練ごとに10ずつ進める案。
- 試練4は港の白い大井戸の先の旧前線村構想があるが、4.6必須とはまだ決めない。案内人の反応・台詞はOwnerが決める。
- 大部分の新システムは狩場3「輝きの王国」で見える/使い始められる拠点型にする方向。
- インフレ自体は非目標ではなく、FFA系の長期成長として許容する。将来の転生はLv上限・必要XP・成長力・アクセ枠・技セット枠拡張の候補。

## 次に詰める順序

1. 装備次世代化の小さなcontractを確定する。特に、ratingの基準IL、武器倍率の対象、HP倍率と回復倍率の適用位置、固定特殊効果とrating affixの境界。
2. Codex側で、現行の所有装備解決・貸出snapshot・研磨・既存generator identityを横断して、移行を含む実装案と代表実測を作る。全IL×全buildの恒久test matrixは作らない。
3. Chat側で「夕凪の帰港地」の敵役割・景観・報酬/XP候補を進める。装備式確定前に敵の最終数値を固定しない。
4. 装備式を前提にCodexで狩場4の勝率・DPS・HP・回復を代表構成で調整する。
5. 装備売買、転生、試練4、その他の長期案を4.6へどこまで含めるかは別途Ownerがscopeを決める。

## 既存の持ち越しと読み方

- 4.4.1時点の残件や記録は [4.4.1 TODO](../plans/4.4.1-todo.md) と [実装・検証記録](../releases/4.4.1-chat-implementation.md) に残るが、current mainより古い途中状態をそのまま現在地と解釈しない。
- 現行仕様は [current-contracts](current-contracts.md)、未決の構想は [予定側](../plans/post-4.4.0-decisions-and-ideas.md)、設計gateは [open-questions](../../../docs/open-questions.md)、文書入口は [docs](../../../docs/README.md)。
- T01 MCP production exporterは別系統の未解決事項。4.6装備・狩場作業へ混ぜない。
- 貸出コメント、用途別AI/技保存セット、地下header art、小画面footer、地上アイテム/相互リンク、秘書チャット等は持ち越し候補。今回の4.6必須scopeへ自動昇格させない。
- archiveはOwnerの明示指示なしに通常検索・読込対象へ戻さない。

mainへの直接commit/push、PR merge、production deploy、production DB変更、purge、cron登録、補填はこの準備作業では実行しない。
