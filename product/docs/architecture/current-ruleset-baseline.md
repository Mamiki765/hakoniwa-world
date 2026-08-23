# Current Ruleset baseline

## Scope

The normal application configuration resolves only the current immutable Ruleset,
`hakoniwa-2s-plus-v13`. Its standalone authored file contains the complete payload and does
not execute any historical Ruleset source. Its identity and formal checksum remain:

```text
key: hakoniwa-2s-plus-v13
version: 13
checksum: 27c5d58d80e55bf2807cecd147b99b80e57ea0e1afd836eea150982445723b1f
```

v13 is the formal ver 2.4.0 KARMA/recovery gameplay payload. It is published without
rewriting v12, and exact v12-to-v13 conversion preserves TurnRun, RNG, request-key,
fingerprint, terminal-history, Secretary/Item, and live-monster provenance.

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

`config/hakoniwa.php` loads only the standalone v13 file. Current services obtain gameplay
from `hakoniwa.ruleset`; Secretary initialization uses the current v13-derived
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

Fresh install loads the canonical schema dump and publishes only current v13. The immediate
gameplay upgrade boundary accepts only exact v12 after a fail-closed payload, reference,
integrity, Nation-KARMA, and global unresolved-TurnRun preflight. The preceding exact-v11
source rebaseline remains historical upgrade provenance; see `install-upgrade-rebaseline.md`
and `../ver-2.4.0-karma-recovery.md`.
