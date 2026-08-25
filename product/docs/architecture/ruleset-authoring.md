# Current Ruleset authoring

## Purpose and authority

The current application authors one canonical runtime Ruleset: `hakoniwa-2s-plus-v16`.
The thin entrypoint at `config/hakoniwa/rulesets/hakoniwa-2s-plus-v16.php` composes explicit
domain payloads from `config/hakoniwa/rulesets/current/`. It does not load a historical or
roadmap Ruleset and it does not introduce a second runtime schema.

Git is the complete implementation history. The Markdown archive under
`docs/archive/rulesets/` is a human-readable index to that history; it is not sufficient to
reconstruct or execute a historical payload. A historical implementation must be inspected
at the archive's recorded Git reference.

## Domain-first layout

Authoring is split by the subsystem a maintainer is investigating, not into three global
classification files:

- `world-and-map.php`
- `lifecycle-and-karma.php`
- `economy-and-resources.php`
- `terrain-and-disasters.php`
- `facilities.php`
- `commands-and-production.php`
- `turn-pipeline.php`
- `monsters-and-military.php`
- `secretary.php`
- `trading-post.php`

Each domain returns a `payload` fragment and adjacent `behavior`, `data`, and `flavor`
classification selectors. The entrypoint names every final top-level field explicitly.
`turn_processing` is also assembled explicitly where its concerns cross the turn-pipeline
and disaster domains. There is no recursive merge, implicit flattening, reflection, or
dynamic authoring path used to produce the runtime payload.

## Classification contract

The three classifications are exhaustive. A fourth implicit classification is not allowed.

### Behavior

Behavior selects or identifies how the application acts. It includes phase order, timing,
eligibility, target selection, state transitions, transaction and retry semantics, snapshot
timing, RNG stream identity/version, selectors, policy/effect/command types, stable keys,
ordering identities, spawn and movement methods, settlement order, and cap application
order. A value that looks like a display label but is used as an identity or selector is
behavior.

Examples include `command_definitions.*.key`, `execution_phase`,
`turn_resolution.normal_monster_stage`, `random_stream_version`, `effect.type`, and
`seller_proceeds_rounding`.

### Data

Data is the value supplied to an unchanged algorithm. It includes probabilities, HP,
wreckage value, experience and `experience_per_damage`, prices, costs, capacities,
production amounts, durations, rates, item levels and per-level amounts, spawn thresholds,
movement counts, and inventory/equipment limits.

Examples include `monster_definitions.*.base_hp`, `cost_money`,
`chance_basis_points`, `bonus_money_per_level`, and resource capacities. A numeric effect
amount is data even though changing it would change the result.

### Flavor

Flavor can change without changing gameplay results, database state, target selection, or
RNG results. It includes names, descriptions, manual text, player-facing labels, unit labels,
and asset keys used only for presentation.

Examples include `name`, `description`, `unit_label`, `asset_key`, and monster manual
`appearance`/`special` text.

## Decision procedure

Classify a leaf in this order:

1. If changing it changes a path, order, target, state transition, identity, selector, or
   interpretation rule, classify it as behavior.
2. If the algorithm is unchanged and only a numeric input changes, classify it as data.
3. If gameplay, persistence, targeting, and RNG results are unchanged, classify it as
   flavor.
4. If evidence from runtime use, tests, decision records, and Git history does not resolve
   the choice, stop authoring and request an Owner decision. Do not invent another category.

A nullable numeric field may require leaf-level judgment. For example, a numeric natural
spawn tier is data, while `null` means that a monster is not eligible for that spawn path and
therefore expresses behavior.

## Mechanical validation

`CurrentRulesetAuthoringInspector` is inspection-only. It flattens domain and final payload
leaves for comparison but never composes runtime settings. A classification selector is
either a field name or an absolute JSON-pointer-like path where `*` matches one list index.
The inspector rejects:

- a domain with keys other than `payload` and `classification`;
- a classification other than behavior, data, or flavor;
- an unclassified leaf;
- a leaf matched by more than one classification;
- a leaf authored by more than one domain;
- an unused classification selector; or
- any domain leaf/value/type that differs from the final payload.

The immutable checksum contract separately proves top-level and nested keys, ordering,
associative/list shape, scalar types, nulls, and values. Classification metadata is authoring
metadata and must never appear in the published runtime payload.

## Change discipline

The v16 key, version, full payload, and checksum
`331d2d0e9456fa87a37ea0765313ecd9828b5d4912fa2b6637620806df80487d`
are immutable. A representation-only refactor must resolve to that exact checksum. A changed
checksum fails closed and requires removal of the unintended semantic change.

Do not combine gameplay, balance, RNG, flavor-text, schema, migration, or UI changes with an
authoring-classification refactor. A supported contract change requires a new immutable
Ruleset version and its reviewed forward migration.
