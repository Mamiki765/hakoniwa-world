# ver 2.4.0 install and upgrade rebaseline

> This document records the first ver 2.4.0 exact-v11 rebaseline and historical-retirement
> boundary. The completed application subsequently publishes immutable v12 for dormancy and
> immutable v13 for KARMA/recovery. Fresh install now publishes only v13; the immediate
> supported gameplay transition is exact v12 to v13. See
> `product/docs/ver-2.4.0-karma-recovery.md`.
>
> **Current ver 2.6.1 boundary:** this document is historical architecture. The supported
> production source is already-final-v16. To upgrade v15 or earlier, check out ver 2.6.0,
> complete its supported migration to v16, then advance to 2.6.1. The current tree does not
> execute the historical authored PHP or replay the retired v11-to-v16 migration chain.

## Historical v11 rebaseline identity

The first ver 2.4.0 rebaseline kept the existing Ruleset identity unchanged:

```text
key: hakoniwa-2s-plus-v11
version: 11
checksum: 5c65c49ed3fd623375f004815ec6bba0b2f67524f61f0638c6fe528fe9599db8
```

That rebaseline changed installation and upgrade routing only. It did not create v12,
change gameplay or balance, rewrite a published payload, or regenerate request fingerprints.

## Historical two-path rebaseline

At that boundary, fresh installation and production upgrade stopped sharing an obligation to
execute the complete historical migration chain.

### Fresh installation

Laravel loads `database/schema/pgsql-schema.sql`, which is a schema-only PostgreSQL dump of
the current schema plus the historical migration ledger. It therefore creates the same
tables, columns, indexes, foreign keys, checks, unique constraints, and triggers as the full
chain while marking those historical migrations as already applied. Only the subsequent
ver 2.4.0 rebaseline migration executes.

On an otherwise empty business schema that migration installs the current terrain,
facility, resource, and monument catalogs, then publishes exact v11 and its current command,
production, and monster definitions. Historical roadmap/formal Ruleset publications and
repair migrations are not executed and their domain rows are intentionally absent. They are
past audit publications, not current operational defaults for a World that has never run.

The application image and PHPUnit CI install the PostgreSQL client required by Laravel's
standard schema loader. Normal `config/hakoniwa.php` remains v11-only.

### Existing production upgrade

The only supported direct application source is the operator contract **2.3.1 with exact
v11**. The database has no authoritative application-version field, so ver 2.4.0 does not
invent one. Operators must first run the 2.3.1 application to a clean exact-v11 state,
complete an official Turn, and then cross this release boundary.

The database preflight proves the supported state from existing evidence:

- the exact v11 publication migration is present;
- exact v11 payload, definitions, formal checksum, and current global catalogs match;
- exactly one `shared-world` exists and references v11;
- queued commands, alive monsters, and current kill statistics reference v11 definitions;
- queue request scalar shape, provenance, fingerprints, and stored parameters are complete
  and internally consistent;
- legacy monster-cycle seed requirements are complete;
- Secretary skills and Item keys/levels satisfy the current contract; and
- no unresolved non-dry TurnRun exists globally.

The preflight locks business tables in deterministic order and runs in the migration
transaction. An unsupported source throws before application data mutation; Laravel records
the migration only after successful completion. For an existing supported database the
rebaseline performs no World, Nation, cell, resource, Secretary, Item, command, definition,
audit/event, TurnRun, or RNG-history write.

## TurnRun cutoff semantics

The global cutoff treats non-dry `pending`, `running`, `failed`, and `blocked` rows as
unresolved. Dry-run history and completed production rows do not block.

This does not make every past failure permanently blocking. Current `TurnRunner` manual retry
reuses a failed/blocked row only for the same World, target turn, Ruleset, and seed, then
updates that same row to `completed` after success. A row still carrying one of the four
statuses is therefore unresolved at the release boundary and must be resolved by 2.3.1
before upgrade.

## Preservation and recovery

Successful upgrade preserves all existing business and audit bytes. In particular,
`request_key`, `request_fingerprint`, request provenance, terminal command history, Ruleset
and definition history, TurnRun rows, and RNG seeds are neither regenerated nor rebound.

The migration is forward-only. Recovery is:

```text
verified pre-upgrade backup
-> restore the supported 2.3.1/v11 database
-> resolve the failed preflight or migration cause
-> perform the forward upgrade again
```

Running the old application in place against the migrated database and a complete `down()`
path are not supported. The backup and restore procedure remains the contract in
`docs/operations/database-backup-and-restore.md`.

## Current responsibilities after ver 2.6.1 Stage 2

Historical authored PHP, `RulesetUpgradeAuthoringCatalog`, migration-only runtime services,
and the remaining v11-to-v16 Ruleset publication/upgrade migrations are retired. Their exact
implementation remains available only from the recorded Git commits. The current operator
validator and all normal/test bootstrap paths load current v16 only.

The schema dump contains the final-v16 schema and the applied historical migration ledger,
so a fresh installation does not rediscover or replay the retired files. Existing production
must already be at final v16; applying 2.6.1 is a migration no-op for business data.

## PR D retirement result

The historical-retirement PR removed:

- roadmap/formal publication replay and historical repair/data-conversion migrations;
- direct v2-v10 and roadmap-snapshot conversion services;
- historical migration/idempotency suites and obsolete release checkpoint contracts; and
- normal-test bootstrap that published all 21 authored Rulesets for every fixture.

It retained at that earlier stage:

- immutable historical database rows and definition links;
- historical World read presentation and current mutation fail-closed tests; and
- exact current fresh-install/source-upgrade, checksum, fingerprint, provenance, TurnRun,
  lock, transaction, and integrity coverage.

Stage 2 subsequently retired the historical authored PHP and catalog as listed in
`ruleset-runtime-retirement.md`. Git, not the current application or Markdown, is the
authority for the old executable implementation.
