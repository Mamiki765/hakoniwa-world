# ver 1.4.1 production update / smoke checklist

## Scopeと停止境界

この文書はreview済みver 1.4.1をproductionへ反映するときにoperatorが使う。PRの`main` mergeはdeploy、production DB migration、container置換、cron、backup、announcement公開を含まない。

ver 1.4.1はapplication表示と回帰を安定化するpatchで、新しいrulesetやdatabase migrationを追加しない。公開済み`hakoniwa-2s-plus-v1`、v2、v3 payloadを変更せず、World、Nation、cell、command queue、TurnRun、audit event、messageをresetまたはbackfillしない。production作業前には[`ver-1.4.0-release.md`](ver-1.4.0-release.md)のpreflight、write traffic停止、backup、read-only audit、rollback禁止境界も満たす。

## Repositoryを`main`へ正規化

checkoutは`/home/ubuntu/apps/hakoniwa-world`、Compose projectは`/home/ubuntu/apps`を正本とする。開始前にproduction作業記録へ現在branch、HEAD、worktree状態を残す。未追跡・未コミット差分があれば上書きせず停止する。

通常運用は次のfast-forward-only手順を使う。

```bash
cd /home/ubuntu/apps/hakoniwa-world

git fetch origin
git switch main
git pull --ff-only origin main
git status --short --branch
git log -1 --oneline
```

hostにlocal `main`がまだ存在せず、`git switch main`が失敗した初回だけ、`origin/main`を確認してtracking branchを作る。既存branchをrename、force-update、resetしない。

```bash
cd /home/ubuntu/apps/hakoniwa-world

git fetch origin
git show-ref --verify refs/remotes/origin/main
git switch --track -c main origin/main
git status --short --branch
git log -1 --oneline
```

作成後は通常手順へ戻し、`main...origin/main`、clean worktree、承認済みrelease SHAが一致することを確認する。

## Build、migration、常駐web置換

新imageを先にbuildし、そのimageのtemporary containerでforward migrationを完了してから常駐webを置換する。migrationが失敗した状態で旧常駐webを不用意に置換しない。`docker compose run --rm --no-deps`はmigration guardを迂回しない正規の実行方法であり、guard failure時は`exec`へ切り替えて再試行しない。

```bash
cd /home/ubuntu/apps

docker compose build hakoniwa-web

docker compose run --rm --no-deps \
  hakoniwa-web \
  php artisan migrate --force

docker compose up -d \
  --no-deps \
  --force-recreate \
  hakoniwa-web

docker compose exec -T hakoniwa-web \
  php artisan migrate:status

docker compose ps
```

temporary container migrationが非ゼロ終了した場合は、常駐web置換前に停止する。出力、exact image、HEAD、World、次turnのTurnRunを記録し、migrationを手作業で部分適用しない。`migrate:rollback`、`migrate:fresh`、World reset、volume再作成、direct SQLでのqueue付け替えを行わない。原因解消にproduction mutationが必要なら、そのrelease windowを中止して別途明示承認を得る。

## Post-deploy smoke

`migrate:status`だけで成功と判断しない。public API、operator本人のowner account、必要なら専用test accountだけを使い、他人accountをimpersonateしない。次を1項目ずつ時刻と結果付きで記録する。

- application headerが`ver 1.4.1`である。
- `php artisan migrate:status`にPending migrationが0件である。
- `docker compose ps`でPostgreSQLとwebが正常で、web healthcheckが成功している。
- TOPが表示され、World turn statusが正常である。
- 自島HUD、map、開発workspaceが表示される。
- command catalogで本物は「防衛施設建設」、`build_decoy`は「ハリボテ建築」と区別できる。
- owner本人が実際に実行予定の通常開発commandを1件登録でき、開発計画にも同じcommand名で反映される。smoke確認だけのitemを使う場合は、表示確認後に通常のcancel操作で監査を残して取り消し、queueから除かれたことを確認してからcronを再開する。
- TOP公開島ログへ整地、地ならし、埋め立て、掘削、農場・工場・採掘場の公開可能な通常行動が反映される。
- TOP公開島ログで従来の伐採、ミサイル、災害等が引き続き表示され、秘密施設の正体やprivate座標が出ない。
- 島主ログへ自島の公開行動とowner/private detailが表示され、他島event、companion重複、routine生産・食料消費・内部counter通知が混ざらない。
- 自島と公開previewの両方で、伝言板が島ログより上に表示される。
- 伝言板timelineを取得できる。
- owner本人またはtest accountが通常伝言を1件投稿でき、同じtimelineへ反映される。release確認用の不要投稿は残さない運用ルールに従う。
- `php artisan hakoniwa:turn:status --world=shared-world`とrelease preflightが正常である。
- deploy開始時刻以降のDocker/Laravel logに新規HTTP 500、migration exception、database constraint errorがない。

write trafficとturn cronを再開する前に全項目を確認する。失敗項目があれば再開せず、exact SHA/imageとログを保持して調査する。次の公式turnは別途監視し、World turnとTurnRunが同じv3 rulesetで完了したことを確認する。

## Announcement

全smoke成功後、[`ver-1.4.1-announcement.md`](../ver-1.4.1-announcement.md)のtitle/bodyを既存のお知らせ管理画面から1回だけ公開する。migration、seeder、deploy scriptで記事を作らず、同じrelease記事があれば重複作成しない。
