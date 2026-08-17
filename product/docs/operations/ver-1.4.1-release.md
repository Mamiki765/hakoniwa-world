# ver 1.4.1 production update / smoke checklist

## Scopeと停止境界

この文書はreview済みver 1.4.1をproductionへ反映するときにoperatorが使う。PRの`main` mergeはdeploy、production DB migration、container置換、cron、backup、announcement公開を含まない。

ver 1.4.1はapplication表示と回帰を安定化するpatchで、新しいrulesetやdatabase migrationを追加しない。公開済み`hakoniwa-2s-plus-v1`、v2、v3 payloadを変更せず、World、Nation、cell、command queue、TurnRun、audit event、messageをresetまたはbackfillしない。production作業前には[`ver-1.4.0-release.md`](ver-1.4.0-release.md)のpreflight、write traffic停止、backup、read-only audit、rollback禁止境界も満たす。

## Production write-freeze / turn-cron change-control gate

このrepositoryだけでは、productionで実際に使うplayer write-freezeとturn cronのpause/resume procedureを一意に確定できない。repositoryが確認できるのは次の境界までである。

- hostのHTTP/HTTPS入口がNginx Proxy Managerであることは`docs/operations/existing-server-context.md`にあるが、production proxy hostの設定、write methodだけを拒否するaccess rule、変更・確認・復旧手順はrepositoryへ収録していない。
- [`docs/operations/turn-cron.md`](../../../docs/operations/turn-cron.md)はhost cronの登録例とchecked-in wrapperを示すが、productionで使用中のcrontab entry、実行user、host crontab / systemd timer / その他の管理方式、pause/resume commandは記録していない。同文書が明記するようにapplicationはhost crontabを読めない。
- repository既定Composeにwrite-freeze専用serviceはなく、`hakoniwa-web`停止をwrite-freezeとして採用するreview済み契約もない。web停止はGET health/smokeも失わせ得るため、operator承認なしに代用しない。
- Laravelのfile maintenance markerはcontainer置換を跨ぐ正本ではない。Nginx Proxy Manager操作、crontab編集、systemd、Compose停止を状況から推測して実行しない。

このgapが解消されるまで、backupやdeployの直前に実環境を見て手順を考える運用は禁止する。release windowの前にoperatorが次を確認・承認し、secretを含めずdeployment recordへexact mechanism、実施者、確認結果、復旧方法を記録する。

1. productionの入口と対象proxy host、およびGET等の許可範囲を維持したままplayer mutationを拒否できるか。拒否対象methodだけで十分か、OAuth callback等のGET系write routeも含むroute inventoryが必要か。
2. Nginx Proxy Managerの既存access rule等、実際に使用するwrite-freeze mechanism、その変更方法、反映確認方法、元へ戻す方法。既存mechanismがなければ、このrelease runbookへ未reviewの設定を即席追加せず別のoperations変更としてreviewする。
3. production turn triggerがhost crontab、container cron、systemd timerのどれか、実行user、現在のexact entry、timezone、wrapper path、log path。
4. cron entryを失わずpauseし、重複登録せずresumeするexact操作と、pause/resume後のmanager状態確認方法。
5. pause時点で既に起動中のturn processがないことを確認する方法。host設定だけでなく`hakoniwa:turn:status`、TurnRun、必要ならreview済みのprocess/lock確認を組み合わせる。
6. proxy/cronを変更する権限、二者確認の要否、操作記録、失敗時の連絡先。

上記の承認済み手順がdeployment recordにない場合は`OPERATIONAL PROCEDURE NOT APPROVED`としてrelease windowを開始しない。これはrelease preflight成功で代替できない。

### 承認済みmechanismを記入した後のcanonical sequence

actual commandや画面操作は前項で承認したenvironment固有procedureを参照し、次の順序を変えない。

1. player write freezeを実施する。
2. 外部clientから対象write routeが拒否され、許可するread routeの期待状態が保たれ、queue item等の記録値が増えていないことを確認する。
3. turn cronをpauseする。手動turn実行も禁止する。
4. cron manager上の停止、起動中turnなし、次target turnのunresolved TurnRun状態を確認する。
5. [`database-backup-and-restore.md`](database-backup-and-restore.md)のproduction wrapperでverified off-host backupを取得し、exit 0、暗号化、upload、HEAD、size/MD5、marker、local encrypted fileを確認する。
6. `hakoniwa:release:preflight --world=shared-world`とrelease固有read-only auditを実行する。freeze後の基準値が変わった場合は進めない。
7. exact reviewed SHA/imageのbuild、forward migration、web置換をrelease固有手順どおり行う。
8. migration status、health、public/read smoke、owner-only smoke、postflight、release固有read-only auditを完了する。この時点でもfreezeとcron pauseを維持する。
9. 承認済みprocedureでturn cronをresumeする。重複entryを作らない。
10. cron manager上のactive状態、exact entry/timezone/wrapper、次回予定を確認する。必要な場合は次の公式turnを監視するが、確認目的の追加production turnを即興で実行しない。
11. 承認済みprocedureでplayer write freezeを解除する。
12. 外部clientからread routeとowner本人の許可されたwrite routeを確認し、freeze ruleが残っていないこと、release確認用mutationを通常の監査付き経路で整理したことを記録する。

### 途中STOP時

- backup、migration、web置換の前でold versionが完全に稼働可能なら、調査中はcron pauseとwrite freezeを維持する。releaseを中止して通常運用へ戻す場合だけ、old versionのhealth、TurnRun status、release preflight、実データ基準値を再確認し、承認済みprocedureでcron resume確認、write-freeze解除確認を行う。停止したreleaseのためにcheckoutやDBをrollbackしない。
- migration開始後またはnew image置換後に失敗した場合は、cron pauseとwrite freezeを維持する。forward migrationをgeneric rollbackせず、exact SHA/image、migration status、logs、backup、TurnRun/queue基準値を保存して別のreview済みforward fixまたは明示承認されたrestore判断を待つ。
- procedure自体が不明、確認結果が曖昧、またはpause/freezeを実証できない場合も同じくSTOPする。推測したproxy、crontab、systemd、Compose操作で先へ進まない。

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
