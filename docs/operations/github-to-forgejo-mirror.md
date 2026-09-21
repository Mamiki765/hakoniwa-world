# GitHubからForgejoへの片方向同期

## 目的とauthority

GitHubを変更の入口、ForgejoをOCI内のclone・deploy元として維持する。
`.github/workflows/mirror-forgejo.yml`は、GitHubで更新された`main`とtagだけを同じrepositoryのForgejoへpushする。

このworkflowは次を行わない。

- ForgejoからGitHubへの逆同期
- `main`以外のbranch、Forgejo上のPR branch、notesの同期
- force push、履歴の書き換え、自動merge
- deploy、container再起動、production DB操作

## GitHub repository secret

各GitHub repositoryで、`Settings` → `Secrets and variables` → `Actions` → `Repository secrets`に次を設定する。

| Secret | 内容 | 必要権限 |
|---|---|---|
| `FORGEJO_PUSH_URL` | 対応するForgejo repositoryのHTTPS push URL。例: `https://<user>:<token>@git.pbwlove.com/Mamiki765/<repository>.git` | 対象repositoryの内容をpushできる最小権限。admin、organization、別repositoryの権限は不要 |

Forgejo tokenはrepositoryごとに分離し、有効期限を設定して定期的にrotateする。値をworkflow、log、文書、Git設定へcommitしない。

## 動作

- GitHub `main`へのpush時、Forgejo `main`がGitHub側commitのancestorであることを確認してから通常pushする。
- Forgejo `main`が先行または分岐している場合は失敗し、mergeやforce pushはしない。
- GitHub tag作成時はそのtagだけを通常pushする。Forgejoに異なる同名tagがある場合はpushが拒否される。
- 同じrefの実行は直列化し、進行中の同期をcancelしない。
- GitHub Actions、Forgejo、network、secretのいずれかが利用不能なら同期は失敗するが、既存のForgejo repositoryは変更されない。

GitHub側だけにworkflowが存在し、Forgejo側からGitHubへpushするautomationを作らないため、同期loopは発生しない。

## 導入と確認

1. GitHub repositoryへ`FORGEJO_PUSH_URL`を登録する。
2. workflowを含むPRをreviewし、Owner承認後にmergeする。
3. GitHub Actionsの`Mirror main and tags to Forgejo`を一度手動実行する。
4. GitHub Actionsが成功したことと、GitHub/Forgejoの`main` SHAが一致することを確認する。
5. tag同期は新しいtest tagまたは次回release tagで、両remoteのtag SHAが一致することを確認する。

失敗時はGitHub Actions logを確認する。Forgejoが分岐している場合はautomationで解消せず、両remoteの履歴と未反映作業を調査してOwner判断を得る。

## 停止

workflowをdisableするか`FORGEJO_PUSH_URL`を削除すれば、以後の同期は停止する。停止やworkflow削除は、すでにForgejoへpushされたcommitやtagを削除しない。
