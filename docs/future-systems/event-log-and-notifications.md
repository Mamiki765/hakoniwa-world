# イベントログと通知

## 目的

ゲーム内の重要な出来事を、プレイヤー向けログ、管理監査、Discord等の外部通知で共通利用できる構造化eventとして保存する。外部サービス障害でターン処理を失敗させない。

## 背景

表示用文字列だけを正本にすると、重要度、actor、座標、公開範囲で検索できず、WebとDiscordで再利用しにくい。またターン中の直接Webhook送信は外部障害をゲーム保存へ波及させる。

## 対象イベント例

- 新国家誕生、世界拡張。
- 首都攻撃、首都人口の大幅減少。
- 巨大隕石、怪獣出現、大規模災害。
- dormant_frozen、dormant_contestable、sunken_archivedへの国家state遷移と明示的放棄。
- 研究完了、希少アイテム発見。
- 大規模領土変更、防壁都市による防衛。
- season終了。

全セルの小変化を通知対象にせず、importanceと集約規則を持つ。

## 既存作品との関係

箱庭諸島2＋は表示文字列中心のログ、やまにてぃは多数のLog classからHTML・Vue向け表示断片をJSON保存する。新作では、表示文ではなく構造化turn eventを正本とし、Web、管理画面、Discord向け表現を後から投影する。

## 暫定設計

turn eventをゲーム上の出来事の正本とし、notification outboxを配送要求の正本とする。両者をゲーム状態と同じDB transactionで保存し、channel別workerがcommit後に配送する。

## データモデル例

### turn_events

- event_id、event_type、schema_version。
- importance。
- world_id、turn_number、turn_run_id。
- actor_type、actor_id。
- target_type、target_id。
- related_nation_idsまたは関連表。
- map_space_id、signed axial q、r nullable。UI用odd-q座標は保存しない。
- structured payload。
- occurred_at。
- visibility: public、nation、private、admin。
- deduplication_key。
- causation_id、correlation_id。

message本文は正本にしない。event typeとpayloadからlocale別templateで生成する。過去表示を完全固定する必要がある場合は、template_versionまたはrendered snapshotを補助保存する。

### notification_outbox

- outbox_id、event_id、channel、destination reference。
- status: pending、sending、sent、retrying、dead。
- deduplication_key。
- available_at、attempt_count、last_attempt_at、sent_at。
- last_error_code、sanitized_failure_reason。
- payload snapshotまたはrendering version。

secret tokenや実Webhook URLをevent payloadへ保存しない。

## 処理フロー

```text
ターン処理
→ ゲーム状態更新
→ turn event作成
→ 通知policyがnotification outbox作成
→ 同一DB transactionをcommit
→ 別workerがoutboxを取得
→ channel adapterが送信
→ 成功または再試行状態を保存
```

Discord送信、Mariachang API、メールはturn transaction中に直接呼ばない。outbox作成が失敗した場合はgame stateと一緒にrollbackするが、commit後の配送失敗はturn完了を取り消さない。

## event schema

event typeごとにpayload schemaを版管理する。例としてCapitalDamagedはcapital nation、before population、after population、damage category、coordinate、source visibilityを持つ。画面がPHP class名や保存時のHTMLに依存しないようstable event keyを使う。

payloadへ巨大なcell snapshotや秘密情報を含めない。必要な歴史的表示値は、後で名称変更されても意味が通る最小snapshotとして持つ。

## 可視性

- public: 全プレイヤーと公開通知に利用可。
- nation: 関係国家のmemberだけ。
- private: actor本人または限定権限。
- admin: 運用・不正調査専用。

1 eventに複数国家で異なる詳細を見せる場合、共通event＋audience-specific projectionを作る。公開eventからprivate payloadをfilterするだけの実装は漏えいriskがあるため、serializerはvisibility別schemaを使う。

## 重要度と通知policy

importanceはdebug、info、notable、critical等の順序付き値を候補とする。channel、world、event type、player設定から通知対象を決める。

例:

