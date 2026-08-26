# Current Ruleset baseline

## Scope

The normal application configuration resolves only the current immutable Ruleset,
`hakoniwa-2s-plus-v17`. Its identity and formal checksum are:

```text
key: hakoniwa-2s-plus-v17
version: 17
checksum: 8b0781a52e1d4b534a1e80acca4d63731fc7a80680bf27ea5edcaf1c0233e3b3
```

v17 is the ver 2.7.0 gameplay contract. It adds Regular and Cursed Secretary Items,
versioned monster-drop and bow RNG, fixed-price Item sale, the Collar KARMA/refugee effects,
the demographic Secretary skills, and the settlement-population limits. Regular and Cursed
Items remain player-tradable while `npc_tradable=false`; Old Bow is neither player- nor
NPC-tradable. Trading Post settlement visibility is projected as a winner public event and,
for a player seller, an owner-only private event without changing the existing administrative
`trading_post.sold` audit.

The demographic skills use stable keys `declining_birthrate_policy` and `indomitable`.
`declining_birthrate_policy` uses cumulative, non-consuming population-high-water EXP and is
excluded from the Secretary Suit modifier. `indomitable` uses consuming EXP with remainder
carry and remains eligible for that existing modifier. The persisted Nation
`population_high_water` value is the authoritative all-time-population statistic; it is not
derived from modified skill EXP.

## Immutable v16 boundary

The immediately preceding supported source remains the exact immutable v16 payload:

```text
key: hakoniwa-2s-plus-v16
version: 16
checksum: 331d2d0e9456fa87a37ea0765313ecd9828b5d4912fa2b6637620806df80487d
```

v17 authoring explicitly reuses unchanged v16 domain fragments and replaces the identity-only
`world-and-map` fragment plus `turn-pipeline`, `monsters-and-military`, and `secretary`. It does not mutate the
v16 source or payload and does not introduce recursive inheritance or a dynamic historical
catalog.

Historical v15 remains immutable with formal checksum
`d361856e81bb6fe8752a5f1c448d8cbbdb87b6471d5142b36a06b756923fda70` and authored-file
SHA-256 `4a033f2f0fd2ff3e241162f18842360f133741de07ceb32f9eb65a0e606b4283`.
Historical v14 remains immutable with checksum
`af9afe5bf055f4d2ecc4349de058f6dfc6281194dd3d52238167ced07c9d8274`.

## Dependency boundary

`config/hakoniwa.php` loads only the thin v17 entrypoint. The entrypoint names every final
top-level payload field explicitly, taking unchanged fields from exact v16 and changed fields
from `config/hakoniwa/rulesets/v17/`. Current services obtain gameplay from
`hakoniwa.ruleset`, and `hakoniwa:ruleset:validate` validates current v17 only.

The exact v16 entrypoint is retained only as the immutable supported migration source and
checksum proof. Older authored PHP and its former catalog/bootstrap remain retired; Git and
immutable database snapshots are the authority for unsupported executable history. The
exact v10 monster-dispatch duplicate-request compatibility continues to read persisted
Ruleset and command-definition snapshots rather than historical PHP.

## Installation and forward migration

The schema dump remains the canonical final-v16 dump and is not rewritten for this release.
Fresh installation applies the new forward migration, adds the authoritative
`nations.population_high_water` statistic, publishes v17, and creates no historical Item
backfill.

The only supported production upgrade is exact v16 to v17. The forward-only migration
requires the exact v16 key, version, and checksum, locks the mutable World, rejects an
unresolved next non-dry TurnRun, publishes v17 transactionally, rebinds queued command
definitions, living monsters, and current kill statistics by stable key, adds the two missing
Secretary skill rows, and seeds demographic history only from authoritative current and Turn
summary data. It preserves historical records, request provenance/fingerprints, Secretary,
Item, equipment, and auction data. A rerun against exact current v17 is idempotent; a failure
rolls back publication and all rebind/backfill work.

Historical Worlds and records remain readable and fail closed for mutation under the current
Ruleset guard. A v15-or-earlier database must first use its supported historical release to
reach exact v16; this tree does not directly upgrade it.
