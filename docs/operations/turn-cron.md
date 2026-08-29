# Turn cron operations

## Adopted deployment shape

PR23 enables an OCI host cron as the production trigger. Version 1.1.0 fixes that trigger to every even hour in Asia/Tokyo (00:00, 02:00, ..., 22:00). It invokes the same Laravel Artisan command used by an operator. The shell wrapper contains no game rules, ruleset selection, transaction control, or retry loop.

```text
OCI host cron
  -> optional host flock
  -> product/docker/cron/run-turn.sh
  -> docker compose exec -T hakoniwa-web
  -> php artisan hakoniwa:turn:run --source=cron
  -> TurnRunner
  -> PostgreSQL advisory lock + turn transaction
```

This is simpler than adding a cron daemon to `hakoniwa-web` or maintaining a dedicated scheduler container for one World. A separate scheduler can be reconsidered when multiple Worlds or independent lifecycle jobs justify it. The database/application lock and unique turn-run key are authoritative; `flock` is only a cheap host-level filter.

The repository supplies the reviewed wrapper and registration example. The operator installs the cron entry on the actual production host after the pre-registration checks below; credentials remain in the existing Compose environment and are not copied into cron.

## Host wrapper

The checked-in wrapper requires the absolute deployed repository directory:

```console
HAKONIWA_PROJECT_DIR=/opt/hakoniwa-world \
HAKONIWA_WORLD_KEY=shared-world \
/opt/hakoniwa-world/product/docker/cron/run-turn.sh
```

It runs the command as `www-data`, without allocating a TTY. It inherits the service's existing environment and therefore does not copy credentials into a cron file.

## Even-hour Asia/Tokyo example

Confirm the host cron implementation supports `CRON_TZ` before using this example:

```cron
CRON_TZ=Asia/Tokyo
0 */2 * * * /usr/bin/flock -n /run/lock/hakoniwa-shared-world-turn.lock /usr/bin/env HAKONIWA_PROJECT_DIR=/opt/hakoniwa-world HAKONIWA_WORLD_KEY=shared-world /opt/hakoniwa-world/product/docker/cron/run-turn.sh >> /var/log/hakoniwa-turn.log 2>&1
```

This produces 12 scheduled triggers per local day. On a UTC cron host, the equivalent fixed schedule is `0 1-23/2 * * *`: JST 00:00 is the previous UTC date at 15:00, followed by 17:00, 19:00, 21:00, 23:00, 01:00, ..., 13:00 UTC. Prefer `CRON_TZ=Asia/Tokyo` so this date boundary remains explicit. Japan does not observe daylight-saving time.

The public turn health contract uses the same `Asia/Tokyo` even-hour schedule and `HAKONIWA_TURN_SCHEDULE_GRACE_MINUTES` (default 15). Keep the cron entry, environment, and application config together when changing operations. The web process never reads the host crontab directly.

The log destination is owned by the host operator. Laravel also emits command failures through its configured application log. Do not log environment dumps, database URLs, or credentials.

## Daily Underground battle-log prune

Undergroundの1000時間retentionを越えた詳細battle logは、Turnと同じOCI host cron thin-trigger patternから1日1回cleanupする。Laravel schedulerやcron daemonをapplication containerへ追加せず、checked-in wrapperが既存`hakoniwa-web` container内の`underground:prune-battle-logs`を実行する。

```cron
CRON_TZ=Asia/Tokyo
15 3 * * * /usr/bin/flock -n /run/lock/hakoniwa-underground-log-prune.lock /usr/bin/env HAKONIWA_PROJECT_DIR=/opt/hakoniwa-world /opt/hakoniwa-world/product/docker/cron/prune-underground-battle-logs.sh >> /var/log/hakoniwa-underground-log-prune.log 2>&1
```

このjobは`expires_at <= now`の`underground_battle_logs`だけを削除する。`underground_battles`のsummary、damage/recovery aggregate、request idempotency identityは保持する。03:15 JSTの独立jobであり、Turn command、TurnRun、World lock、failed/blocked Turnのmanual retry contractには触れない。非ゼロ終了時は同じcommandをoperatorが原因確認後に明示実行し、shell側でretry loopを作らない。

## Pre-registration checks

Before registering production cron:

1. Deploy the exact reviewed image and run migrations normally.
2. Confirm `docker compose ps` reports `hakoniwa-web` and PostgreSQL healthy.
3. Run `php artisan hakoniwa:turn:status --world=shared-world`.
4. Run `php artisan hakoniwa:turn:run --world=shared-world --dry-run`.
5. Confirm the reported `ruleset_version_id`, target turn, phase order, and missing phases.
6. Run `php artisan hakoniwa:release:preflight --world=shared-world` with the configured external contact URL.
7. Register the cron entry, observe one official execution, and confirm World turn and TurnRun status both advanced.

## Success, failure, and retry

- Exit code `0`: the dry run completed, or a complete production pipeline committed.
- Non-zero: the World was missing, a lock/idempotency guard rejected execution, the scaffold is incomplete, or execution failed.
- A duplicate host trigger returns quickly because `flock` may reject it; even without `flock`, the PostgreSQL advisory lock rejects overlap.
- On a Laravel failure, game state and `current_turn` roll back. The run history remains `failed` with bounded failure information.
- `source=cron` does not retry an existing `failed` or `blocked` TurnRun. It exits non-zero without changing the run status, attempt count, ruleset, or saved seed.
- Inspect the non-zero exit, application log, and `hakoniwa:turn:status`. Fix the cause, then explicitly retry as the operator:

  ```console
  php artisan hakoniwa:turn:run \
    --world=shared-world \
    --source=manual
  ```

- Before every deploy, run `hakoniwa:release:preflight`. A pending, running, or failed next production TurnRun blocks deploy and must be explicitly resolved. Never carry an automatic retry across a release.

If a command is interrupted after the database connection closes, the session advisory lock is released by PostgreSQL; the run record may still require operator diagnosis. Stale-run recovery, retry backoff and limits, and external notification are post-release work, not shell logic.
