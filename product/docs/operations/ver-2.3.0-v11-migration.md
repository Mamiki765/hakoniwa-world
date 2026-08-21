# ver 2.3.0 ruleset v11 operator checklist

この文書はreview済みver 2.3.0 releaseをexact v10 `shared-world`へ適用するoperator checklistである。mergeはdeploy、production DB migration、turn再開を許可しない。実行window、担当者、release SHA、backup、停止時間は別途明示承認する。本PRの検証ではこの手順をproductionへ実行しない。

## 1. Freeze, identity, and backup

- player write trafficとturn cronを承認済み手順で停止し、manual TurnRunを禁止する。
- worktreeがcleanで、checked-out commit、reviewed release SHA、remote SHA、build sourceが完全一致することを記録する。
- `shared-world`がexact `hakoniwa-2s-plus-v10`で、published settings checksumが`6a0f5354f8894081bacdb8eaaba328d3e4ee80a2c4136819b16cfa924f485dc1`であることをread-onlyで確認する。別version、複数World、partial v11 referenceなら停止する。
- `php artisan hakoniwa:release:preflight --world=shared-world`を実行する。next non-dry TurnRunが`pending`、`running`、`failed`、`blocked`ならmigrationを開始しない。failed runをrelease越しに自動retryしない。
- [database-backup-and-restore.md](database-backup-and-restore.md)のapproved wrapperでfresh off-host encrypted backupを取得し、wrapper exit zero、encryption、upload、remote HEAD、size/MD5、local encrypted file、`.uploaded` markerを同じrunで検証する。backupを保持する。

## 2. Build and migrate

- exact reviewed commitからapplication imageをbuildし、digestをdeployment recordへ残す。`latest`や未review sourceへ置き換えない。
- persistent serviceの切替前に、同じreviewed imageで次を1回実行する。

```bash
docker compose run --rm --no-deps \
  hakoniwa-web \
  php artisan migrate --force
```

- 非zeroならoutputとDB backupを保持して停止する。direct SQL、migration table編集、queue cancel、fingerprint再計算、World reset、`migrate:rollback`、`migrate:fresh`で回避しない。
- `php artisan migrate:status`でpending migrationがなく、v11 migrationが1回だけ`Ran`であることを確認する。

## 3. Read-only postconditions

同じread-only repeatable-read snapshotで次を件数付きで記録する。

- `shared-world.ruleset_version_id`がexact `hakoniwa-2s-plus-v11`で、published checksumが`5c65c49ed3fd623375f004815ec6bba0b2f67524f61f0638c6fe528fe9599db8`である。
- queued itemのdefinitionがv11で、request provenanceはproved original v10 identityを保持する。queue position、quantity、parameters、target snapshot、request key、fingerprint、status、timestampsはpreflight snapshotと同一である。
- `completed`、`failed`、`cancelled` itemのdefinition、payload、status、fingerprintはhistorical v10のままである。safe non-null fingerprintのprovenance backfill以外に差分がない。
- alive MonsterInstanceはすべてv11 definitionを参照し、HP、state、occupancy、spawn turn、versionが同一である。killed/removed rowsはv10 definitionを保持する。
- current NationMonsterKillStatはすべてv11 definitionを参照し、kill count、first/last turn、versionが同一である。duplicate rowがない。
- null fingerprintはnull、non-null fingerprintはpreflight bytesと完全一致し、contradictory/null queued provenanceがない。
- `monster_instance_world_ruleset_guard`、`nation_monster_kill_stat_guard`、`nation_command_queue_items_world_ruleset_match`がすべてenabledで、reviewed schemaのtrigger/function definitionと一致する。
- User、Secretary identity/name/skills/experience/equipment version、Item ID/key/level/grant/equipped slot、Warehouse count、Nation balances、MapCell count、current turn、TurnRun/event countがpreflight snapshotと一致する。starter Old Bowはexactly one、Ring自動grantはzeroである。

## 4. Application health and controlled smoke

- exact reviewed imageへserviceを切り替え、health endpoint、public lobby/manual、authenticated Secretary equipment、command catalog/queue、monster tableを確認する。custom Aoi/Zero GIFが外部asset pathにない場合はsafe fallback表示になることを許容し、binaryをrepositoryから補わない。
- write trafficとcronを停止したまま、承認済みoperator identityでone controlled v11 TurnRun smokeを実行する。target turn、ruleset ID、seed、sourceを記録し、1回だけ`completed`、Worldが1 turnだけ進むことを確認する。
- smokeが`failed`または`blocked`なら停止し、same-target/same-ruleset/same-seed manual retry boundaryを守る。自動retry、別seed、release越しretryをしない。
- healthとpostconditionがすべてgreenになってからplayer writesを再開し、turn cronは最後に再開する。

## 5. Rollback boundary

このmigrationはforward-onlyであり、`down()`はrollback手段ではない。production rollbackは停止中のwrite/cronを維持し、migration前に検証したapproved backupからrestoreする別途承認済みrecovery operationである。partial stateをdirect SQLで修正しない。
