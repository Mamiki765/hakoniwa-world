# GitHubからForgejoへの片方向同期

## 目的とauthority

GitHubを変更の入口、ForgejoをOCI内のclone・deploy元として維持する。
`.github/workflows/mirror-forgejo.yml`は、GitHubで更新された`main`、`release/*`の親branch、tagを同名のForgejo refへ通常pushする。

`release/4.4.0`のように`release/`の下が一段のbranchだけを対象にする。作業用の小branchには`codex/4.4.0-...`、`fix/...`などを使い、`release/`をprefixにしない。`release/4.4.0/subtask`も同期対象外。

このworkflowは次を行わない。

- ForgejoからGitHubへの逆同期
- `codex/*`などの作業branch、Forgejo上のPR、notesの同期
- force push、履歴の書き換え、自動merge、削除の伝播
- deploy、container再起動、production DB操作

## GitHub repository secret

各GitHub repositoryの`Settings` → `Secrets and variables` → `Actions` → `Repository secrets`に設定する。

| Secret | 内容 | 必要権限 |
|---|---|---|
| `FORGEJO_PUSH_URL` | 対応するForgejo repositoryのHTTPS push URL。例: `https://<user>:<token>@git.pbwlove.com/Mamiki765/<repository>.git` | 対象repositoryの内容をpushできる最小権限。admin、organization、別repositoryの権限は不要 |

Forgejo tokenはrepositoryごとに分離し、有効期限を設定して定期的にrotateする。値をworkflow、log、文書、Git設定へcommitしない。既存の接続先検証は`hakoniwa-world.git`だけを許可し、今回secretの値は変更しない。

## 動作

- GitHub `main`または`release/*`へのpush時、同名のForgejo branchがGitHub側commitのancestorであることを確認して通常pushする。push後にbranch SHAも照合する。
- Forgejoに同名のrelease branchがなければ、新規作成する。`ls-remote`の「refなし」と認証／通信失敗を区別し、後者は停止する。既存`main`が消えていた場合は勝手に再作成しない。
- Forgejo側が先行または分岐していれば失敗し、mergeやforce pushはしない。確認後の競合も通常pushの拒否に任せる。
- GitHub tag作成時はそのtagだけを通常pushする。Forgejoに異なる同名tagがある場合はpushが拒否される。途中の保管点には`checkpoint-...`等を使い、完成前の`v4.4.0`と混同しない。
- 同じrefの実行は直列化し、進行中の同期をcancelしない。branch/tag削除イベントでは実行せず、Forgejoの保存点を消さない。
- GitHub Actions、Forgejo、network、secretが利用不能なら同期は失敗する。履歴を無理に一致させない。

同期loopは作らない。これはGitのコードと履歴の複製であり、PR会話・未pushの作業・本番DB・Git外assetのバックアップではない。特に`codex/*`上だけにある実装・企画branchは親releaseへ取り込むまで自動同期されない。

## 導入と確認

1. このworkflow変更を含むPRをreviewし、Owner承認後に対象の`release/4.4.0`へmergeする。`codex/*`にcommitしただけではrelease同期はまだ有効にならない。
2. mergeで更新されたrelease branchのworkflowが成功し、ログの`Verified release/4.4.0 at ...`がmerge後のSHAと一致することを確認する。Forgejo側のbranchも照合する。
3. 次のrelease branchにも、このworkflowを含む基点を使う。mainへの取り込みは4.4.0全体のrelease時でよく、この変更のためにゲームコードを先行deployしない。
4. tag経路は実際のcheckpointまたはrelease tagで両remoteを照合する。workflow_dispatchも対象refの選択が必要で、作業branchは拒否される。

古いworkflowのままのbranchが存在する間は、そのbranchの同期対象は旧仕様のまま。必要な履歴は通常のPR統合で取り込み、force pushで揃えない。

GitHubの凍結等が起きた後はそのGitHub Actionsにも依存できないので、障害後に未同期分まで届く保証はない。最後に成功確認したForgejo refを復旧起点にする。

## 停止

workflowをdisableするか`FORGEJO_PUSH_URL`を削除すれば、以後の同期は停止する。停止やworkflow削除は、すでにForgejoへpushされたcommitやtagを削除しない。
