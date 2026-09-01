# Current Ruleset baseline

## Scope

The normal application configuration resolves only the current in-development Ruleset for
release/3.1.0, `hakoniwa-2s-plus-v19`. Its identity and current formal checksum are:

```text
key: hakoniwa-2s-plus-v19
version: 19
checksum: b65752b88e9daf3c9b64e6d28b72847315d521dfe65b704f4cd8fd622e1368c9
```

v19 is the ver 3.1.0 contract under construction. It adds the turn-free `territory_abandon`
command for safe empty owned cells, moves `build_undersea_city` to player-facing sort order
125, and versions the isolated Underground facility/command definitions and their ongoing
effects. Underground unlock entitlement remains Secretary-owned, constructed facilities are
Nation-owned, and neither is represented as a surface Ruleset map cell. v19 may be refined as
part of completing release/3.1.0; it freezes when that release reaches main/production.

## Immutable v18 boundary

The immediately preceding supported source remains the exact immutable v18 payload:

```text
key: hakoniwa-2s-plus-v18
version: 18
checksum: 40bb900705776bf82e69e11b4f6f9aeed433988599aa0690cfd6088964e16f8b
```

v19 authoring explicitly reuses unchanged v18 fields and replaces only its bounded changed
domains. It does not mutate the v18 source or payload and does not introduce recursive
inheritance or a dynamic historical catalog.

Historical v15 remains immutable with formal checksum
`d361856e81bb6fe8752a5f1c448d8cbbdb87b6471d5142b36a06b756923fda70` and authored-file
SHA-256 `4a033f2f0fd2ff3e241162f18842360f133741de07ceb32f9eb65a0e606b4283`.
Historical v14 remains immutable with checksum
`af9afe5bf055f4d2ecc4349de058f6dfc6281194dd3d52238167ced07c9d8274`.

## Dependency boundary

`config/hakoniwa.php` loads only the thin v19 entrypoint. The entrypoint takes unchanged fields
from exact v18 and replaces identity, the Surface commands domain, and the separate
`underground_facility_development` domain from `config/hakoniwa/rulesets/v19/`. Underground
commands do not enter Surface `command_definitions`. Current services obtain gameplay from
`hakoniwa.ruleset`, and `hakoniwa:ruleset:validate` validates current v19 only.

The exact v18 entrypoint is retained as the immutable supported migration source and
checksum proof. Older authored PHP and its former catalog/bootstrap remain retired; Git and
immutable database snapshots are the authority for unsupported executable history. The
exact v10 monster-dispatch duplicate-request compatibility continues to read persisted
Ruleset and command-definition snapshots rather than historical PHP.

## Installation and forward migration

The schema dump is the canonical final 3.0.0 schema and migration ledger. A fresh install
loads that schema directly, then the forward 3.1.0 migrations publish current v19 and create
the Nation-owned Underground facility schema with a non-null `ruleset_version_id`; no
Underground or 3D map schema is created. The exact v18 source remains in code as the immutable
supported upgrade input; a fresh database does not need a historical v18 catalog row.

The supported production application upgrade from ver 2.8.0 / v18 to 3.0.0 uses the
forward-only 3.0.0 migration. It rejects an absent v18 ledger, any retired Underground alpha
migration ledger, or any pre-existing Underground table. It verifies immutable v18 without
changing its payload, then creates the final Underground profile, intro/progression,
battle/history, STP/SP/Skill Tree, equipment ownership, request-idempotency, constraint,
index, and foreign-key schema directly. Existing World, Nation, player, command, Turn,
event, audit, and surface Ruleset data are not reset or reinterpreted.

The supported 3.1.0 gameplay upgrade is exact v18 to v19. It rebinds current queued commands
and live definition references by stable key, switches the locked World atomically, and
reconciles the development-only Trial 1 inconsistency by raising only profiles with a first
clear and zero unlocked layers to layer 1. Existing values above layer 1 are never reduced,
and historical commands, events, turns, battles, and Ruleset snapshots are preserved.
The subsequent development-only forward migration upgrades the earlier PR116 facility schema
and v19 payload by backfilling each facility row to the exact v19 definition identity. Queued
Underground commands continue to resolve through their existing request Ruleset provenance,
so future definitions cannot reinterpret an existing queue item or constructed facility.

The ten Underground migrations authored during the 3.0.0-alpha branch were never deployed to
production and are retired from the final release. Their final schema is represented by the
single 3.0.0 upgrade migration and the current schema dump; alpha database state is not a
supported production source.

Historical Worlds and records remain readable and fail closed for mutation under the current
Ruleset guard. A v17-or-earlier database must first use ver 2.8.0 to reach exact v18; this tree
does not directly upgrade it.
