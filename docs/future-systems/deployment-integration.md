# 将来のDocker・配備統合

## 目的

既存サーバー上のMariachang、Terraria、Nextcloud、Nginx Proxy Manager、board-web等と責務・dataを混在させず、将来hakoniwaを追加する構成を整理する。本Phaseでは既存docker-compose、service、network、volumeを変更しない。

## 背景

同一serverには用途・DB・公開方法が異なる複数serviceがある。既存の巨大composeへ無計画に追加すると、secret、network、volume、再起動の影響範囲が広がる。箱庭はturn workerとschedulerをWebから分離する必要もある。

## 既存作品との関係

箱庭諸島2＋はCGIの手作業設置、やまにてぃは開発用5 serviceとCloud Run例を持つが、scheduler・queue・backupが不十分である。新作は既存composeをコピーせず、必要なservice責務だけを独立構成へ反映する。

## 暫定設計

hakoniwa専用compose projectを候補とし、web、worker、scheduler、PostgreSQLを分離する。Nginx Proxy ManagerとMariachangに必要なnetworkだけをexternal networkとして共有し、DBとvolumeは専用化する。

## 想定service

| service | 責務 | 永続data | 外部公開 |
|---|---|---|---|
| hakoniwa-web | Laravel API、認証、静的entry point | 原則なし | Nginx Proxy Manager経由 |
| hakoniwa-worker | turn job、通常queue、outbox処理 | 原則なし | 非公開 |
| hakoniwa-scheduler | turn期限判定とjob投入 | 原則なし | 非公開 |
| hakoniwa-postgres | ゲーム正本DB | 専用volume | 非公開 |

通知処理とturn処理を同じworker imageで別processとして動かすか、hakoniwa-notifierを分けるかは負荷・障害分離の計測後に決める。imageは共通でもprocess責務は分ける。

## データモデル例

配備自体のゲームtableは作らない。運用上はdeployment version、migration version、worker code version、ruleset versionをturn_runと監視metadataで関連付ける。secret値はDBへ複製せず、識別名とrotation日時だけを監査対象にする。

## 既存serviceとの分離

- Nextcloud用MariaDBを共用しない。
- MariachangのDBを直接読書きしない。
- board-webや他serviceのnetwork alias、volume、secretを再利用しない。
- hakoniwa-postgresは専用database user、volume、backup policyを持つ。
- 共通のreverse proxy networkと、hakoniwa内部networkを分離する。

正本DBはPostgreSQLに確定した。行lock、constraint、JSONB、索引を利用し、既存MariaDBとは同居・共用しない。未決定なのは運用者のbackup手順、memory、storage、更新手順、RPO・RTOであり、本番公開前に確定する。

## network案

```text
Internet
→ Nginx Proxy Manager
→ hakoniwa-web

hakoniwa-web / worker / scheduler
→ hakoniwa-postgres

hakoniwa notification worker
→ internal integration network
→ Mariachang internal API
→ Discord
```

postgres networkへMariachangやproxyを参加させない。webだけをproxy external networkへ接続する。Mariachang連携用networkはAPI portだけを公開し、DB networkと分ける。

## service責務

### hakoniwa-web

- statelessなHTTP requestを処理する。
- turnをrequest内で直接実行しない。
- health endpointはprocess生存と必要最小依存を分ける。
- uploadをcontainer filesystemへ恒久保存しない。

### hakoniwa-worker

- queueからjobをclaimする。
- world単位のturn排他と冪等性を守る。
- graceful shutdownで新job取得を止め、実行中jobの期限を管理する。
- turn、通知、低優先maintenanceにqueueまたはconcurrency上限を分ける。

### hakoniwa-scheduler

- 原則1つだけactiveにするが、複数起動でもDB一意制約で重複確定しない。
- next_turn_atを確認してjobを投入する。
- turn実行自体やDiscord送信を行わない。

### hakoniwa-postgres

- 外部host portを原則公開しない。
- 専用userと最小権限。
- backup、WAL、vacuum、監視、schema migrationを運用計画に含める。

## volume

永続volume候補:

