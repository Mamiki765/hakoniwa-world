# 汎用資源システム

## 目的

資金、食料、魔力、工業力、研究力、鉱石、宇宙資源、地下資源を後から追加できるよう、資源の定義と国家の保有量を分離する。初期実装で全種類を有効にすることは目的ではない。

## 背景

箱庭諸島2＋は資金・食料などを固定構造と上限値で扱い、やまにてぃもStatusの列として主要資産を保持する。この形は単純で検索しやすいが、資源を増やすたびに国家table、計算、API、UIを一斉変更する。

新作は追加可能性を残しつつ、すべてを巨大JSONや任意精度数へ逃がさない。保有量はtransaction、比較、集計、lockが多いため通常tableを優先する。

## 既存作品との関係

- nationsは国家identityと状態を持ち、資源列を増やし続けない。
- rulesetは利用可能なresource definitionと生産・消費規則を版管理する。
- commandsは実行時にresource ledgerへ予約・消費を記録する。
- modifiersは生産量や消費量を変更するが、残高を直接正本化しない。
- turn eventは残高変化の理由を記録する。

## 暫定設計

### resource definitions

安定したresource key、表示名message key、単位、表示精度、残高下限・上限方針、transfer可否、負残高可否、公開範囲、metadata schema versionを持つ。

資源固有の表示・分類など可変属性はJSONB候補である。一方、key、active、unit、precision、transferableのような検索・制約対象は通常列を推奨する。

### nation resource balances

nation_id、resource_definition_id、amount、reserved_amount、updated_turnを持つ正規化tableを候補とする。nationとresourceの組合せを一意にし、残高更新時は行lockまたは条件付きupdateを行う。

available amountはamount minus reserved_amountとして扱う。予約は命令受理時に必要か、ターン実行時だけ差し引くかを命令種別ごとに決め、二重消費を防ぐ。

### resource transactions

監査が必要な場合、turn_run、nation、resource、delta、balance_after、reason_type、reason_id、command_id、event_id、deduplication_keyを持つledgerを追加する。残高はledger全走査で毎回求めず、balance行を正本または検証可能なsnapshotとして維持する。

## 代替案

| 案 | 利点 | 欠点 |
|---|---|---|
| 国家tableに固定列 | 単純、高速、型が明確 | 資源追加ごとにmigrationと多層変更 |
| balances正規化table | 追加しやすく検索・lock可能 | joinと行数が増える |
| 国家ごとのJSONB | schema変更が少ない | 条件検索、同時更新、制約、監査が難しい |
| 完全ledgerのみ | 監査性が高い | 残高計算・訂正・大量履歴が重い |

暫定推奨は、definition＋normal balance＋append-oriented ledgerの組合せである。初期資源が資金と食料だけでも同じモデルを使い、未使用の巨大ライブラリは導入しない。

## 利点

資源追加時にnationsへ固定列を増やさず、残高を検索・lock・監査できる。定義と保有量を分けるため、同じresource keyをruleset版ごとに表示・cap・用途だけ変更できる。

## 欠点

固定列よりjoinと行数が増え、存在しないbalanceを0とみなす規約、bulk取得、ledger保持が必要になる。過度に汎用化すると、資源固有ルールの型安全性が失われる。

## データモデル例

概念例でありmigration定義ではない。

- resource_definitions: id、stable_key、ruleset_id、unit、display_scale、minimum_policy、maximum_policy、metadata。
- nation_resource_balances: nation_id、resource_id、amount、reserved_amount、updated_turn、lock_version。
- resource_transactions: id、turn_run_id、nation_id、resource_id、delta、balance_after、reason_type、reason_id、dedupe_key、occurred_at。
- resource_sources: facilityやcellが何をどの条件で生産するかをcatalog側で参照。

## 処理フロー

1. turn開始時にruleset版と有効resource definitionsを固定する。
2. production phaseが施設・人口・研究から基礎生産を集計する。
3. modifier pipelineで加算、倍率、上下限を適用する。
4. upkeep、食料消費、command費用を同じ単位で差し引く。
5. 不足規則を適用し、失敗命令、停止施設、飢餓などのeventを作る。
6. balanceとledgerをゲーム状態と同じtransactionで保存する。
7. 表示用summaryを更新する。

## 上限を分離する

- 表示上限: UIで省略表記する閾値。値そのものを切り捨てない。
- ゲームバランス上限: 倉庫容量などruleset上の意味あるcap。
- DB保存上限: 選択した数値型の技術限界。

旧実装の255や65535をゲーム仕様へ持ち込まない。初期候補はsigned BIGINTまたは精度を固定したdecimalである。予想最大増加量、world寿命、倍率上限を計算し、安全余裕を確認する。BIGINTを超える数値が本当にgameplay上必要になった時点でdecimalまたは別表現を検討し、初期から巨大数libraryを導入しない。

## ゲームバランス上の懸念

- 資金・食料は流入、sink、備蓄価値を明示する。
- 魔力・研究力を単なる別色の資金にせず、生成条件と用途を差別化する。
- 地下・宇宙資源はlayerへの投資と輸送riskを持たせる候補とする。
- 倉庫capを超えた分の廃棄、価格低下、他国移転のどれを採るか決める。
- 新規国家と古参国家の複利差をseason、逓減、維持費で評価する。

インフレは総発行量、中央値、上位集中度、turnごとのsourceとsinkを計測する。表示だけ丸め、計算は一貫した固定精度で行う。

## 性能上の懸念

全国家×全resourceの空行を作らず、保有または有効化した組合せだけを保持する案が有力である。turn中はnationごとの必要balanceをまとめて取得し、resourceごとのN+1 queryを避ける。ledgerはturnやworldでpartition・archiveする余地を残す。

## セキュリティ上の懸念

- resource keyやdeltaをclientの任意値として信用しない。
- 管理者調整もreason、actor、before/afterを監査する。
- 残高不足確認と消費を同じtransactionで行う。
- webhookやMariachangに非公開残高を送らない。
- JSON metadataを任意式やclass名として評価しない。

## 未決定事項

- amountの整数最小単位とdecimalの必要性。
- command登録時の資源予約範囲。
- 国家間取引、奪取、負債を初期範囲に含めるか。
- balanceとledgerのどちらを会計上の最終正本とするか。
- 資源ごとの可視性と諜報ルール。
- 地下・宇宙間の輸送を資源移動としてどう表すか。

## MVP縦切りで必要か

生産、消費、資金・食料balance、追加resourceは最初のMVP縦切りへ実装しない。terrain・facility等のcatalogと同様にresource typeをstable keyで後から追加でき、資源種ごとの固定columnだけへ閉じない境界を維持する。

## 後回しにできるもの

国家間市場、負債、巨大数、宇宙・地下輸送、資源ごとの複雑な可視性、完全ledger再構築は後から追加できる。resource実装時は残高更新の原子性と理由eventを優先する。
