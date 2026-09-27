# hakoniwa-world

**箱庭諸島２S＋**。PHP/LaravelとVueで実装する、全プレイヤーが一つの地上世界を共有する箱庭ゲームです。アプリケーション本体は`product/`にあります。

地上の開発計画・ターン更新・経済・災害・怪獣・ミサイル・島のライフサイクルと、秘書の育成・装備・パーティー探索などの地底ゲームを扱います。MVP時点の「生産・消費や地下は未実装」という説明は現在地ではありません。

## 開発を再開する

[現在地](product/docs/handoffs/current-status.md) → [文書の入口](docs/README.md) → 対象のcode・現行設計へ進んでください。[作業予定](product/docs/plans/4.4.1-todo.md)では、実装済み、確認待ち、未着手、Owner判断待ちを分けています。

Agentの作業規則は[AGENTS.md](AGENTS.md)、設計上の未決gateは[open-questions.md](docs/open-questions.md)です。過去の会話・実装記録を通常の再開資料へ戻さず、archiveの閲覧はOwnerの明示指示に限定します。

## 起動と運用

ローカル起動・APP_KEY・OAuthは[local-development.md](docs/operations/local-development.md)、Composeは[docker-compose.md](docs/operations/docker-compose.md)、backup/restoreは[database-backup-and-restore.md](product/docs/operations/database-backup-and-restore.md)を参照してください。採用依存versionは`product/Dockerfile`・lock file・Composeを正本とします。

ゲームデータは箱庭専用PostgreSQLで管理し、他サービスのDBと共有しません。OAuth secret・DB password・APP_KEYはGit外の環境設定へ置きます。原作画像などの外部assetと出典・利用条件は[THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md)と対象assetの運用文書を参照してください。

作業branchの更新と本番への反映は別です。main更新・merge・deploy・本番DB操作を、開発再開の指示だけで実行しません。
