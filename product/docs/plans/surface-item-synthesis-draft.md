# 三紋章合成のDraft実装

基点はmain `91005f7c91f3fec4a64be300c1a1eba35277dd93`。Ownerの2026-10-02指示は「地上アイテム合成のひな形として夢魔の三紋章合体レシピだけを実装」「配置は裁量可」。この文書は既存の採用方向と、このDraftで置いた提案を区別する。

## 見つかった根拠

`docs/README.md`の現行導線から[地上アイテム拡張](surface-item-expansion.md) §5と[Owner判断・構想](post-4.4.0-decisions-and-ideas.md)の地上アイテム行を確認した。archiveは検索・閲覧していない。repositoryに追加の `.agents/skills` / 下位AGENTSはない。runtime指定のローカル `memory_summary.md` と必要語の索引検索は利用したが、レシピ仕様の根拠にはしていない。現行コードとissue/PR検索でも追加のレシピ決定は見つからなかった。

| 対象 | 既存プラン・現行コードで確認できたこと |
|---|---|
| 愛の紋章 | stable key `love_emblem`、Artifact、最大Lv1。通常・誘致人口増加 +10%。 |
| 双子星の紋章 | `twin_star_emblem`、Artifact、最大Lv1。最終防衛線の消費を10%で防ぐ。 |
| 三日月の紋章 | `crescent_emblem`、Artifact、最大Lv1。基地経験値2倍抽選。 |
| レシピの組み合わせ | 上の三素材から「夢魔の紋章」。素材育成・Lv継承は不要。 |
| 成果物 | Relic、Lv1固定、合成限定の隠しアイテム。自然回復・誘致人口増加2倍、指定flavor。既存のstable keyは存在しない。 |
| Relicの売値 | catalog / v27のrarity値は6,000億。これは合成費用ではない。 |
| 現行上限 | 地上倉庫50個、装備5枠、同じitemの装備は1個まで。三素材の所持unique制限はない。 |
| 未決 | 合成費用、各素材の消費単位、解放・開示、成果物の交易可否、重複効果の具体的扱い。 |

コードの根拠は `SecretaryItemCatalog`、`config/hakoniwa/rulesets/current/secretary.php`、`SecretaryItemGameplayContract`、`SecretaryItemGrantService`。数値IDを固定せず、素材はユーザーが所有するinstance IDで指定する。

## Draftの提案（Owner確定事項ではない）

- 新規item / recipe keyを `succubus_emblem` とする。アクセサリーとして付与する。
- 各素材Lv1を1個ずつ消費し、Lv1成果物を1個だけ付与する。追加費用0億、Turn消費なし。資金支払いengineは作らない。
- 素材は本人の未装備・未出品instanceだけ。自動解除せず、別の素材への置換も行わない。
- 本人が三素材を同時に所持した場合だけ、本人の倉庫に合成カードと成果物を開示する。解除条件に島・地底・Lv・施設を新設しない。素材を消費した後は再び揃うまで候補を非表示とする。
- 成果物はplayer交易不可・NPC出品不可、通常抽選から除外。直接売却は既存Relic価格を使う。複数所持は倉庫上限内で許可し、装備は同じitem1個まで。
- プランの「自然回復」は地上の通常人口増加として接続する。単独装備では既存 `population_growth_percent` の+100%で、通常・誘致の基礎増加量を2倍にする。既存人口上限・丸め・不屈の追加増加順序は維持する。愛の紋章と同時装備すると既存item genreの加算方針で+110%となる。**これらの接続・併用の扱いもレビュー判断事項。**

## 安全性と境界

World advisory lock → World row → Secretaryのsurface state → 指定素材rowの順で排他取得する。既存 `CurrentRulesetGuard` と未解決Turn guardを通す。認可はsession User自身のSecretaryから解決し、外部入力でSecretaryを選べない。

