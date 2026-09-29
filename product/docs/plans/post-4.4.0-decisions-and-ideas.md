# 実装予定・構想：Owner判断と未決の範囲

分類：採用方針と未決の構想。過去releaseで実装済みになった項目も含むため、現在のruntime仕様は [current-contracts](../handoffs/current-contracts.md)、作業状態は [current-status](../handoffs/current-status.md) を優先する。release branch上の実装をproduction稼働と混同しない。

## 1. 採用された変更方針

1〜4は4.4.1 branchで実装した。5は固定値と重複テストを中心に整理中、6の最終構造はOwner判断待ち、7の分類・移動は今回の[文書一覧](../../../docs/documentation-inventory.md)を参照する。release branch上の実装をproduction稼働と混同しない。


1. トロフィーの見せ方はまずバハムル初級1・中級1。**内部の初回勝利記録は異世界ボス全レベルを対象**にする。
2. 大予告は数字の色だけで済ませず **「大予告が付与された。」** と出す。あわせてPT戦でバフ・デバフ一覧が見えない問題を調査・対応する。
3. 地下ホームの同行者アイコンを接続する。ホーム小アイコンへ権利情報ボタンは出さない。
4. 能力等の通常表示は装備込み。**STP配分だけは現在どおり裸ステ＋振り分け**。装備・最終値の横列を増やさず、スマホの見やすさを守る。
5. test／DB固定値／validatorの棚卸しは継続する。特に、configやbalance値をtestへ写し、値変更時に期待値も同時変更するだけのtestを増やさない。表示全文・catalog件数等の偶然の固定も避ける。
6. 過去versionをアプリ内で共存・ダウングレードさせるためのRulesetを維持したくない。更新識別用の符号は残してよいが、旧互換・累積処理を可能な範囲で簡素化したい。最終構造は未決。
7. **現行repoの大量のdocs全体**を棚卸しし、現在使う文書、実装予定・構想、過去の記録を整理する。過去のdocsはarchiveへ移し、**Ownerの明示指示なしにAgentに参照させない**。AGENTS・通常検索・索引も整理する。handoffの三分割はその一部で、ZIP内だけの整理を完了扱いしない。初回の配置と未完範囲は `docs/documentation-inventory.md` を参照。

## 2. 4.5〜4.6で前進したもの

- 試練3「天光の王城」は4.5.0で実装済み。
- 4.6ではIL200超の武器力 / HP倍率 / rating、IL210バハ装備・共鳴結晶、研磨・貸出・表示接続を実装済み。
- 狩場4「夕凪の帰港地」と専用宝物庫は4.6 release branchへ実装済み。Lv700入口、IL220世代、通常11 + elite4 + rare「クリスタル・ドルフィン」。
- 4.6のrelease closure状況は[current-status](../handoffs/current-status.md)を正本とし、この構想帳から完了状態を逆算しない。

## 3. 構想・留保・別件（実装へ自動追加しない）

- **試練4**：夕凪の帰港地の白い大井戸の先。緑の粘液に溶けた旧前線村跡と過去storyへつなぐ。案内人の反応・台詞はOwnerが決める。公開時期未決。
- **地上Relic・合成・同系統育成・ticket圧縮**：[地上アイテム拡張メモ](surface-item-expansion.md)へ分離して継続記録する。別releaseへ自動追加しない。
- **地上の配置synergy・電力・二次/三次産業・属性魔力**：[地上発展拡張メモ](surface-development-expansion.md)へ分離して継続記録する。地底攻略を必須gateにしない。
- **装備player market**：武器種RNGのミスマッチを緩和する候補。方式・通貨・取引安全性はUG-05で判断する。鉱石/素材市場案は一旦保留。
- **転生**：Lv上限、成長力、必要XP、節目のaccessory / active skill枠等は候補。既存高Lv者の扱いを含め未決。
- **防具の物防/魔防特化**：2026-09-29時点でOwnerは見送る方向。active TODOに戻さない。
- **研磨量の量的再設計 / グラム表示**：将来候補。4.6 blockerではない。
- **共有HP raid**：将来分。既存設定へ勝手に追加しない。
- **Turn C2**：同一起動内限定retry C1は実装済み。failed/blocked TurnをOwner不在でも安全に自律復旧させる後続処理は未実装。対象失敗とsame run/turn/ruleset/seed、backoff、上限、auditを決めてから着手する。
- **idea memo MCP**：長いChat contextへ埋もれる案/TODOを、軽量な外部memoとしてappend/search/list/status管理する開発補助案。repoの仕様正本とは分ける。実装未決。
- Agent/CI全面改修は別作業。
- 旧handoffから引き継がれるMCP-F1/M8/M9/M10、Safari N19は現在未再調査。現行の確定不具合やblockerとして自動計上しない。必要時は現行code・現象を調査し、旧archiveはOwnerの指示なしに開かない。

## 4. 地上と地底の境界

地底RPG攻略の地上還元は**首都地下の施設群**。地底農場・地底工場等で基礎産業を補助することはあり得るが、地上の新産業・電力・配置synergy・属性魔力を地底攻略必須へ変えない。

地上側は、人口→作物/工業品/鉱物、油田→原油、将来の発電→電力を基礎に、高付加価値な二次/三次産業へ進む構想。詳細は[surface-development-expansion.md](surface-development-expansion.md)。

## 5. 実装時の注意

通常の表示は装備込みに変えても、戦闘計算へ装備値を二重加算しない。PT表示は同期後の同行者実効値と、借用元の元の能力を区別する。

Rulesetを簡素化する際、Gitでコードを戻すことと本番DBの資産・進行を戻すことは別。アプリ内ダウングレード機構を作る必要性と、現存データを壊さない更新手順の必要性を混同しない。これはダウングレード機構の維持要求ではない。


構想メモはOwnerの意図を忘れないためのもの。実装時はcurrent code / Ruleset / open gateを再確認し、未決値・名称・効果をAgentが推測で確定しない。
