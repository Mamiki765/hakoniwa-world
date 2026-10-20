# 文書の入口

通常の再開で読むのは現在地と、今回の作業に必要な文書だけです。過去のrelease順に全docsを読む必要はありません。

## 最新コードと過去資料の読み分け

現在の設定は[現行Rulesetの確認方法](../product/docs/architecture/current-ruleset-baseline.md)と[authoring](../product/docs/architecture/ruleset-authoring.md)に従い、対象HEADの[config](../product/config/hakoniwa.php)から確認します。コードの版、PRの統合状態、本番への適用状態は別の証拠です。

- 露店・合成・チケット部分使用・倉庫拡張は[5.5.0](../product/docs/releases/5.5.0-market-synthesis.md)、露店の無期限化は[5.6.0](../product/docs/releases/5.6.0.md)を参照します。5.5.0の7日期限は5.6.0で置き換わります。
- fresh構築とsupported upgradeは[現行install/upgrade境界](../product/docs/architecture/install-upgrade-rebaseline.md)と[5.0.1 rebaselineの承認・検証記録](../product/docs/plans/5.0.1-ruleset-rebaseline.md)を入口にします。旧v27の手順を現行treeへ戻しません。
- 古い構想帳や`current-contracts`の「地上合成は未実装」「露店・圧縮条件は未決」は、上記5.5系の採用範囲について後続決定に置き換わっています。弓・スーツ育成や自由な効果合成まで採用済みと読み替えません。

以下の版別資料と[文書一覧](documentation-inventory.md)には固定時点の記録も含みます。「現在」「未完」の語やcheckboxだけで現在の作業を決めず、後続決定とコードを照合してください。archiveの時点・元path・外部管理庫への移管は文書一覧の案内を使います。

## 地上・地下・地底の基本原則

- 対象地形・所有者の条件を満たす地上の通常施設建設は、村・町・都市を置換できる。新しい地上施設を追加するときは、[共通の置換許可リスト](../product/app/Domain/Command/SettlementOverbuildPolicy.php)へ追加し、[予告・計画登録](../product/app/Application/CommandQueueService.php)と[Turn実行](../product/app/Application/DomesticCommandExecutor.php)で同じ判定を使う。首都や他の施設を置換対象へ広げず、所有者・地形の検証を維持する。同種施設の増設・修理は各施設の既存契約に従う。
- **地下**は首都強化用のNation所有施設。建設・撤去は通常の開発計画で公式Turnを使い、対象は解禁済みの`(layer, slot_index)`。占有slotへの直接上書きはしない。[地下施設の設計](../product/docs/architecture/underground-facility-development.md)と[実装](../product/app/Application/Underground/UndergroundFacilityService.php)を参照する。
- **地底**はFFA/RPGパート。Secretary所有の進行・資産とTurn独立の探索・戦闘を、地下施設のNation所有・公式Turn処理と混同しない。地上の新施設へ地底攻略必須の条件を勝手に追加しない。[現行contract](../product/docs/handoffs/current-contracts.md)を入口にする。プレイヤー向けマニュアルは概要だけなので仕様の根拠にしない。この呼び分けだけを理由にアプリ全体の表示や識別子を一括変更しない。

## 再開と実装

新しいイベントシーンを追加するときは、既読後の回想登録も確認する。既存の解放条件・既読記録・シーン表示を再利用し、回想で戦闘・報酬・進行を再実行しない。

