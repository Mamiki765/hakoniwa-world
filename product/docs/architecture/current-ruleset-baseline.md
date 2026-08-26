# Current Ruleset baseline

## Scope

The normal application configuration resolves only the current immutable Ruleset,
`hakoniwa-2s-plus-v16`. Its thin entrypoint explicitly composes the complete payload from
domain-first current authoring and does not execute any historical Ruleset source. Its
identity and formal checksum remain:

```text
key: hakoniwa-2s-plus-v16
version: 16
checksum: 331d2d0e9456fa87a37ea0765313ecd9828b5d4912fa2b6637620806df80487d
```

v16 is the final ver 2.6.0 oil-resource and trading-post payload and remains the current
payload for application ver 2.6.1. It adds `oil` as a normal tradable Nation
resource measured in ten-thousand-barrel units, with an initial balance of zero, a capacity
of 5,000 units, a sale rate of 1 unit to 2 internal money units, and the ordinary default
`stockpile` sale policy. A seabed oil field now credits 500 oil units per Turn through the
canonical Nation inventory path instead of directly crediting 1,000 money units. Its
depletion probability, depletion result, and drilling contract are unchanged. If cell-phase
production leaves oil above its individual capacity, the capacity phase offers the `stockpile`
overflow to the canonical inventory sale planner before discarding only the unsold remainder.
The same v16 payload authors the player trading-post escrow, 90% seller proceeds
with floor rounding, the `箱庭連合` NPC listing limits and prices, and the Secretary Item
`novice` rarity contract. There is no v17 payload or v16-to-v17 migration in ver 2.6.1.

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
Historical v1-v15 authored Ruleset sources continue to be treated as byte-for-byte
immutable. The current v16 source may be reorganized only when its resolved payload and
formal checksum remain exact; the domain and classification contract is documented in
`ruleset-authoring.md`.

## Dependency boundary

`config/hakoniwa.php` loads only the thin v16 entrypoint and its `current/` domain files.
Current services obtain gameplay from `hakoniwa.ruleset`; Secretary initialization uses that
current v16 payload directly so historical test execution cannot reintroduce an authored v7
runtime dependency.

Historical authored PHP, its catalog/bootstrap, and the v11-to-v16 upgrade services and
migrations are retired from the current tree. Git is the authority for their complete
implementation. The Markdown archive is only a human-readable index and cannot reconstruct
or execute a historical payload. `hakoniwa:ruleset:validate` validates current v16 only.

The exact v10 monster-dispatch duplicate-request compatibility remains in the current request
path. It reads immutable database Ruleset and command-definition snapshots and does not make
normal configuration execute the v10 authored PHP source.

## Retained data and deferred work

Historical RulesetVersion rows, definition rows, Worlds, Nations, cells, resources,
Secretaries, Items, equipment, command/audit/event history, request identity and provenance,
and TurnRun/RNG history remain unchanged. Historical Worlds remain readable and fail closed
for mutation under the existing current-Ruleset guard.

Fresh install loads the canonical final-v16 schema dump, installs/asserts the current
catalogs, and publishes only current v16. The dump includes oil-compatible resource storage,
auction listings/bids, Secretary Item escrow, Secretary profile/image preferences, monster
experience, the KARMA `-30..100` constraint, current indexes, triggers, foreign keys, and the
applied historical migration ledger. It does not replay historical Ruleset PHP or migrations.

The only supported production source for ver 2.6.1 is a database already migrated to final
v16 by ver 2.6.0. Applying the current migration set to that source has no pending migration
and performs no business-data mutation. A v15-or-earlier source must first use the recorded
ver 2.6.0 Git release to migrate to v16, and only then advance to 2.6.1. The current tree does
not directly upgrade it. Historical ledger rows, Ruleset/definition rows, World references,
request provenance/fingerprints, terminal commands, TurnRuns/RNG identity, events/audit,
monster records/statistics, and Secretary/Item records remain readable database data. The
current guard continues to fail closed when a historical World is asked to mutate.