- critical public eventは世界Discord channelへ。
- nation private eventはplayerがopt-inしたDM等へ。
- admin eventは運用channelへ。
- 同turnの小規模領土変更は1件へ集約。

重要度をgameplay効果の数値だけで自動算出せず、event typeの既定値とcontext ruleを組み合わせる。

## retryと重複排除

workerは短いtimeout、指数backoff＋jitter、最大attemptを持つ。429はRetry-Afterを尊重し、4xx恒久errorと5xx一時errorを区別する。deduplication keyをchannel側にも渡せる場合は利用する。

送信直後にworkerが停止すると、sent保存前の再試行で重複し得る。完全exactly-onceは外部channelが対応しない限り保証せず、at-least-once＋message内event ID＋受信側dedupeを基本とする。

dead状態は管理画面で理由、attempt、eventを確認し、再送または破棄できる。破棄にもactorと理由を記録する。

## 保持と削除

player向けevent、admin audit、outbox配送履歴は保持目的が異なる。eventはworld・turnでpartitionする候補を持ち、古い低重要eventをarchiveできる。admin auditはより長く保持し、player delete requestとの関係を法務・運用要件で決める。

outbox payloadに個人情報を複製しすぎない。sent後一定期間でpayloadを削除し、状態とevent参照だけを残す案を検討する。

## 表示API

cursor paginationを使用し、turn、importance、event type、nation visibilityでfilterする。offset paginationは更新中に重複・欠落しやすい。応答はmessage、structured fields、coordinate link、turn、importance、next cursorを返す。

clientはevent typeから勝手にprivate詳細を推測せず、server projectionを表示する。多言語化ではmessage keyとparameterを返すか、server rendered textを返すかをAPI方針で決める。

## ゲームバランス上の懸念

公開eventが多すぎると奇襲、研究、希少itemの情報を過度に漏らし、少なすぎると共有世界の出来事が伝わらない。大国の小eventが通知欄を占有しない集約、公平な重要度、国家別visibilityを設計する。

## 性能上の懸念

- eventをturn中にbulk insertする。
- world_id、turn_number、visibility、importance、event_idに利用queryに合わせた索引。
- outboxはstatus、available_atでlock skip方式を候補とする。
- 全channel向けrenderingをturn workerで作らない。
- 大量セルeventをchunk・nation単位に集約する。

## セキュリティ上の懸念

- payload schema validationとsize上限。
- ログへaccess token、Webhook URL、cookie、個人情報を入れない。
- Discord mentionはallowlistし、payloadから任意のeveryone mentionを作らない。
- 外部出力はchannel adapterでescapeする。
- admin eventの閲覧・再送を権限分離し、監査する。

## 代替案

- ゲームコードから直接Discord: 単純だが外部障害がturnへ波及し不採用。
- DB polling outbox: 信頼性とtransaction整合に強く暫定推奨。
- message brokerへ直接publish: 高性能だがdual write対策が必要。
- 完全event sourcing: 再現性は高いが初期複雑性が過大。

## 利点

Web、管理、Discordが同じevent identityを共有でき、表示文の変更と履歴の意味を分離できる。outboxにより外部停止時もturnを確定し、後から安全に再送できる。

## 欠点

schema version、visibility projection、保持、重複配送の運用が増える。構造化payloadを過剰に保存すると容量とprivacy riskが上がる。

## 未決定事項

- event schema registryの実装方法。
- player向けmessageをserverとclientのどちらでrenderするか。
- importance段階と通知の既定値。
- 各保持期間、archive、個人情報削除。
- WebSocket等のリアルタイム更新基盤。
- Mariachang停止時の最大保持・再送期間。

## MVP縦切りで必要か

不要。最初のMVP縦切りでは構造化event tableとoutboxを先行実装しない。構造化eventとoutboxの最小核は将来のturn基盤と同時に追加する。DiscordやMariachang連携そのもの、複雑な通知設定、WebSocketはさらに後回しにできる。

## 後回しにできるもの

Discord・メールの実配送、player別通知設定、WebSocket、長期archive、複数localeの完全対応、外部brokerは後回しにできる。
