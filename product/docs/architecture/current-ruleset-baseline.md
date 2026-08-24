# Current Ruleset baseline

## Scope

The normal application configuration resolves only the current immutable Ruleset,
`hakoniwa-2s-plus-v15`. Its standalone authored file contains the complete payload and does
not execute any historical Ruleset source. Its identity and formal checksum remain:

```text
key: hakoniwa-2s-plus-v15
version: 15
checksum: b31c097c89d7f9105c2219aea52a0c65e76d2f8bb5e61cef9c4902375ce2ab0d
```

v15 is the ver 2.5.0-beta monster-damage experience payload. It fixes each monster's
`experience_per_damage`, awards launch-base and Old Bow Secretary experience from actual
damage, and is published without rewriting v14. Historical v14 remains the immutable
Secretary profile/capacity payload with checksum
`af9afe5bf055f4d2ecc4349de058f6dfc6281194dd3d52238167ced07c9d8274`.

| Monster key | EXP per actual damage |
|---|---:|
| `mecha_inora` | 3 |
| `mecha_inora_zero` | 9 |
| `inora` | 4 |
| `sanjira` | 5 |
| `red_inora` | 4 |
| `dark_inora` | 6 |
| `aoi_inora` | 8 |
| `inora_ghost` | 10 |
| `whale` | 5 |
| `king_inora` | 6 |

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

`config/hakoniwa.php` loads only the standalone v15 file. Current services obtain gameplay
from `hakoniwa.ruleset`; Secretary initialization uses the current v15-derived
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

Fresh install loads the canonical schema dump and publishes only current v15. The immediate
gameplay upgrade boundary accepts only exact v14 after a fail-closed payload, reference,
history, integrity, and global unresolved-TurnRun preflight. It adds persistent Secretary
monster experience and monster-definition `experience_per_damage`, backfills only uniquely
attributable historical Old Bow final blows with their historical
`missile_base_experience`, remaps only current queued-command, alive-monster, and kill-stat
references, and changes the World Ruleset reference without resetting other gameplay data.
It does not rescan historical nonlethal Old Bow damage or reconstruct historical launch-base
damage. Every existing `map_cells.facility_experience` value is digested before and after the
upgrade and remains unchanged; actual-damage launch-base experience begins only under v15.
The preceding exact-v11 rebaseline, v11-to-v12 dormancy, v12-to-v13 KARMA, and v13-to-v14
Secretary profile conversions remain historical upgrade provenance; see
`install-upgrade-rebaseline.md`, `../ver-2.4.0-karma-recovery.md`, and
`../ver-2.5.0-secretary-profile.md`.
