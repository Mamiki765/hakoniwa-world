# ver 1.5.0 shared-world 60×60 → 64×64 expansion runbook

## Scope and stop boundary

This runbook is for the one reviewed production operation that expands `shared-world` from `x=0..59, y=0..59` to `x=0..63, y=0..63` after the operator command has been merged, deployed, and backed up. Merging or deploying the command does not expand the World. The operation changes only the surface `MapSpace` bounds and the 496 missing neutral-sea `MapCell` rows. It does not change a ruleset, existing cell state, chunk metadata/version, disaster behavior, Nation state, or TurnRun history.

The command permits read-only `--dry-run` in any environment, but mutation is fail-closed unless `APP_ENV=production`. Its production operation contract is deliberately limited to this exact 60×60 → 64×64 transition and its same-target retry. A different expansion requires a separately reviewed operator release even though the underlying `WorldExpansionService` accepts explicit containing bounds.

Do not proceed if any of the following is true:

- the deployed commit is not the reviewed release commit, the worktree is dirty, or the production containers are unhealthy;
- the backup wrapper does not exit zero and complete its off-host verification;
- `APP_ENV` is not `production`, the configured World is not `shared-world`, or the current bounds are not exactly the expected before bounds or the already-completed target bounds;
- current coverage is not complete, any unresolved non-dry-run TurnRun is `pending`, `running`, `failed`, or `blocked`, or the current ruleset is historical;
- the first preflight does not report 3,600 current cells, 4,096 target cells, exactly 496 added cells, 16 existing/target chunks, zero created chunks, and seven touched existing chunks;
- the execution or postflight reports a cell count other than 4,096 or a chunk count other than 16.

Do not repair an unexpected state, retry a failed TurnRun automatically, run direct SQL, reset the World, or restore production as an improvisation. Preserve logs and the exact deployed image and stop for investigation. A production restore requires a separate approved recovery procedure; it is not part of `WorldExpansionService`.

## 1. Verify the deployed release and maintenance window

Run on the production host as `root`. Replace the placeholder with the reviewed commit that contains this runbook and command.

```bash
set -Eeuo pipefail

repository='/home/ubuntu/apps/hakoniwa-world'
compose_directory='/home/ubuntu/apps'
release_sha='<reviewed-release-sha>'

test "$(git -C "${repository}" rev-parse HEAD)" = "${release_sha}"
test -z "$(git -C "${repository}" status --porcelain)"
git -C "${repository}" log -1 --oneline

cd "${compose_directory}"
docker compose ps
docker compose exec -T --user www-data hakoniwa-web php artisan about --only=environment
```

Confirm PostgreSQL and `hakoniwa-web` are healthy and the application environment is production. Use the already-reviewed production maintenance procedure to block player write traffic and pause the turn cron before the expansion window. Confirm no new command or TurnRun starts while the window is held. Do not invent a second application lock; the execution itself uses the common `WorldMutationLock`.

## 2. Take and verify a fresh backup

The canonical backup contract is [database-backup-and-restore.md](database-backup-and-restore.md). Do not execute the expansion before every check below succeeds for the same backup run.

```bash
backup_status=0
/home/ubuntu/apps/hakoniwa-world/product/docker/backup/run-production-backup.sh \
  >> /var/log/hakoniwa-backup.log 2>&1 || backup_status=$?
tail -n 40 /var/log/hakoniwa-backup.log
test "${backup_status}" -eq 0
```

Verify the same run has one `production_backup=ok object=hakoniwa-... bytes=...` line; encryption, upload, HEAD, and verification are all `status=ok`; and it has no `production_backup=failed` or `backup_error=` line. Verify the named encrypted file and its `.uploaded` marker exist, and the private Object Storage object has the same filename, byte size, and MD5. A nonzero wrapper exit or incomplete verification stops this operation even if `tail` looks successful.

## 3. Inspect TurnRuns and run the read-only expansion preflight

```bash
cd /home/ubuntu/apps

docker compose exec -T --user www-data hakoniwa-web \
  php artisan hakoniwa:turn:status --world=shared-world

docker compose exec -T --user www-data hakoniwa-web \
  php artisan hakoniwa:release:preflight --world=shared-world

docker compose exec -T --user www-data hakoniwa-web \
  php artisan hakoniwa:world:expand \
  --world=shared-world \
  --expected-min-x=0 --expected-max-x=59 \
  --expected-min-y=0 --expected-max-y=59 \
  --target-min-x=0 --target-max-x=63 \
  --target-min-y=0 --target-max-y=63 \
  --reason='ver 1.5.0 production shared-world 60x60 to 64x64' \
  --dry-run
```

The expansion preflight must exit zero and report all of the following:

