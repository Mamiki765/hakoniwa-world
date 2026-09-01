# Current Ruleset authoring

## Purpose and authority

The current application authors one canonical runtime Ruleset: `hakoniwa-2s-plus-v19`.
The thin entrypoint at `config/hakoniwa/rulesets/hakoniwa-2s-plus-v19.php` explicitly composes
unchanged fields from immutable v18 and changed fields from the bounded v19 domain fragments.
It does not load an unsupported historical or roadmap Ruleset and does not introduce a
second runtime schema.

Git is the complete implementation history. The Markdown archive under
`docs/archive/rulesets/` is a human-readable index, not an executable payload source.

## Domain-first layout

The v18 source remains the complete immutable predecessor. v19 changes only these fragments:

- `v19/world-and-map.php` (identity only)
- `v19/commands-and-production.php`
- `v19/underground-facilities.php`

The entrypoint replaces identity, Surface commands/production, and adds the isolated
`underground_facility_development` definition section. Unchanged world generation, other
economy and Surface facilities, Secretary, Underground dungeon, and Trading Post behavior is
reused as-is from v18. There is
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

For v18 this includes the `undersea_city` identity, disguised presentation, sea/territory
target eligibility, capital-population transfer, fixed settlement identity, refugee exclusion,
maintenance payment and ordering policies, famine deduplication, minimum-population removal,
fire/disaster eligibility, missile resistance and destruction classification, and KARMA ledger
category identities.

For v19 this additionally includes the `territory_abandon` identity, its safe owned-cell
eligibility, ownership-only result, and non-turn-consuming execution contract. It also
includes the isolated Underground facility/command identities, slot target, build/remove
action, official-Turn consumption, and command ordering. Command sort order is behavior
because it determines the player-facing command sequence.

### Data

Data is the value supplied to an unchanged algorithm. It includes probabilities, HP,
experience, prices, capacities, rates, levels, per-level amounts, weights, and limits.

For v18 this includes the 100-billion-yen (`cost_money = 1000`) command cost, 3,100/3,000-person transfer values,
1,000-unit maintenance bases, 2:1 substitution ratio, 3,000-person minimum, and +3/+1 KARMA
amounts. Existing settlement growth and famine ranges remain unchanged inputs inherited from
v17. For v19 it also includes Underground build/remove costs and the city, farm, factory, and
missile-capacity effect amounts.

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

The Ruleset checksum separately owns empty arrays, key presence, array order, shapes,
types, nulls, and values. Classification metadata never enters the published payload.

## Change discipline

The v16 key, version, full payload, and checksum
`331d2d0e9456fa87a37ea0765313ecd9828b5d4912fa2b6637620806df80487d`
are immutable. The v17 key, version, full payload, and checksum
`8b0781a52e1d4b534a1e80acca4d63731fc7a80680bf27ea5edcaf1c0233e3b3`
are immutable. The v18 key, version, full payload, and checksum
`40bb900705776bf82e69e11b4f6f9aeed433988599aa0690cfd6088964e16f8b`
are immutable. v19 is the in-development Ruleset for release/3.1.0; its current checksum is
`b65752b88e9daf3c9b64e6d28b72847315d521dfe65b704f4cd8fd622e1368c9`.
It may change while that release branch is completed, even if source calls it published or a
publication migration exists. The production baseline, not a PR or source label, determines
freeze state.

When 3.1.0 reaches main/production and the production World uses v19, its key, version, full
payload, and checksum freeze. A later gameplay, balance, RNG, or
persistence-interpretation change then requires v20 plus a reviewed forward migration; it
must not be hidden in an authoring refactor. Editing a production-unapplied migration still
requires an Owner-confirmed production baseline.