同一transactionで全素材検証、3個削除、既存grant経路による1個付与、receipt記録を確定する。倉庫は消費後の使用数で判定するため50個満杯でも合成でき、最終48個となる。装備versionを変える操作はしない。素材削除後にgrant/receiptが失敗すれば全体rollbackする。

receiptは `(secretary_id, request_key)` のuniqueと排他で保護し、素材IDを昇順で固定する。同じUUID・同じ素材は保存結果を返す。UUID使い回しで別素材へ変更する操作は拒否する。別UUIDで消費済みIDを再指定しても、新しい素材集合を勝手に選ばない。成果物の売却・削除後もreceiptを保持し、retryで再付与しない。receiptのpurgeは実装しない。

UIは送信前に消費と成果物を確認し、連打を抑止する。通信結果不明時は同じUUIDと素材IDをページ内・sessionStorageに保持し、再表示後もその結果だけを照会する。World lock競合の409も元の処理の完了を否定しないためintentを保持する。それ以外の確定した409/422拒否でintentを解除する。未発見レシピは本人APIでも空配列。公開プロフィールへrecipeや倉庫候補を追加しない。取得後に装備した成果物の表示は既存の公開装備contractを使う。公開source code自体を秘密にする意味ではない。

## Ruleset・migrationと並行baseline修正

本番使用済みv27のpayload・checksum、`config/hakoniwa.php`、published list、baseline dump・manifest・検証器は変更しない。v28は**未公開draft entrypoint**としてv27の未変更domainを再利用し、Secretary domainに成果物と1レシピだけを加える。通常のアプリ起動はv27で合成候補なし、POSTも拒否する。

receipt migrationは `database/migrations/draft-synthesis/` に隔離し、通常のLaravel migration探索では実行しない。テストが専用DBで明示的に読み込む。この配置は未決仕様をproductionへ自動有効化しないためのDraft境界であり、別schema authorityではない。

依存先は未mergeの[baseline修正 PR #187](https://github.com/Mamiki765/hakoniwa-world/pull/187)、固定SHA `40637d71ced0d78b52600d62d42febf3e8762751`（4.9.2 / v27）。このPR自身の基点は引き続きmain `91005f7`で、#187をmainや本番適用済みとは扱わない。将来fresh DBは修正schemaから通常baseline migrationでv27・markerを作成してからreceipt追加、既存DBは追加tableのない状態で修正baseline採用検査・markerを完了してからreceipt追加する。

将来有効化する場合の順序は、(1) baseline担当の修正を取り込みv27基準DBの採用検査・markerを完了、(2) receipt migrationを通常探索対象へ移動してfresh / v27採用済みDBの追加DDLを検証、(3) 同じ次releaseの単一v28 draftに統合してimmutable publication、World・queueの版切替を設計・検証、(4) Ownerによる公開・本番適用の承認、となる。追加tableのあるDBを旧v27 baseline構造と同値とは判定しない。新しいmigrationをbaseline検査の例外へ黙って追加しない。

**このPRは(2)のmigration登録、v28の本番publication/upgrade、Web切替、mergeを実装・実行していない。** 上記の未決提案と有効化順序のレビューが完了するまでDraftを維持する。並行作業との共通箇所は `App.vue` のimportと倉庫へのcomponent差込みのみ。baselineのschema基盤と別荘UIは触らない。

## 確認の範囲

新しい代表は、満杯倉庫での消費と付与・UUID再送・payload変更拒否・別UUIDによる重複拒否、所有権/装備/escrow、grant失敗rollback、発見前秘匿・公開プロフィール、実際の合成→装備→通常/誘致Turn効果、UI連打・不明結果の再表示を確認する。v27の既存checksum・アイテム契約・チケット代表を併せて確認する。

別PHPプロセスによる消費中の競合と同一receipt再送、PC/320pxのlight/dark/black表示は隔離DB・ローカルpreviewで確認する。previewのAPIデータはfixtureで、実DB接続のブラウザE2Eとは区別する。repository-wide回帰はGitHubのexact-head Quality CIへ委譲する。最終の実行結果・review状態はPR本文に記録する。
