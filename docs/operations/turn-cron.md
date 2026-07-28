# Turn cron operations

## Adopted deployment shape

Roadmap PR #7 adopts an OCI host cron as the hourly trigger. It invokes the same Laravel Artisan command used by an operator. The shell wrapper contains no game rules, ruleset selection, transaction control, or retry loop.

```text
OCI host cron
  -> optional host flock
  -> product/docker/cron/run-turn.sh
  -> docker compose exec -T hakoniwa-web
  -> php artisan hakoniwa:turn:run --source=cron
  -> TurnRunner
  -> PostgreSQL advisory lock + turn transaction
```

This is simpler than adding a cron daemon to `hakoniwa-web` or maintaining a dedicated scheduler container for one hourly World. A separate scheduler can be reconsidered when multiple Worlds or independent lifecycle jobs justify it. The database/application lock and unique turn-run key are authoritative; `flock` is only a cheap host-level filter.

PR #7 does not install or change production cron.

## Host wrapper

The checked-in wrapper requires the absolute deployed repository directory:

```console
HAKONIWA_PROJECT_DIR=/opt/hakoniwa-world \
HAKONIWA_WORLD_KEY=shared-world \
/opt/hakoniwa-world/product/docker/cron/run-turn.sh
```

It runs the command as `www-data`, without allocating a TTY. It inherits the service's existing environment and therefore does not copy credentials into a cron file.

## Hourly Asia/Tokyo example

Confirm the host cron implementation supports `CRON_TZ` before using this example:

```cron
CRON_TZ=Asia/Tokyo
0 * * * * /usr/bin/flock -n /run/lock/hakoniwa-shared-world-turn.lock /usr/bin/env HAKONIWA_PROJECT_DIR=/opt/hakoniwa-world HAKONIWA_WORLD_KEY=shared-world /opt/hakoniwa-world/product/docker/cron/run-turn.sh >> /var/log/hakoniwa-turn.log 2>&1
```

This produces 24 scheduled triggers per local day. The interval and timezone live in operations configuration, not game code. If the host does not support `CRON_TZ`, set the cron daemon timezone explicitly or translate the schedule to the host timezone and document it.

The log destination is owned by the host operator. Laravel also emits command failures through its configured application log. Do not log environment dumps, database URLs, or credentials.

## Pre-registration checks

Before registering production cron:

1. Deploy the exact reviewed image and run migrations normally.
2. Confirm `docker compose ps` reports `hakoniwa-web` and PostgreSQL healthy.
3. Run `php artisan hakoniwa:turn:status --world=shared-world`.
4. Run `php artisan hakoniwa:turn:run --world=shared-world --dry-run`.
5. Confirm the reported `ruleset_version_id`, target turn, phase order, and missing phases.
6. Do not register cron while the production pipeline is scaffold-blocked.

## Success, failure, and retry

- Exit code `0`: the dry run completed, or a complete production pipeline committed.
- Non-zero: the World was missing, a lock/idempotency guard rejected execution, the scaffold is incomplete, or execution failed.
- A duplicate host trigger returns quickly because `flock` may reject it; even without `flock`, the PostgreSQL advisory lock rejects overlap.
- On a Laravel failure, game state and `current_turn` roll back. The run history remains `failed` with bounded failure information.
- Inspect with `hakoniwa:turn:status`, fix the cause, then invoke the same run command manually. The same target run and saved seed are reused.
- There is no automatic retry, timeout kill, or stale-`running` takeover in this PR. Do not delete or edit the run row to force progress.

Long-running real phases require measured transaction/lock-duration testing before production enablement. If a command is interrupted after the database connection closes, the session advisory lock is released by PostgreSQL; the run record may still require operator diagnosis. Stale-run recovery is design gate D-02, not shell logic.
