# 既存サーバーとの将来統合方針

## 文書の目的

本番候補サーバーには複数の既存serviceが稼働している。箱庭を将来追加するときの分離原則だけを公開可能な形で記録し、実際のCompose、credential、host path、既存serviceの内部設定は本repositoryへ収録しない。

本書は将来の本番統合前提を示す。repository単体のMVP Composeは実装済みだが、既存本番Composeの変更は承認しない。

## 既存環境との境界

- hostのHTTP/HTTPS入口はNginx Proxy Managerが管理し、ports 80と443を使用している。
- 箱庭Webは80・443を直接hostへbindせず、Docker内部portだけをexposeし、将来Nginx Proxy Managerからroutingする。
- 既存serverにはBot、game server、file sharing、Web application、静的asset配信等の複数serviceがあり、箱庭のnetwork、volume、secret、再起動範囲を不用意に共有しない。
- 既存の本番Docker Compose全体を本repositoryへcopyしない。

## MVPで追加する予定のservice

| service | 予定責務 | 分離方針 |
|---|---|---|
| `hakoniwa-web` | Laravel API、OAuth、Vue配信 | Nginx Proxy Manager経由で公開し、DBを外部公開しない |
| `hakoniwa-postgres` | 箱庭の正本Database | 箱庭専用user、volume、backup方針を持つ |

箱庭は専用PostgreSQLを使用する。既存service用のMariaDBまたはPostgreSQL database、user、volumeを流用・共用しない。

## Turn・通知導入後のservice

turnと通知を実装した後に、同じapplication imageから別processとして次を起動する予定である。

- `hakoniwa-worker`: turn job、通常queue、将来のoutbox処理。
- `hakoniwa-scheduler`: 期限判定とjob投入。

Web request内でturnを直接実行しない。Mariachang等への通知連携はgame state確定後のoutbox・adapterへ分離し、外部通知の障害でturn処理やWeb readinessを停止させない。

## 原作GIF

原作GIFはGitHub repositoryやapplication imageへ収録しない。Git外のhost directoryへ原名・原形式で配置し、containerへ読み取り専用mountする。定義はhost pathではなく`asset_key`を参照し、mountがない場合はUIの代替表示を使用する。

詳細は`docs/assets/tile-asset-mapping.md`と`THIRD_PARTY_NOTICES.md`を正本とする。

## 統合時期

repository rootにはローカル検証用`compose.yml`を置き、`127.0.0.1:8080`だけへbindする。実際の本番Compose統合、reverse proxy設定、network名、host volume、secret、継続backup、monitoringは別の設計判断と運用者承認を経て行う。既存OCI Composeは今回変更していない。
