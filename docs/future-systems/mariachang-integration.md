# Mariachang連携

## 目的

既存Discord BotであるMariachangへ箱庭世界の重要eventを渡し、Discord通知を行う。Mariachangが箱庭DBのmapやnation tableを直接解釈せず、箱庭側の内部契約を通じて連携する。

## 背景

通知候補は新国家誕生、世界拡張、首都攻撃、巨大隕石、怪獣、大規模災害、国家消滅、研究完了、希少item、season終了などである。通知は補助機能であり、Bot停止やDiscord障害でturn処理をrollbackしてはならない。

既存のMariachang、サーバーのDocker構成、Discord設定は本Phaseでは変更・実行・接続していない。本書は将来の境界案だけを示す。

## 既存作品との関係

箱庭諸島2＋に外部通知基盤はなく、やまにてぃはturn失敗時の同期Webhookを持つ。新作は構造化eventとoutboxを正本にし、既存MariachangはDiscord配送adapterとして利用する候補である。

## 代替案

### 1. Mariachangが箱庭DBのoutboxをpoll

利点:

- 箱庭側notification workerを省ける可能性。
- DB上の未送信状態をMariachangが直接処理できる。

欠点:

- DB credentialとschemaを別serviceへ公開する。
- migrationとBot releaseが密結合になる。
- nation、map、visibilityの解釈がMariachangへ漏れる。
- 行lock、再試行、障害時責任が曖昧になる。

原則不採用。

### 2. 箱庭側がDiscord Webhookへ直接送信

利点:

- 経路が短く、Mariachangの変更が不要。
- Discord向けだけなら構成が単純。

欠点:

- mention、channel設定、rate limit、formattingが箱庭へ重複する。
- Mariachangの通知統合・管理機能を利用できない。
- Bot経由でないと実現できない機能に拡張しにくい。

小規模なfallback候補だが、既存Bot統合という目的には弱い。

### 3. 箱庭notification workerがMariachang内部APIを呼ぶ

利点:

- 箱庭DBを公開しない。
- event契約、認証、timeout、rate limitの境界が明確。
- MariachangがDiscord channel、mention、formattingを担当できる。
- outboxにより互いの停止を分離できる。

欠点:

- 内部APIの実装・versioning・service認証が必要。
- Mariachangと箱庭の双方で契約testが必要。

暫定推奨。

### 4. message brokerを共有

利点:

- 疎結合で複数consumerへ拡張しやすい。
- 大量eventとback pressureに対応しやすい。

欠点:

- 新しい運用service、権限、監視が増える。
- DB transactionとのdual write対策が必要。
- 初期規模には過剰な可能性。

将来、consumerが増えた場合の候補。

## 利点

内部API案では箱庭DBとMariachangのschemaを分離し、Discord固有のchannel・mention・rate limit責務をBot側へ集約できる。outboxにより両serviceの停止を切り離せる。

## 欠点

APIのversion、service認証、二段階の配送status、双方の監視が必要になる。Mariachangを変更する別作業と所有者間の契約合意が必要である。

## 暫定設計

箱庭notification workerが、公開可能なevent projectionだけをMariachang内部APIへ渡す。Mariachangは箱庭のtableを読まず、受理後のDiscord配送とrate limitを所有する。

## 処理フロー

```text
箱庭DB notification_outbox
→ hakoniwa notification worker
→ Docker内部のMariachang API
→ MariachangのDiscord adapter
→ Discord
```

箱庭turn workerはoutboxまでをtransaction内で保存して終了する。notification workerがAPIを呼び、Mariachangがacceptした時点とDiscordへ送信した時点を区別できる契約にする。

APIが非同期acceptだけを返す場合、Mariachang側message IDを応答し、箱庭outboxへ保存する。最終配送statusをcallbackまたはstatus APIで同期するかは必要性を確認する。

## データモデル例

箱庭側はnotification_outboxにevent_id、channel intent、destination key、dedupe key、status、attempt、available_at、Mariachang message idを持つ。Mariachang側の内部table構造は契約外とし、HTTP request・response schemaだけを共有する。

## 内部API契約案

送信requestに必要な概念:

- contract version。
- event idとdeduplication key。
- event type、importance、occurred_at。
- world display key、turn number。
- publicに許可されたstructured fields。
- suggested channel key。
- allowed mention targets。
- localeまたはmessage key。
- expiry time。

Mariachangへmap cell、nation record、認証userを直接渡さない。必要な表示名、座標、公開URLは箱庭側のnotification projectionで明示する。Mariachangは未知event typeでもgeneric messageとして安全に扱うか、明示的unsupported応答を返す。

