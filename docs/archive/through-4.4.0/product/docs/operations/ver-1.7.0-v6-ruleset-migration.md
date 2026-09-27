# ver 1.7.0 ruleset v6 production migration

## Scope and non-negotiable guards

This runbook is only for the reviewed forward migration from
`hakoniwa-2s-plus-v5` to `hakoniwa-2s-plus-v6`. Merging the release does not
authorize deployment, production migration, queue mutation, or resuming a
TurnRun.

The migration fails closed by default when a queued v5 `logging`,
`build_defense_facility`, or `build_monument` item exists. The only permitted
exception is the v6-specific, one-invocation environment variable below after
an operator has reviewed every reported queue item and its target map state.

```text
HAKONIWA_V6_REBIND_REVIEWED_QUEUE_ITEMS=CONFIRM_REVIEWED_V5_QUEUE_ITEMS_TO_V6
```

Never put this variable in `.env`, Compose configuration, an image, a service
definition, a secret manager, or a persistent shell profile. It does not
bypass unresolved TurnRun checks, the World advisory/database locks, stable-key
and live-reference checks, published ruleset checksum validation, kill-stat
collision checks, or database constraints. Laravel's `migrate --force` only
allows a production migration command to run; it does not bypass the affected
queue guard.

## Freeze and backup

1. Record the exact reviewed release SHA, image digest, World key, current turn,
   current ruleset, pending migrations, and the next non-dry TurnRun state.
2. Block player write traffic at the reverse proxy or load balancer. Stop the
   turn cron and prohibit manual turn execution. Confirm that command queue and
   TurnRun counts remain unchanged while the window is held.
3. Run `php artisan hakoniwa:release:preflight --world=shared-world`. A next-turn
   non-dry `pending`, `running`, `failed`, or `blocked` TurnRun stops this
   procedure. Do not use the queue override for it.
4. Take and verify a fresh off-host production backup using
   [database-backup-and-restore.md](database-backup-and-restore.md). Require the
   wrapper to exit zero and verify encryption, upload, remote HEAD, size/MD5,
   the local encrypted file, and its `.uploaded` marker. Keep the backup.
5. Build the exact reviewed image, but do not replace the persistent web
   container yet.

## Default migration attempt and operator review

Run the migration once without any override from the Compose project directory:

```bash
docker compose run --rm --no-deps \
  hakoniwa-web \
  php artisan migrate --force
```

If no affected queued v5 item exists, this is the normal migration path. If the
migration reports affected items, it exits nonzero without rebinding live
references. Preserve the full output. Each JSON line reports `item_id`, Nation
ID/number/name, queue ID and position, command key, and target x/y.

While write traffic and the turn cron remain stopped, take one read-only,
repeatable-read database snapshot and save its ordered output in the deployment
record. The query below shows the affected queue payload and current target map
state; use the reviewed production database access wrapper rather than putting
credentials in shell history.

```sql
BEGIN TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY;

SELECT
    item.id AS item_id,
    nation.id AS nation_id,
    nation.nation_number,
    nation.name AS nation_name,
    queue.id AS queue_id,
    item.queue_position,
    definition.key AS command_key,
    item.target_x,
    item.target_y,
    item.quantity,
    item.parameters,
    target.owner_nation_id AS target_owner_nation_id,
    terrain.key AS target_terrain_key,
    facility.key AS target_facility_key,
    target.facility_scale,
    target.monument_definition_id,
    target.state AS target_state
FROM nation_command_queue_items item
JOIN nation_command_queues queue
  ON queue.id = item.nation_command_queue_id
JOIN nations nation
  ON nation.id = queue.nation_id
JOIN worlds world
  ON world.id = nation.world_id
JOIN command_definitions definition
  ON definition.id = item.command_definition_id
JOIN ruleset_versions ruleset
  ON ruleset.id = definition.ruleset_version_id
LEFT JOIN map_cells target
  ON target.map_space_id = queue.map_space_id
 AND target.x = item.target_x
 AND target.y = item.target_y
LEFT JOIN terrain_definitions terrain
  ON terrain.id = target.terrain_definition_id
LEFT JOIN facility_definitions facility
  ON facility.id = target.facility_definition_id
WHERE world.key = 'shared-world'
  AND ruleset.key = 'hakoniwa-2s-plus-v5'
  AND item.status = 'queued'
  AND definition.key IN ('logging', 'build_defense_facility', 'build_monument')
ORDER BY nation.nation_number, queue.id, item.queue_position, item.id;

COMMIT;
```

Match every row to the migration failure output. Review the entire queue order,
including earlier commands that can change the same target before this item.
Confirm and record the intended v6 result: logging produces plain terrain;
owner defense overbuild self-destructs; owner monument overbuild becomes a
flight and requires a valid target Nation. Inspect the referenced target cells
and any `target_nation_id` parameter. If any intent or projected state is
ambiguous, stop the release and leave the default guard in place.

## One-shot reviewed rebind

Only after the review above is signed off, rerun the exact reviewed image in a
new temporary container with the dedicated variable attached to that command:

```bash
docker compose run --rm --no-deps \
  -e HAKONIWA_V6_REBIND_REVIEWED_QUEUE_ITEMS=CONFIRM_REVIEWED_V5_QUEUE_ITEMS_TO_V6 \
  hakoniwa-web \
  php artisan migrate --force
```

The temporary container must be removed by `--rm`; do not copy the variable to
the persistent service. The override permits only the three-key queued-item
rebind check to proceed. If any other guard fails, preserve the output and stop.
Do not add another variable, use generic force as a workaround, edit the
migration table, rebind definitions with direct SQL, cancel queues, reset the
World, or run `migrate:rollback`/`migrate:fresh` during this release window.

## Postflight and restart

1. Without the override variable, verify `php artisan migrate:status` has no
   pending migration and rerun the release preflight.
2. In a read-only snapshot, verify `shared-world` and every live command,
   monster instance, and monster kill-stat reference use v6. Compare the saved
   affected-item rows and confirm that only `command_definition_id` changed;
   queue position, coordinates, quantity, parameters, status, request key, and
   timestamps remain unchanged.
3. Replace the persistent web container with the exact reviewed image. Confirm
   its environment does not contain
   `HAKONIWA_V6_REBIND_REVIEWED_QUEUE_ITEMS`.
4. Run authenticated UI/API smoke checks, including the command queue and the
   reviewed affected items. Keep external writes and the turn cron stopped if
   any check fails.
5. Reopen player write traffic only after every postflight check passes, then
   resume the existing turn cron last. Monitor the next official turn and
   verify it completes once with the same v6 ruleset. Never retry a failed turn
   automatically across the release.

Production rollback is restore from the verified checkpoint backup or a new,
explicitly reviewed forward conversion while writes and the turn cron remain
stopped. The v6 migration is forward-only.
