# Current Ruleset baseline

## Scope

The normal application configuration resolves only the current immutable Ruleset,
`hakoniwa-2s-plus-v14`. Its standalone authored file contains the complete payload and does
not execute any historical Ruleset source. Its identity and formal checksum remain:

```text
key: hakoniwa-2s-plus-v14
version: 14
checksum: af9afe5bf055f4d2ecc4349de058f6dfc6281194dd3d52238167ced07c9d8274
```

v14 is the formal ver 2.5.0 Secretary-level capacity payload. It is published without
rewriting v13. Exact v13-to-v14 conversion preserves TurnRun, RNG, request-key, fingerprint,
terminal-history, User/Secretary/profile/Item, and live-monster provenance. Historical v13
remains the immutable ver 2.4.0 KARMA/recovery payload with checksum
`27c5d58d80e55bf2807cecd147b99b80e57ea0e1afd836eea150982445723b1f`.

## One-time source representation rebaseline

The earlier ver 2.4.0 source-dependency rebaseline made one explicit exception to
authored-source byte immutability: the then-current `hakoniwa-2s-plus-v11` PHP source was
mechanically rewritten from the historical
inheritance/delta representation into a standalone representation. The rewrite was accepted
only after strict resolved-array equality and an unchanged formal checksum were demonstrated.

This exception is limited to source representation. The v11 key/version, resolved payload,
formal checksum, published RulesetVersion row and definitions, gameplay, and balance remain
immutable. The former v11 source representation remains recoverable from Git history. This
one-time exception does not permit future semantic changes under the v11 identity; any future
gameplay or balance change requires a new unique Ruleset version and immutable publication.
Historical authored Ruleset sources continue to be treated as byte-for-byte immutable.

## Dependency boundary

`config/hakoniwa.php` loads only the standalone v14 file. Current services obtain gameplay
from `hakoniwa.ruleset`; Secretary initialization uses the current v14-derived
`hakoniwa.current_catalogs.secretary` snapshot so historical test execution cannot reintroduce
an authored v7 runtime dependency.

Historical authored files remain available through `RulesetUpgradeAuthoringCatalog` for three
explicit consumers:

- the historical migration chain, installed only when Laravel emits `MigrationsStarted`;
- historical migration/contract tests that opt in through an explicit database-fixture
  concern; and
- the operator validation command, which reads the catalog directly without installing it
  into normal application configuration.

The exact v10 monster-dispatch duplicate-request compatibility remains in the current request
path. It reads immutable database Ruleset and command-definition snapshots and does not make
normal configuration execute the v10 authored PHP source.

## Retained data and deferred work

Historical RulesetVersion rows, definition rows, Worlds, Nations, cells, resources,
Secretaries, Items, equipment, command/audit/event history, request identity and provenance,
and TurnRun/RNG history remain unchanged. Historical Worlds remain readable and fail closed
for mutation under the existing current-Ruleset guard.

Fresh install loads the canonical schema dump and publishes only current v14. The immediate
gameplay upgrade boundary accepts only exact v13 after a fail-closed payload, reference,
integrity, and global unresolved-TurnRun preflight. It adds the Secretary profile/User
preference schema, remaps current queued-command and live-monster references, and changes the
World Ruleset reference without resetting gameplay data. The preceding exact-v11 rebaseline,
v11-to-v12 dormancy, and v12-to-v13 KARMA conversions remain historical upgrade provenance;
see `install-upgrade-rebaseline.md`, `../ver-2.4.0-karma-recovery.md`, and
`../ver-2.5.0-secretary-profile.md`.