## 認証とnetwork

- Docker内部networkだけで到達可能なservice endpointを候補とする。
- 短命なservice tokenまたは署名付きrequestを使う。
- tokenはsecret managerまたはDocker secret候補で、composeやGitへ平文保存しない。
- TLS終端が内部でない場合も、共有host上の脅威modelを確認する。
- request timestamp、nonceまたはidempotency keyでreplayを抑止する。
- Mariachangは箱庭からのsource identityと許可event scopeを検証する。

単一の永続tokenを採る場合でもrotation、漏えい時失効、環境別分離を用意する。

## retry、timeout、rate limit

箱庭workerは接続・応答timeoutを短くし、指数backoff＋jitterで再試行する。429ではRetry-Afterを尊重し、401・403は自動無限再試行せず運用alert、5xx・timeoutは一時障害として扱う。

同一outboxを複数workerが処理しないようclaim lockを用いる。送信成功応答が失われる可能性があるため、event idまたはdedupe keyをMariachang側でも一定期間保持する。

channelごとにDiscord rate limitが異なるため、importance別queueまたはpriorityを検討する。低重要eventをまとめ、高重要eventを先に処理しても、同じturn内の意味順序を壊さない。

## channel振り分け

箱庭側はpublic、nation、admin等のnotification intentを決め、Mariachangは実Discord channel IDとのmappingを管理する案を推奨する。これにより箱庭DBへDiscord固有IDを増やさない。

- world public channel。
- 運用admin channel。
- playerまたはnation別通知。初期対応の要否は別決定。
- critical fallback channel。

mentionはuser入力文字列をそのまま使わず、許可されたDiscord identity mappingだけを使う。everyone、here、role mentionは明示allowlistする。

## 停止時の挙動

### Mariachang停止

turnは通常完了し、outboxはpendingまたはretryingで残る。一定時間を超えた低重要eventは集約、期限切れ、または管理者破棄を許す。critical eventはdead-letter後も手動再送可能にする。

### 箱庭停止

新規eventは作られない。再開後にSchedulerがturnのcatch-up policyを適用し、commit済みoutboxだけを送る。Mariachangは箱庭DBへ問い合わせず、既受理messageを独立に配送する。

### Discord停止

Mariachangが再試行するのか、箱庭がMariachangへの再送を担うのか責任を分ける。推奨は、Mariachangがaccept後のDiscord配送を所有し、箱庭は同じeventを再送しない方式である。

## 管理画面

箱庭管理画面ではoutbox status、attempt、last error、Mariachang message ID、再送・破棄を表示する。Discord credentialや内部tokenは表示しない。player設定では通知種別、重要度、公開channelまたはDMのopt-inを提供する候補がある。

## ゲームバランス上の懸念

公開通知から攻撃者、隠し研究、希少item位置を漏らすとゲーム上の情報非対称性が崩れる。大国のeventが通知を占有しない集約と、player別opt-inを設ける。

## 性能上の懸念

通知はbulk可能な低重要eventをturn summaryへ集約する。1セル1messageを作らない。worker concurrencyはMariachangとDiscordのrate limitに合わせる。API payloadにmap snapshotを含めず、公開URLと最小fieldsに留める。

## セキュリティ上の懸念

- private game eventをpublic channelへ送る前に箱庭側projectionで除外。
- internal API logにtoken、private payloadを残さない。
- URLはopen redirectや署名漏えいを起こさない公開routeに限定。
- playerとDiscord accountの紐付けは明示同意と解除手順を持つ。
- 削除request後のDiscord messageは完全回収できないことを利用規約で扱う。

## 未決定事項

- Mariachangに内部APIを追加できるかと、その所有チーム。
- acceptと最終配送を同期する必要があるか。
- service認証方式、token rotation、内部TLS。
- player別DM、mention、channel mappingの保管場所。
- retry期間、dead-letter、古いeventの集約。
- Mariachang APIが停止中の最大outbox容量。

## MVP縦切りで必要か

不要。Mariachang連携、structured event、notification outboxはいずれも最初のMVP縦切りへ実装しない。箱庭側のstructured eventとoutboxは将来のturn基盤で追加する。連携開始前に、Mariachangを変更する別承認と契約調整が必要である。

## 後回しにできるもの

player別DM、Discord account連携、配送status callback、message broker、複数Bot、管理画面からの高度な再送・channel編集は後回しにできる。