- PostgreSQL data。
- backup staging。ただし同一diskだけをbackup先にしない。
- player uploadをlocal保存する場合の専用volume。

application source、vendor、node_modulesを本番の可変共有volumeにしない。build artifactをimageへ含め、同じimageをweb・worker・schedulerで使用する候補とする。

## 静的画像

新作独自または明確に再配布可能なrelease画像はfrontend build artifactへ含め、content hash付きURLと長期cacheを使う。

箱庭諸島2＋の原GIFはrelease imageへ含めない。Git外のホストディレクトリを候補`/srv/hakoniwa-assets/original`へread-only bind mountし、`HAKONIWA_ORIGINAL_ASSET_PATH`と`HAKONIWA_ORIGINAL_ASSET_BASE_URL`で解決する。definitionは`asset_key`のみを持ち、manifestが確認済みbasenameへ解決する。原名・原形式を維持し、base64 DB保存、build時copy、再encode、sprite化をしない。mount欠落時はreadiness全体を落とさずasset healthを警告し、UIをCSS・短縮名fallbackへ切り替える。詳細は`docs/assets/tile-asset-mapping.md`を正本とする。

この個別方針は箱庭諸島2＋の確認済み原GIFだけに適用し、やまにてぃ等の出典不明な第三者reference画像を配置する根拠にはしない。

将来playerがuploadする国家画像等は、release imageと分離する。object storageまたは専用volumeを候補とし、拡張子ではなく実content type、size、pixel dimensions、再encode、malware対策、公開範囲を検証する。元filenameをpathに使わない。

## healthcheckと依存

containerの起動順だけで準備完了を保証しない。webはDB一時障害に適切な503、workerはqueue・DB再接続、schedulerは次回loopで回復できるようにする。

healthはlivenessとreadinessを分ける。

- liveness: processがdeadlockせず応答できる。
- readiness web: request処理に必要なschemaとDBへ到達できる。
- readiness worker: 新jobを安全に受けられる。
- postgres: server readyに加えbackup freshnessは別監視。

MariachangやDiscord停止をhakoniwa-web・turn workerのreadiness失敗条件にしない。

## secrets

- APP key、DB password、OAuth secret、Mariachang token、Discord関連secretをGitとcompose本文へ書かない。
- environmentごとに分離し、rotation手順を作る。
- web、worker、schedulerへ必要なsecretだけを渡す。
- build時secretをimage layerへ残さない。
- log、health、exception画面で値をmaskする。

開発用既定secretを本番で許可せず、起動時validationで拒否する。

## queueとscheduler

初期候補はDB-backed queueでservice数を抑えるか、既存運用に安全に追加できるRedis等を使うかである。turn jobは長時間・高重要、notificationは外部rate limit、maintenanceは低優先のため、少なくともlogical queueとconcurrencyを分ける。

Schedulerが停止した場合のcatch-up policyは、全未実行turnを連続実行、最新1回だけ実行、管理者判断のいずれかをworld rulesetで決める。無制限catch-upでserverを圧迫しない。

## migration

本番deployでは後方互換なexpand-and-contractを基本とする。

1. 新旧applicationが共存できるschemaを先に追加。
2. 必要ならonline backfillを小batchで実行。
3. 新codeへ切替。
4. 観測期間後に旧列・旧pathを削除。

長時間table lock、起動時自動migration、複数replicaからの同時migrationを避ける。migration runnerは別の一回jobとし、backup・rollback判断を持つ。

## 処理フロー

1. imageをbuild・検証し、immutable digestを確定する。
2. DB backup freshnessと互換migrationを確認する。
3. 一回migration jobを実行する。
4. webを新imageへ更新し、readiness後にtrafficを切り替える。
5. workerをgracefulに入れ替え、scheduler singletonを確認する。
6. turn、queue、outbox、DB指標を監視する。
7. 問題時は互換範囲内でapplicationをrollbackする。

## zero-downtime deployment

- imageをimmutable tagまたはdigestで管理。
- webはreadiness成功後にtrafficを切替。
- workerはgraceful stopし、job leaseと冪等性で回復。
- turn実行中はrulesetとcode versionをrunへ記録。
- deployとturn締切が衝突しないmaintenance policy。
- rollback時も新schemaを旧codeが読める期間を保つ。