```text
app_env=production
current_bounds=x=0..59,y=0..59
expected_before_bounds=x=0..59,y=0..59
target_bounds=x=0..63,y=0..63
current_cells=3600 target_cells=4096 expected_added_cells=496
existing_chunks=16 target_chunks=16 predicted_created_chunks=0 predicted_touched_existing_chunks=7
operation_contract=ok production_guard=not-required-dry-run
ruleset=ok coverage=ok chunk_coverage=ok
unresolved_turn_runs=0
current_state=expected-before requested_operation=expand
preflight=ok
execution=not_started dry_run=true
```

Record the preflight `bounds_revision`. The exact confirmation token for this request is also reported as:

```text
EXPAND:shared-world:0:59:0:59:TO:0:63:0:63
```

If current state is already the target, the command must instead report complete target coverage and `requested_operation=no-op`. That is a completed same-target retry, not authority to infer or repair any other bounds.

## 4. Execute exactly once

Recheck that the maintenance window is held and no unresolved TurnRun appeared after preflight. Then run the same explicit request with the exact confirmation token. The command rechecks every safety gate inside the service's common World lock and database transaction.

```bash
cd /home/ubuntu/apps

docker compose exec -T --user www-data hakoniwa-web \
  php artisan hakoniwa:world:expand \
  --world=shared-world \
  --expected-min-x=0 --expected-max-x=59 \
  --expected-min-y=0 --expected-max-y=59 \
  --target-min-x=0 --target-max-x=63 \
  --target-min-y=0 --target-max-y=63 \
  --reason='ver 1.5.0 production shared-world 60x60 to 64x64' \
  --confirm='EXPAND:shared-world:0:59:0:59:TO:0:63:0:63'
```

Require exit zero and an `execution=complete` result with `result_bounds=x=0..63,y=0..63 cells=4096 chunks=16`. A failure rolls the transaction back; do not switch to SQL or a reset. Diagnose the reported blocker while player writes and the turn cron remain paused.

## 5. Verify bounds, counts, coverage, TurnRuns, and audit

Run the same read-only preflight again. Because current bounds now equal the target, this invocation must be a safe no-op preview and must not add an audit event.

```bash
cd /home/ubuntu/apps

docker compose exec -T --user www-data hakoniwa-web \
  php artisan hakoniwa:world:expand \
  --world=shared-world \
  --expected-min-x=0 --expected-max-x=59 \
  --expected-min-y=0 --expected-max-y=59 \
  --target-min-x=0 --target-max-x=63 \
  --target-min-y=0 --target-max-y=63 \
  --reason='ver 1.5.0 production shared-world 60x60 to 64x64 postflight' \
  --dry-run

docker compose exec -T --user www-data hakoniwa-web \
  php artisan hakoniwa:turn:status --world=shared-world
```

Require `current_bounds=x=0..63,y=0..63`, `current_cells=4096`, `existing_chunks=16`, all three validation statuses `ok`, `unresolved_turn_runs=0`, and `current_state=target requested_operation=no-op`. The output must include the latest `world.expanded` audit metadata with before/after bounds, `added_cell_count=496`, `created_chunk_count=0`, `touched_existing_chunk_count=7`, and the execution reason. There must be only one success event for this operation; a same-target execution retry does not create another.

## 6. Verify the public revision and frontend

The public map-space endpoint requires no authentication. Set the production origin, obtain the numeric `shared-world` ID from the first response, then inspect its map-space response.

```bash
production_origin='https://<production-host>'
curl -fsS "${production_origin}/api/v1/public/worlds"

world_id='<shared-world-numeric-id>'
curl -fsS "${production_origin}/api/v1/public/worlds/${world_id}/map-spaces"
```

Verify the `surface` entry reports bounds `0..63` on both axes and a `bounds_revision` different from the value recorded before expansion. In an authenticated owner session and a public Nation preview, reload the map and verify normal rendering and neutral sea at the new right/bottom edge. The existing revision-change contract clears cells, selection, loaded/confirmed-empty chunk caches, and ignores stale in-flight requests; this operation adds no new frontend UI or runtime full-coverage scan.

## 7. Resume and observe one normal turn

Only after all postflight and UI checks pass, use the established production procedure to reopen player writes and resume the existing turn cron. Let the next scheduled non-dry-run turn execute once; do not add a second manual turn merely for smoke testing. After it completes, run:

```bash
cd /home/ubuntu/apps

docker compose exec -T --user www-data hakoniwa-web \
  php artisan hakoniwa:turn:status --world=shared-world

docker compose exec -T --user www-data hakoniwa-web \
  php artisan hakoniwa:release:preflight --world=shared-world
```

Verify the World advanced by exactly one turn, the latest non-dry TurnRun is `completed`, and its ruleset is the same current v3 ruleset. If it is `failed` or `blocked`, stop: preserve the run and its audit history and use the existing same-target-turn, same-ruleset, same-seed manual retry contract only after diagnosis and explicit operator decision. Never retry it automatically across a release.

The 64×64 World still has 16 chunk rows. This operation does not authorize any disaster probability/center change, negative-coordinate production expansion, sea-edge change, registration-driven auto expansion, or later 64→80 operation.
