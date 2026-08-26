# Current Ruleset authoring

## Purpose and authority

The current application authors one canonical runtime Ruleset: `hakoniwa-2s-plus-v17`.
The thin entrypoint at `config/hakoniwa/rulesets/hakoniwa-2s-plus-v17.php` explicitly composes
unchanged fields from immutable v16 and changed fields from the bounded v17 domain fragments.
It does not load an unsupported historical or roadmap Ruleset and does not introduce a
second runtime schema.

Git is the complete implementation history. The Markdown archive under
`docs/archive/rulesets/` is a human-readable index, not an executable payload source.

## Domain-first layout

The v16 source remains the complete immutable predecessor. v17 changes only these fragments:

- `v17/world-and-map.php` (identity only)
- `v17/turn-pipeline.php`
- `v17/monsters-and-military.php`
- `v17/secretary.php`

The entrypoint names every final top-level field explicitly and explicitly replaces the
settlement portion of `turn_processing`. Unchanged world, lifecycle, economy, terrain,
facilities, commands, disasters, and Trading Post behavior is reused as-is from v16. There is
no recursive merge, implicit flattening, reflection, generic Ruleset inheritance, or dynamic
historical catalog.

Each changed domain returns a `payload` fragment and adjacent `behavior`, `data`, and
`flavor` classification selectors.

## Classification contract

The three classifications are exhaustive. A fourth implicit classification is not allowed.

### Behavior

Behavior selects or identifies how the application acts. It includes phase order, timing,
eligibility, target selection, state transitions, transaction and retry semantics, snapshot
timing, RNG stream identity/version, selectors, policy/effect/progression types, stable keys,
ordering identities, settlement order, and cap application order.

For v17 this includes Item tradability and NPC eligibility, drop eligibility/pools and RNG
streams, recipient and capacity-check order, bow target/safety/finisher behavior, Collar
qualifying impacts, skill progression accounting mode and requirement basis, experience
source, Secretary Suit excluded skill keys, population-limit targets and rounding, and
historical backfill source policy.

### Data

Data is the value supplied to an unchanged algorithm. It includes probabilities, HP,
experience, prices, capacities, rates, levels, per-level amounts, weights, and limits.

For v17 this includes the 10,000 triangular multiplier, natural maximum +50 per level,
attraction maximum +100 per level, Indomitable's 25 basis points per level, the 100-person
over-cap decline, Item fixed-sale prices, drop weights/caps, and bow probabilities/damage.

### Flavor

Flavor can change without changing gameplay results, database state, target selection, or
RNG results. It includes names, descriptions, player-facing labels, unit labels, and display
text. The Japanese skill and Item names are flavor; their stable keys are behavior.

## Decision procedure

Classify a leaf in this order:

1. If changing it changes a path, order, target, state transition, identity, selector, or
   interpretation rule, classify it as behavior.
2. If the algorithm is unchanged and only an input value changes, classify it as data.
3. If gameplay, persistence, targeting, and RNG results are unchanged, classify it as
   flavor.
4. If runtime use, tests, decisions, and Git history do not resolve the choice, stop and
   request an Owner decision.

A nullable numeric field may require leaf-level judgment: a numeric threshold is normally
data, while `null` may disable a path and therefore express behavior.

## Mechanical validation

`CurrentRulesetAuthoringInspector` is inspection-only. It owns semantic classification of
scalar leaves and never composes runtime settings. A selector is a field name or an absolute
JSON-pointer-like path where `*` matches exactly one numeric list index, never an associative
key. The inspector rejects:

- an invalid domain or classification key;
- an unclassified or multiply classified leaf;
- a leaf authored by more than one changed domain;
- an unused selector; or
- a changed-domain leaf whose value or type differs from the final payload.

The immutable checksum separately owns empty arrays, key presence, array order, shapes,
types, nulls, and values. Classification metadata never enters the published payload.

## Change discipline

The v16 key, version, full payload, and checksum
`331d2d0e9456fa87a37ea0765313ecd9828b5d4912fa2b6637620806df80487d`
are immutable. The v17 key, version, full payload, and checksum
`8b0781a52e1d4b534a1e80acca4d63731fc7a80680bf27ea5edcaf1c0233e3b3`
become immutable when published.

A changed checksum under an already-published identity fails closed. A later gameplay,
balance, RNG, or persistence-interpretation change requires a new Ruleset identity and a
reviewed forward migration; it must not be hidden in an authoring refactor.
