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

The entrypoint replaces only identity and commands. Unchanged world generation, economy,
surface facilities, Secretary, Underground, and Trading Post behavior is reused as-is from
v18. There is
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
eligibility, ownership-only result, and non-turn-consuming execution contract. Command sort
order is also behavior because it determines the player-facing command sequence.

### Data

Data is the value supplied to an unchanged algorithm. It includes probabilities, HP,
experience, prices, capacities, rates, levels, per-level amounts, weights, and limits.

For v18 this includes the 100-billion-yen (`cost_money = 1000`) command cost, 3,100/3,000-person transfer values,
1,000-unit maintenance bases, 2:1 substitution ratio, 3,000-person minimum, and +3/+1 KARMA
amounts. Existing settlement growth and famine ranges remain unchanged inputs inherited from
v17.

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
are immutable. The v18 key, version, full payload, and checksum
`40bb900705776bf82e69e11b4f6f9aeed433988599aa0690cfd6088964e16f8b`
are immutable. The current v19 key, version, full payload, and checksum
`3f6cc0bbede129ab08cd14093de3d19bbd08879cfb6d87cb792b21a46bcc16d0`
are immutable after publication. Later gameplay contracts require v20 or later.

A changed checksum under an already-published identity fails closed. A later gameplay,
balance, RNG, or persistence-interpretation change requires a new Ruleset identity and a
reviewed forward migration; it must not be hidden in an authoring refactor.
