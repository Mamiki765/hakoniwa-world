# Current Ruleset baseline

## Scope

The normal application configuration resolves only the current immutable Ruleset,
`hakoniwa-2s-plus-v16`. Its standalone authored file contains the complete payload and does
not execute any historical Ruleset source. Its identity and formal checksum remain:

```text
key: hakoniwa-2s-plus-v16
version: 16
checksum: 9b063ebb9d9b4c1a32c0b723089da7f4830159d9af43df1106d6a42feb6f28e5
```

v16 is the final ver 2.6.0 oil-resource and trading-post payload. It adds `oil` as a normal tradable Nation
resource measured in ten-thousand-barrel units, with an initial balance of zero, a capacity
of 5,000 units, a sale rate of 1 unit to 2 internal money units, and the ordinary default
`stockpile` sale policy. A seabed oil field now credits 500 oil units per Turn through the
canonical Nation inventory path instead of directly crediting 1,000 money units. Its
depletion probability, depletion result, and drilling contract are unchanged. If cell-phase
production leaves oil above its individual capacity, the capacity phase offers the `stockpile`
overflow to the canonical inventory sale planner before discarding only the unsold remainder.
The same unpublished v16 payload authors the player trading-post escrow, 90% seller proceeds
with floor rounding, the `箱庭連合` NPC listing limits and prices, and the Secretary Item
`novice` rarity contract. There is no v17 payload or v16-to-v17 migration in ver 2.6.0.

Historical v15 remains immutable with formal checksum
`d361856e81bb6fe8752a5f1c448d8cbbdb87b6471d5142b36a06b756923fda70` and authored-file
SHA-256 `4a033f2f0fd2ff3e241162f18842360f133741de07ceb32f9eb65a0e606b4283`.
v15 is the ver 2.5.0-beta monster-damage experience and Secretary forest-management payload. It fixes each monster's
`experience_per_damage`, awards launch-base and Old Bow Secretary experience from actual
damage, adds the fifth passive skill `forest_management`, and is published without rewriting v14. Historical v14 remains the immutable
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

`config/hakoniwa.php` loads only the standalone v16 file. Current services obtain gameplay
from `hakoniwa.ruleset`; Secretary initialization uses that current v16 payload directly so
historical test execution cannot reintroduce an authored v7 runtime dependency.

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

Fresh install loads the canonical schema dump and publishes only current v16, including the
oil definition, trading-post listing/bid escrow schema, Secretary Item escrow state, and zero oil balance plus the ordinary default sale policy for every new
Nation. The immediate gameplay upgrade boundary accepts only exact v15 after a fail-closed
payload, reference, history, integrity, and global unresolved-TurnRun preflight. It adds oil
balance zero and the ordinary default policy for each existing Nation, preserves every
existing resource balance and sale policy, remaps current queued-command, alive-monster, and
kill-stat references, and advances the production World to v16 in one forward-only
transaction. The preceding v14-to-v15 boundary accepts exact v14 after the same class of
fail-closed payload, reference,
history, integrity, and global unresolved-TurnRun preflight. It adds persistent Secretary
monster experience and monster-definition `experience_per_damage`, backfills only uniquely
attributable historical Old Bow final blows with their historical
`missile_base_experience`, remaps only current queued-command, alive-monster, and kill-stat
references, and changes the World Ruleset reference without resetting other gameplay data.
The same v14-to-v15 transaction adds only the missing `forest_management` row at Lv0/EXP0
for every existing Secretary, preserves all four legacy skill rows, and performs no historical
logging or planting EXP backfill. Fresh Secretaries start with all five skills. Successful
logging and planting each award one forest-management EXP; logging income and the Ruleset
forest `growth_increment` use `floor(base * (100 + Lv) / 100)` before their existing caps.
It does not rescan historical nonlethal Old Bow damage or reconstruct historical launch-base
damage. Every existing `map_cells.facility_experience` value is digested before and after the
upgrade and remains unchanged; actual-damage launch-base experience begins only under v15.
The preceding exact-v11 rebaseline, v11-to-v12 dormancy, v12-to-v13 KARMA, and v13-to-v14
Secretary profile conversions remain historical upgrade provenance; see
`install-upgrade-rebaseline.md`, `../ver-2.4.0-karma-recovery.md`, and
`../ver-2.5.0-secretary-profile.md`.
