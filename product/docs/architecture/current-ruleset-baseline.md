# Current Ruleset baseline

## Scope

The normal application configuration resolves only the current immutable Ruleset,
`hakoniwa-2s-plus-v11`. Its standalone authored file contains the complete payload and does
not execute any historical Ruleset source. Its identity and formal checksum remain:

```text
key: hakoniwa-2s-plus-v11
version: 11
checksum: 5c65c49ed3fd623375f004815ec6bba0b2f67524f61f0638c6fe528fe9599db8
```

This is a source-dependency baseline only. It does not publish a new Ruleset, change balance,
rewrite an immutable database row, migrate player data, or change TurnRun, RNG, request-key,
fingerprint, or provenance semantics.

## One-time source representation rebaseline

The ver 2.4.0 rebaseline makes one explicit exception to authored-source byte immutability:
the current `hakoniwa-2s-plus-v11` PHP source is mechanically rewritten from the historical
inheritance/delta representation into a standalone representation. The rewrite was accepted
only after strict resolved-array equality and an unchanged formal checksum were demonstrated.

This exception is limited to source representation. The v11 key/version, resolved payload,
formal checksum, published RulesetVersion row and definitions, gameplay, and balance remain
immutable. The former v11 source representation remains recoverable from Git history. This
one-time exception does not permit future semantic changes under the v11 identity; any future
gameplay or balance change requires a new unique Ruleset version and immutable publication.
Historical authored Ruleset sources continue to be treated as byte-for-byte immutable.

## Dependency boundary

`config/hakoniwa.php` loads only the standalone v11 file. Current services obtain gameplay
from `hakoniwa.ruleset`; Secretary initialization uses the current v11-derived
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

Fresh install now loads the canonical schema dump and publishes only current v11. The direct
production upgrade boundary accepts only the operator-declared 2.3.1/exact-v11 source after a
fail-closed source and global unresolved-TurnRun preflight. Historical migration removal and
historical compatibility/test retirement remain separate follow-up work; see
`install-upgrade-rebaseline.md`.