| 目的 | 入口 |
|---|---|
| 4.10.0の採用PR・統合状況・検証・残る判断 | [4.10.0 release](../product/docs/releases/4.10.0.md) |
| 4.11.0 電力・ピザの仕様表・決算・移行・画像の残件 | [4.11.0 power/pizza](../product/docs/releases/4.11.0-power-pizza.md) |
| 4.11.1 資源予測の簡素化・ピザ金銭維持費削除・秘書条件表示 | [4.11.1 power/secretary](../product/docs/releases/4.11.1-power-secretary.md) |
| 4.12.0 油田開発・船舶収益・海軍回避・過去油田XPのdry-run | [4.12.0 oil/fleet](../product/docs/releases/4.12.0-oil-fleet.md) |
| 4.12.1 新しい地上施設の村系置換漏れ修正・自島HUDゲージ | [4.12.1 settlement overbuild / HUD](../product/docs/releases/4.12.1-settlement-overbuild.md) |
| 4.13.0 海域天候抽選・保存・雨・台風/流星群/終末・マップ表示 | [4.13.0 sea-area weather](../product/docs/releases/4.13.0-sea-area-weather.md) |
| 4.13.0 承認済み天候GIFの対応表・外部配置 | [weather asset placement](assets/weather-asset-placement-4.13.0.md) |
| 4.14.0 地底背景・ホーム初回イベント・狂月賛歌・表示修正 | [4.14.0 underground harbor](../product/docs/releases/4.14.0-underground-harbor.md) |
| 4.14.1 地図の上限・ピザ屋の収益表示 | [4.14.1 map details](../product/docs/releases/4.14.1-map-details.md) |
| 4.14.2 帰港地・狂月賛歌の既読回想 | [4.14.2 recollections](../product/docs/releases/4.14.2-recollections.md) |
| 4.14.3 秘書の肩書き表示・実績画面の配置 | [4.14.3 secretary title layout](../product/docs/releases/4.14.3-secretary-title-layout.md) |
| 4.15.0 船の視界・アクセサリー全枠付与・装備Quality | [4.15.0 equipment quality / ship vision](../product/docs/releases/4.15.0-equipment-quality-ship-vision.md) |
| 4.18.3 ログの公開区分・SPPカルマ | [4.18.3 log visibility](../product/docs/releases/4.18.3-log-visibility.md) |
| 5.1.2 Ruleset由来の施設名・交易配送・計画処理の統合 | [5.1.2](../product/docs/releases/5.1.2.md) |
| 5.2.0 遷都間隔・船の目的地・海底工作船 | [5.2.0](../product/docs/releases/5.2.0.md) |
| 5.3.0 エコポ・旧版怪獣の継続・API制限枠 | [5.3.0](../product/docs/releases/5.3.0.md) |
| 5.4.0 queue・怪獣・地下施設の現在版解決と来歴、省エネXP、3 migration | [5.4.0 queue / provenance / energy](../product/docs/releases/5.4.0-queue-provenance-energy.md) |
| 5.5.0 地底露店・地上合成・チケット部分使用・倉庫拡張 | [5.5.0 market / synthesis](../product/docs/releases/5.5.0-market-synthesis.md) |
| 5.6.0 露店の無期限化と統合・検証の記録 | [5.6.0](../product/docs/releases/5.6.0.md) |
| 外部管理庫の現在地・作業引継ぎへの入口 | [current-status](../product/docs/handoffs/current-status.md) |
| 取り違えを防ぐ現行contract | [current-contracts](../product/docs/handoffs/current-contracts.md) |
| IL200超の装備計算・研磨 | [地底装備の倍率とrating](../product/docs/architecture/underground-equipment-scaling.md) |
| バハムル中級2の採用値・測定条件・候補比較 | [中級2バランス調整](../product/docs/plans/bahamul-intermediate-2-balance.md) |
| 採用済みの未完作業と会話の構想 | [Owner判断・構想](../product/docs/plans/post-4.4.0-decisions-and-ideas.md) |
| 地上アイテムの採用済み合成・圧縮と、未採用の育成構想 | [5.5.0実装範囲](../product/docs/releases/5.5.0-market-synthesis.md)、[地上アイテム拡張の構想](../product/docs/plans/surface-item-expansion.md) |
| 地上の配置・電力・上位産業・属性魔力 | [地上発展拡張](../product/docs/plans/surface-development-expansion.md) |
| T01〜T12の元の範囲・途中記録 | [4.4.1予定表](../product/docs/plans/4.4.1-todo.md)、[実装・検証記録](../product/docs/releases/4.4.1-chat-implementation.md)。古いcheckboxを現在の未実装判定に使わない |
| 本番の観測、証拠の限界、運用残件 | [deployment status](../product/docs/operations/current-deployment.md) |
| Receiptの集約・手動purge、戦闘snapshot縮約、限定routine audit整理 | [保持整理runbook](../product/docs/operations/receipt-audit-retention.md)。preview、明示apply、保持条件を分け、本番稼働は別確認 |
| fresh構築・既存DB採用・撤去した互換性 | [現行install/upgrade境界](../product/docs/architecture/install-upgrade-rebaseline.md)、[5.0.1 rebaseline](../product/docs/plans/5.0.1-ruleset-rebaseline.md)。[4.9.1](../product/docs/plans/4.9.1-v27-baseline-consolidation.md)・[4.9.2](../product/docs/plans/4.9.2-baseline-structure-fix.md)は前段の履歴 |
| 実装前の設計gate | [open-questions](open-questions.md)。全Open/Deferredを現在のTODOへ昇格させない |
| 文書整理の進捗と未分類範囲 | [documentation-inventory](documentation-inventory.md) |

## 対象ごとの現行資料

- 実績・肩書き、既命名者の補填、Mariaの既存保存・通知への連携は[実績連携の設計と導入履歴](../product/docs/plans/maria-achievement-link.md)を参照します。World #204はmerge済みです。現在の本番secret・同期cron・実付与/実通知は今回未確認で、merge事実から有効化済みとも未有効とも断定しません。次の20候補は未採用です。

- Rulesetを変更する場合は[authoring](../product/docs/architecture/ruleset-authoring.md)と実際のconfig・validator・consumerを確認します。過去版の調整値を永久不変の契約にしません。
- 地上と地下のlockは[Secretary lock boundaries](../product/docs/architecture/secretary-lock-boundaries.md)、管理操作は[管理運用](../product/docs/operations/4.4.0-admin-operations.md)を入口にします。Git merge、checkout、稼働image、production DBの適用状態は別の証拠です。
- ローカル環境は[local development](operations/local-development.md)と[Compose](operations/docker-compose.md)、データ保護は[backup/restore](../product/docs/operations/database-backup-and-restore.md)を確認します。
- プレイヤー向けの説明は`product/docs/manual/`です。書き方・載せる範囲・版ごとの更新手順は[マニュアルの作り方](../product/docs/manual/README.md)に従います。開発経緯や提案書をゲーム内の説明へ流用しません。
- T09に着手するときだけ[E監査原本](../product/docs/plans/reference/e-test-and-constraint-audit.md)を読みます。これは未処理候補を含む調査原本で、全候補の未修正や採用を宣言するものではありません。9/28の全suite固定値一覧化依頼の完了成果物とも同一視しません。

対象のarchitecture・accepted ADR・operationsは実装済みでも現役文書です。ここに列挙しなかった文書を一律に無効扱いせず、必要な範囲のcodeと設計gateから確認してください。

## 検索とarchive

通常の検索はcode・schema・testsと上記の現行／予定資料へ絞ります。`archive/`配下はOwnerの明示指示なしに閲覧・検索・一括読込せず、旧リンクも自動追跡しません。旧統合handoffやMVP索引を必読へ戻さないでください。

通常文書の分類と過去本文の移動結果は[文書一覧](documentation-inventory.md)にまとめました。将来案や古いarchitectureの記述は、日付やファイル名だけで現行仕様と扱わず、対象のcodeとOwner判断に照らして確認します。
