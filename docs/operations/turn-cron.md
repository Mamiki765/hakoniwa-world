# Turn cron operations

## Adopted deployment shape

The OCI host cron remains a thin production trigger. In 5.8.0 the proposed registration polls once per minute; the application preserves regular Asia/Tokyo even-hour slots and checks explicit reserved additional slots. The shell wrapper contains no game rules, ruleset selection, transaction control, or retry loop. This change documents registration only; it does not install or run production cron.

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

## Minute polling example (5.8.0)

Replace the old even-hour registration with this example only during an approved deployment. The application uses Asia/Tokyo independently of the host timezone:

```cron
CRON_TZ=Asia/Tokyo
* * * * * /usr/bin/flock -n /run/lock/hakoniwa-shared-world-turn.lock /usr/bin/env HAKONIWA_PROJECT_DIR=/opt/hakoniwa-world HAKONIWA_WORLD_KEY=shared-world /opt/hakoniwa-world/product/docker/cron/run-turn.sh >> /var/log/hakoniwa-turn.log 2>&1
```

This polls 1,440 times per day. Regular slots remain JST 00:00, 02:00, ..., 22:00; explicit additional slots are read from persistent reservations. An empty poll exits successfully without inserting/updating game state, TurnRun, execution slots or the compensation cursor. It still boots the application, reads database state, acquires/releases the World advisory lock and emits a host log line. Cost must be measured in the intended environment.

The public next-time API and command use the same due decision. Grace is bounded by the configured `HAKONIWA_TURN_SCHEDULE_GRACE_MINUTES` (default 15), the additional interval, the next regular slot and event end. See [persistent slot operations](../../product/docs/operations/turn-scheduler-worker.md) for exact boundaries, missed-slot policy and deferred long-stop decisions. The web process never reads the host crontab directly.

The log destination is owned by the host operator. Laravel also emits command failures through its configured application log. Do not log environment dumps, database URLs, or credentials.

## Daily Underground battle-log prune

Undergroundの1時間retentionを越えた詳細battle action logは、Turnと同じ既存OCI host cron thin-trigger patternから1日1回cleanupする。期限判定はapplicationが個別詳細を返す時にも適用するため、物理削除前でも1時間を越えたdetailは公開しない。この変更でproduction cron登録を追加・変更せず、Laravel schedulerやcron daemonもapplication containerへ追加しない。checked-in wrapperは既存`hakoniwa-web` container内の`underground:prune-battle-logs`を実行する。

```cron
CRON_TZ=Asia/Tokyo
15 3 * * * /usr/bin/flock -n /run/lock/hakoniwa-underground-log-prune.lock /usr/bin/env HAKONIWA_PROJECT_DIR=/opt/hakoniwa-world /opt/hakoniwa-world/product/docker/cron/prune-underground-battle-logs.sh >> /var/log/hakoniwa-underground-log-prune.log 2>&1
```

このjobは`expires_at <= now`の`underground_battle_logs`と期限切れbattle image参照をbounded batchで整理する。`underground_battles`のcompact record、damage/recovery summary、request idempotency identityは保持し、期限後もcompact summaryを表示できる。03:15 JSTの独立jobであり、Turn command、TurnRun、World lock、failed/blocked Turnのmanual retry contractには触れない。非ゼロ終了時は同じcommandをoperatorが原因確認後に明示実行し、shell側でretry loopを作らない。

## Maria achievement sync（導入時のみ）

実績連携も既存のhost cron方式を使う。Laravel scheduler・新しいscheduler containerは追加しない。`product/docker/cron/sync-maria-achievements.sh` は `hakoniwa-web` 内の `hakoniwa:sync-maria-achievements` を `www-data` で呼ぶだけで、取得・Turn transactionの外で送信する。wrapperの `HAKONIWA_PROJECT_DIR` は実際のCompose directoryに合わせる。

Ownerが導入を決めた時だけ、Mariaの実行envへ `HAKONIWA_LINK_SECRET`、Worldのroot `.env` へ同じ専用値を `MARIA_ACHIEVEMENTS_TOKEN` として配置し、`MARIA_ACHIEVEMENTS_URL` に内部受信URLを設定する。checked-in `compose.yml` はこの2変数を `hakoniwa-web.environment` へ転送する。本番が別Compose fileを使う場合、operatorが同じ2変数を実serviceへ渡す。root `.env` の変更は既存containerへ自動反映されないため、通常の導入手順でWeb containerを再作成する。秘密値はcron・コマンド引数・logへ置かず、環境全体を出力して確認しない。

