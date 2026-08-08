# 1.1.1 live ruleset reference repair

This hotfix repairs the two live monster references omitted when `shared-world`
was moved from `hakoniwa-2s-plus-v1` to `hakoniwa-2s-plus-v2`. It does not change
either published ruleset, the World ruleset, gameplay, a TurnRun, or queue state.

## Root cause and audit scope

The 1.1.0 migration repointed the World and queued command definitions, but left
pre-existing `monster_instances.monster_definition_id` and
`nation_monster_kill_stats.monster_definition_id` on v1. The monster instance
guard and kill-stat guard require a definition from the current World ruleset.
The stale kill-stat definition therefore aborts a v2 kill with `monster kill stat
references inconsistent World state`.

The schema, foreign keys, triggers, deferred constraint, application writes,
polymorphic audit subjects, JSON metadata, and migrations through 1.1.0 were
checked with this classification:

| Reference or lookup | Class | Switch behavior |
| --- | --- | --- |
| `worlds.ruleset_version_id` | A: live mutable | Current ruleset pointer; already v2. |
| `nation_command_queue_items.command_definition_id` | A: live mutable | Must follow the World by command key; already migrated and protected by `nation_command_queue_items_world_ruleset_match`. |
| `monster_instances.monster_definition_id` | A: live mutable | Must follow the World by monster key; omitted in 1.1.0. |
| `nation_monster_kill_stats.monster_definition_id` | A: live mutable | Must follow the World by monster key; omitted in 1.1.0. |
| `turn_runs.ruleset_version_id` | B: historical snapshot | Retain the ruleset used when the run was created, including completed and failed runs. Never repoint. |
| `command_definitions.ruleset_version_id` | B: published catalog snapshot | Immutable v1/v2 rows; never rewrite. |
| `production_definitions.ruleset_version_id` | B: published catalog snapshot | No live table has a direct FK to it, so no conversion is required. |
| `monster_definitions.ruleset_version_id` | B: published catalog snapshot | Immutable v1/v2 rows; live references map by key. |
| `audit_events.subject_id` and JSON metadata | B: historical audit | Subjects are entity IDs; command/monster metadata persists stable keys rather than definition IDs. Do not rewrite. |
| terrain, facility, resource, and monument definitions | C: global catalog | Not ruleset-scoped. |
| production resolution and other definition-by-key reads | D: runtime lookup only | No persisted definition ID to repair. |

No later migration adds another persisted live FK into a ruleset-scoped catalog.
The only confirmed A-class omissions are the two monster tables above.

## Read-only production audit

Keep cron stopped. From the reviewed release checkout, run the committed audit
inside a PostgreSQL client that can read production:

```console
psql "$DATABASE_URL" --set ON_ERROR_STOP=1 \
  --file docs/operations/ruleset-live-reference-audit.sql
```

The script starts a repeatable-read, read-only transaction and rolls it back. It
prints the FK, CHECK, deferred-constraint, and trigger inventory; counts and row
details for all three A-class definition references; and an informational
TurnRun grouping. Old TurnRun rulesets are not reported as errors.

Expected pre-hotfix result for the known incident:

- queued command item mismatch: `0`;
- monster instance mismatch: one or more v1 rows;
- monster kill-stat mismatch: one or more v1 rows.

If any other A-class mismatch appears, stop. Do not broaden this hotfix or repair
rows manually.

## Apply the forward-only repair

1. Leave the failed Turn 40 row unchanged and keep automatic cron execution off.
2. Record the failed row ID, ruleset ID, seed, status, and attempt count, and take
   the normal production database backup.
3. Deploy the reviewed 1.1.1 image.
4. Run `php artisan migrate --force`. The repair obtains the shared World advisory
   transaction lock and requires that the World already use v2.
5. Run `docs/operations/ruleset-live-reference-audit.sql` again. Every A-class
   mismatch count must be `0`.
6. Confirm the World still uses v2 and remains at turn 39. Confirm the failed Turn
   40 row still has its original ID, ruleset ID, seed, status, and attempt count.

`hakoniwa:release:preflight` must continue to report the failed Turn 40 before
this repair; do not alter the run to make that gate green. This is the narrowly
authorized emergency migration for that already-diagnosed failed run, not a
general preflight bypass. Keep player writes and cron stopped for the maintenance
window, apply only the reviewed migration, and proceed to the explicit retry
below after all post-migration checks pass.

The migration maps the complete v1/v2 monster catalogs by unique monster key and
updates only `monster_definition_id`, including alive, killed, and removed
instances. It preserves HP, state, version, position, kill counts and turns,
timestamps, queues, and TurnRuns. It disables only the named
`nation_monster_kill_stat_guard` inside the migration transaction while changing
kill-stat definition IDs. Transaction rollback restores both data and trigger
state. Missing or ambiguous mappings, unexpected source rulesets, or a v1/v2
kill-stat unique collision stop the migration without merging data.

There is no destructive rollback. Re-running an applied migration is a no-op;
invoking the migration logic again is also idempotent after all rows point to v2.

## Manually retry failed Turn 40

After the post-migration audit is clean, retry once as an operator:

```console
php artisan hakoniwa:turn:run \
  --world=shared-world \
  --source=manual
```

The runner must reuse the existing failed Turn 40 row, its v2 ruleset snapshot,
and its saved seed, incrementing only the attempt count. Verify that the command
returns success, the same TurnRun becomes `completed`, `current_turn` becomes 40,
and the next status reports target turn 41. Resume cron only after those checks
pass. Do not delete, edit, recreate, or automatically retry the failed row.