真のzero downtimeが必要か、短いmaintenance windowで十分かは利用規模と運用負担で決める。

## backupと復旧

- PostgreSQLの定期full backupと継続ログを候補。
- backupを別hostまたはobject storageへ暗号化保存。
- RPO、RTO、保持期間を決める。
- restore testを定期実行し、backup成功logだけで安心しない。
- turn_run、ruleset、event、item、所有権が同じ時点へ戻ることを確認。
- player uploadはDB参照と整合する世代でbackup。

同一volume内のファイルcopyは災害・disk障害に弱く、唯一のbackupにしない。

## 観測と運用

- web latency、error rate、DB connection。
- queue depth、oldest job age、failed jobs。
- turn duration、lock wait、phase duration、changed cells。
- scheduler last heartbeat、next turn lateness。
- outbox pending/dead、Mariachang latency。
- DB storage、WAL、backup freshness、restore test。

alertは外部通知基盤自身が停止した場合に備え、Discordだけへ依存しない経路を検討する。

## ゲームバランス上の懸念

配備方式がrulesetや乱数結果を変えてはならない。worker台数やjob並列度によって国家処理順・国境結果が変わらない決定性を保つ。停止後のcatch-up turn数は資源生産へ影響するため運用都合だけで決めない。一方、国家休眠はUTCのlast_active_atで判定し、catch-up turn数によって30日、180日、365日の境界をずらさない。

## 性能上の懸念

同一server上でTerraria、Nextcloud等とCPU、memory、disk I/Oを競合する。turn中のDB負荷、backup、image build、upload処理の時間帯を分け、container limitとcapacity alertを設ける。DB connection数をservice別に予算化する。

## セキュリティ上の懸念

- proxyからwebへのtrusted proxy設定を限定。
- internal portをpublic bindしない。
- containerを非root、read-only filesystem候補、capability最小化。
- imageとdependencyをscanし、更新方針を持つ。
- uploadをapplication codeやpublic executable pathへ置かない。
- DB・backup・内部APIへの最小権限とnetwork分離。

## 代替案

- 既存巨大composeへ全service追加: 一括管理しやすいがblast radiusと変更競合が増える。
- hakoniwa専用compose＋external networks: 責務を分けつつproxy・Mariachangと接続可能で暫定候補。
- Kubernetes等: 拡張性は高いが現在の単一serverには過剰な可能性。
- web内scheduler・queue: service数は減るが障害分離と長時間jobに弱く不採用候補。

## 利点

専用projectとnetwork分離により既存serviceへのblast radiusを抑え、Web、turn、通知を個別に再起動・監視できる。専用PostgreSQLはgame schema、backup、権限を独立管理できる。

## 欠点

service数、image lifecycle、監視、backup対象が増え、単一serverの資源制約は残る。external networkとreverse proxyの調整には既存運用者の明示承認が必要である。

## 未決定事項

- 実serverのCPU、memory、storage、backup先、OS。
- PostgreSQL versionとDB-backed queueか別queueか。
- compose projectの分割とexternal network命名。
- Nginx Proxy Managerのdomain、TLS、upload制限。
- maintenance window、turn中deploy、catch-up policy。
- object storageの有無とplayer upload要件。
- Mariachang内部APIの配備・認証。
- RPO、RTO、保持期間、監視先。

## MVP縦切りで必要か

最初のMVP縦切りではWebとPostgreSQLの接続、secret分離だけが必要であり、turn worker、scheduler、notification worker、queue製品を先行実装・配備しない。将来processを分離できるApplication境界だけを維持する。本番zero-downtime、Mariachang連携、player uploadも後回しにする。既存composeへ変更を加える前に、専用ADRと運用者の明示承認が必要である。

## 後回しにできるもの

zero-downtimeの完全自動化、object storage、player upload、Mariachang内部network、専用broker、水平scale、複数host化は後回しにできる。turn導入時にworker、scheduler、復旧可能性、一意実行をまとめて決める。