設定とmigration・Maria更新後、hostに次の独立jobを登録する例。実際のdirectory・wrapper pathはoperatorが合わせる。今回、実cron登録・設定・試験送信は行っていない。

```cron
* * * * * /usr/bin/flock -n /run/lock/hakoniwa-maria-achievements.lock /usr/bin/env HAKONIWA_PROJECT_DIR=/opt/hakoniwa-world /opt/hakoniwa-world/product/docker/cron/sync-maria-achievements.sh >> /var/log/hakoniwa-maria-achievements.log 2>&1
```

一回最大25件で、失敗した取得行は未送信のまま残し、次回に再試行する。`flock` は同じhost上での重複起動を避ける。送信先やtokenが未設定ならcommandは何も送らない。既存のTurn cronとretry contractは変更しない。

## Receipt retention preview

`product/docker/cron/preview-underground-receipts.sh`は、既存host/Compose経路で`hakoniwa:underground:receipts:purge --batch=100`を`www-data`として呼ぶread-only reminderである。引数を受け取らず、`--apply`を転送しない。設定例を用意しただけで、本番cronに現在登録済みとは判断しない。

```sh
HAKONIWA_PROJECT_DIR=/opt/hakoniwa-world \
/opt/hakoniwa-world/product/docker/cron/preview-underground-receipts.sh
```

定期登録の有無・頻度はoperatorが確認する。receiptの集約→検証→手動purge、戦闘snapshot縮約、audit compactionは[保持整理runbook](../../product/docs/operations/receipt-audit-retention.md)に従う。詳細battle logの期限削除とは別であり、このwrapperに自動DELETE・retry loopを足さない。

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

- Exit code `0`: no slot was due and no game state changed, the dry run completed, or a complete pipeline committed.
- Non-zero: the World was missing, a lock/idempotency guard rejected execution, the scaffold is incomplete, or execution failed.
- A duplicate host trigger returns quickly because `flock` may reject it; even without `flock`, the PostgreSQL advisory lock rejects overlap.
- 4.4.0 C1 retries only transaction-body PostgreSQL `40P01` / `40001` failures, at most three attempts including the first, within the same invocation. It requires a completed root rollback on the same connection/session. The World lock, run ID, target, ruleset and seed remain unchanged; the whole pipeline starts again with fresh attempt state. This applies to manual and cron invocations, not the shell wrapper.
- Commit/after-commit failures, connection loss, caller-owned outer transactions, pipeline blocks and other SQLSTATEs are not automatically retried. A completed run is not relabelled failed by an after-commit exception.
- `attempt_count` counts execution attempts, including C1 retries. Bounded `failure_context.transient_retries` records the prior attempt number, phase and SQLSTATE for this invocation, including when recovery succeeds. On terminal failure `phase`, `exception_class`, `sqlstate` and `attempts_this_invocation` describe the final failure. Success still has status `completed` and null `failure_code` / `failure_message`; historical retry diagnostics do not mean the turn is failed.
- On a transaction-body failure, game state and `current_turn` roll back. If retries are exhausted or the failure is ineligible, the run remains `failed` with bounded failure information. Inspect commit/connection failures rather than assuming rollback from the command exit code alone.
- `source=cron` still does not reopen an existing `failed` or `blocked` TurnRun on a later invocation. It exits non-zero without changing the run status, attempt count, ruleset, or saved seed. C1 does not implement C2 or eliminate operator intervention after three unsuccessful attempts.
- Inspect the non-zero exit, application log, and `hakoniwa:turn:status`. Fix the cause, then explicitly retry as the operator:

  ```console
  php artisan hakoniwa:turn:run \
    --world=shared-world \
    --source=manual
  ```

- Before every deploy, run `hakoniwa:release:preflight`. A pending, running, or failed next production TurnRun blocks deploy and must be explicitly resolved. Never carry an automatic retry across a release.

If a command is interrupted after the database connection closes, the session advisory lock is released by PostgreSQL; the run record may still require operator diagnosis. Stale-run recovery, cross-invocation retry, retry backoff and external notification remain later work, not shell logic. The current in-process retry boundary is recorded in [current contracts](../../product/docs/handoffs/current-contracts.md).
