# ver 1.2.0 UTC database session and timestamp repair

The Laravel application remains UTC and now opens every PostgreSQL application connection with a UTC session. The PostgreSQL server, OCI host, cron schedule, and other containers are not changed. Browser-facing dates continue to render in `Asia/Tokyo`.

## Root cause

Laravel serializes application `DateTimeInterface` values as offset-free `Y-m-d H:i:s` bindings. A PostgreSQL session using `Asia/Tokyo` interpreted those UTC wall-clock values as JST when writing `timestamptz`, storing an instant nine hours too early. PostgreSQL `CURRENT_TIMESTAMP` follows different rules and therefore cannot be repaired with the same assumption.

## Forward repair boundary

Migration `2026_08_09_030000_repair_deterministic_application_timestamps.php` repairs only rows whose affected `timestamptz` value exactly equals a same-operation, non-timezone anchor interpreted as legacy JST:

```sql
affected_timestamp = anchor_timestamp AT TIME ZONE 'Asia/Tokyo'
```

The replacement is the same anchor interpreted as UTC. State predicates are applied where appropriate. A queued multi-turn quantity command is included when its latest successful decrement left `execution_completed_at` equal to `updated_at`; completed dry-run TurnRuns are terminal history and are included as well. Rows that do not match exactly are left unchanged, and rerunning the conversion is idempotent.

The migration deliberately does not change announcement timestamps, general TurnRun or command execution start timestamps, or monster kill aggregate timestamps. Those records lack a deterministic in-database anchor or can mix provenance. Repairing them requires an independent reviewed operator conversion backed by an external trusted record; guessing or applying a blanket nine-hour shift is prohibited.

Before deploy, continue to run the production release preflight and resolve any pending, running, failed, or blocked non-dry TurnRun. After migration, verify `SHOW TIME ZONE` returns `UTC` from the web application connection and recheck TOP Turn status.
