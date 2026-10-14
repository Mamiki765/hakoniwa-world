# Installation and baseline adoption

## Current authority

The current installer is `CurrentDatabaseBaseline`, its single install migration,
`database/schema/pgsql-schema.sql`, and the matching complete structure and required-ledger
manifests under `database/baselines/`. The release-specific supported source and evidence are
recorded in [the 5.0.1 rebaseline plan](../plans/5.0.1-ruleset-rebaseline.md).

A repository version or schema dump does not prove production state. Rebaseline requires the
Owner-confirmed production boundary and a reviewed fresh-install/data-preserving adoption
check. Older environments must reach the supported source using their recorded fixed release
code before advancing to this tree. Retired migrations and Ruleset sources remain in Git.

## Fresh installation

Start from an empty PostgreSQL database and run `php artisan migrate --force` in the configured
application environment. Laravel loads the schema-only dump and runs the install marker. The
installer verifies the complete structure and that every business table is empty, then installs
the current catalog, publishes the configured current Ruleset, and installs baseline seeds.

Fresh installation does not recreate retired snapshots or execute completed historical
backfills. World/Nation initialization uses the normal application path after installation.
A ledger-less database containing business rows is refused rather than reset.

## Existing database adoption

Before authorized operational application, verify the backup and recovery procedure in
[PostgreSQL backup and restore](../operations/database-backup-and-restore.md), including
separate filesystem assets that the database backup does not contain.

Run `php artisan hakoniwa:baseline:check` with the candidate code. This command reads the
complete public structure, the required source migration ledger, current catalogs and
published snapshot/definitions, and the shared World's current Ruleset reference. Any mismatch
stops the adoption. Investigate the specific difference rather than modifying the database or
loosening the manifest automatically.

After the read-only check succeeds, the authorized `php artisan migrate --force` runs the
adoption gate transactionally. The existing-database branch does not execute DDL or business
DML; Laravel appends one install marker after success. Previous ledger IDs/batches, snapshot
IDs and definitions, World/queue/run/audit references, assets, progress and sequence values
remain intact. Later runs have no pending baseline work. The check also supports a database
already installed or adopted by the current marker.

Do not load the fresh schema dump into an existing database, delete its migration ledger,
use `migrate:fresh`, or reinterpret existing history under current settings. Additional schemas,
diagnostic views and ownership/ACL are outside the installer's mutation scope. The structure
gate matches full recorded variants rather than normalizing away columns or constraints.

## Recovery and future changes

The baseline migration is forward-only. `down()` refuses to undo it. If an operational check
fails, preserve the database and resolve the cause; recovery uses the reviewed backup contract.
Code rollback after marker adoption requires an explicit release recovery decision.

A later semantic Ruleset change uses the normal immutable snapshot/version and forward
migration contract. Baseline adoption does not reset Ruleset numbering or authorize rewriting
published snapshots. See [current Ruleset baseline](current-ruleset-baseline.md).

The later [public living monster snapshot](public-monster-counts.md) is an independent
forward migration after the install marker. Its completed-schema manifest substitutes only
the measured `worlds` definition when that migration is recorded. The fixed 5.0.1 dump and
adoption structures remain the authority before this extension; all other structure checks
remain exact.

[Ship destination orders](surface-ship-destination-api.md) add a later independent forward
migration. When its marker is recorded, `ship_destination_structure.json` substitutes only
the complete `ships` definition for each accepted baseline variant. It preserves the known
HP CHECK grouping difference; all other objects remain exact. No live production schema was
read or changed to derive this extension.

On isolated PostgreSQL 18.4, the fresh pre-extension `ships` hash matched
`cc8b9c0f3f9207ece5fe5099b66ef81b6dfd632b8e2b5593dc2df73058325e48`.
Reconstructing the fixed ship DDL from `191b8850` and the NPC/ocean additions recorded at
`7c24078b` matched the complete saved production-variant hash
`51dd7a2e9279e4c5c4075486318ec2df236539e9bfe79bd7acad190d45c83257` before any extension.
Applying the actual new migration yielded, respectively,
`5a8d9bb526dd54027814f06fcee0e0a38b0a3a98c88cac98e7a5ce4bdee041cd` and
`a598f8c2fcfd94addb9cde71cb769dadd73829d116a3ca6e259a5eda37275940`.
Acceptance continues to require a complete known structure, including the new columns and
exclusivity constraint; it does not discard or normalize differences.
