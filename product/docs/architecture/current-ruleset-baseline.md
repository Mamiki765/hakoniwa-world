# Current Ruleset baseline

## Scope

The normal application configuration resolves only the current immutable Ruleset,
`hakoniwa-2s-plus-v18`. Its identity and formal checksum are:

```text
key: hakoniwa-2s-plus-v18
version: 18
checksum: 40bb900705776bf82e69e11b4f6f9aeed433988599aa0690cfd6088964e16f8b
```

v18 is the first ver 2.8.0 gameplay contract. It adds `undersea_city`, its disguised sea
presentation, atomic capital-population transfer, canonical settlement growth and food use,
refugee exclusion, deterministic all-or-nothing industrial-goods/mineral maintenance,
famine-equivalent loss and minimum-population removal, fire and seabed-disaster behavior,
land-destruction compatibility, resource forecast consumption, and the +3/+1 KARMA ledger
entries. It does not add ships, vision, treasure, Artifacts, or fire-mitigation Items.

## Immutable v17 boundary

The immediately preceding supported source remains the exact immutable v17 payload:

```text
key: hakoniwa-2s-plus-v17
version: 17
checksum: 8b0781a52e1d4b534a1e80acca4d63731fc7a80680bf27ea5edcaf1c0233e3b3
```

v18 authoring explicitly reuses unchanged v17 fields and replaces only its bounded changed
domains. It does not mutate the v17 source or payload and does not introduce recursive
inheritance or a dynamic historical catalog.

Historical v15 remains immutable with formal checksum
`d361856e81bb6fe8752a5f1c448d8cbbdb87b6471d5142b36a06b756923fda70` and authored-file
SHA-256 `4a033f2f0fd2ff3e241162f18842360f133741de07ceb32f9eb65a0e606b4283`.
Historical v14 remains immutable with checksum
`af9afe5bf055f4d2ecc4349de058f6dfc6281194dd3d52238167ced07c9d8274`.

## Dependency boundary

`config/hakoniwa.php` loads only the thin v18 entrypoint. The entrypoint names every final
top-level payload field explicitly, taking unchanged fields from exact v17 and changed fields
from `config/hakoniwa/rulesets/v18/`. Current services obtain gameplay from
`hakoniwa.ruleset`, and `hakoniwa:ruleset:validate` validates current v18 only.

The exact v17 entrypoint is retained only as the immutable supported migration source and
checksum proof. Older authored PHP and its former catalog/bootstrap remain retired; Git and
immutable database snapshots are the authority for unsupported executable history. The
exact v10 monster-dispatch duplicate-request compatibility continues to read persisted
Ruleset and command-definition snapshots rather than historical PHP.

## Installation and forward migration

The schema dump is the canonical final 3.0.0 schema and migration ledger. A fresh install
loads that schema directly, then current bootstrap installs and publishes only immutable v18.

The only supported production application upgrade is exact ver 2.8.0 / v18 to 3.0.0. The
forward-only 3.0.0 migration rejects an absent v18 ledger, any retired Underground alpha
migration ledger, or any pre-existing Underground table. It verifies the current immutable
v18 state without changing its payload, then creates the final Underground profile,
intro/progression, battle/history, STP/SP/Skill Tree, equipment ownership, request-idempotency,
constraint, index, and foreign-key schema directly. Existing World, Nation, player, command,
Turn, event, audit, and surface Ruleset data are not reset or reinterpreted.

The ten Underground migrations authored during the 3.0.0-alpha branch were never deployed to
production and are retired from the final release. Their final schema is represented by the
single 3.0.0 upgrade migration and the current schema dump; alpha database state is not a
supported production source.

Historical Worlds and records remain readable and fail closed for mutation under the current
Ruleset guard. A v17-or-earlier database must first use ver 2.8.0 to reach exact v18; this tree
does not directly upgrade it.
