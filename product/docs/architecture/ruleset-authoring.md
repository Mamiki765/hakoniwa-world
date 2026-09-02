# Current Ruleset authoring

## Purpose and authority

The application authors one canonical runtime Ruleset. The exact configured key, version,
and entrypoint are resolved from `config/hakoniwa.php` and current code; this permanent
authoring document does not pin a concrete Ruleset generation.

When a release needs a semantic Ruleset change, its draft entrypoint may explicitly compose
unchanged fields from the exact immutable production predecessor `N` and only the bounded
changed-domain fragments for `N+1`. It does not load unsupported historical or roadmap
Rulesets and does not introduce a second runtime schema.

Git is the complete implementation history. The Markdown archive under
`docs/archive/rulesets/` is a human-readable index, not an executable payload source.

## Domain-first layout

The production predecessor remains the complete immutable source. A release draft replaces
only the domains that actually change and reuses all unchanged fields exactly. Concrete
entrypoint and fragment names belong to current code and release-specific history rather than
this permanent policy document.

There is no recursive merge, implicit flattening, reflection, generic Ruleset inheritance,
or dynamic historical catalog.

Each changed domain returns a `payload` fragment and adjacent `behavior`, `data`, and
`flavor` classification selectors.

## Classification contract

The three classifications are exhaustive. A fourth implicit classification is not allowed.

### Behavior

Behavior selects or identifies how the application acts. It includes phase order, timing,
eligibility, target selection, state transitions, transaction and retry semantics, snapshot
timing, RNG stream identity/version, selectors, policy/effect/progression types, stable keys,
ordering identities, settlement order, and cap application order.

Examples include facility or command identities, disguised presentation behavior,
target eligibility, ownership transitions, maintenance ordering, disaster eligibility,
missile resistance/destruction classification, command execution timing, and command sort
order when that order determines the player-facing command sequence.

### Data

Data is the value supplied to an unchanged algorithm. It includes probabilities, HP,
experience, prices, capacities, rates, levels, per-level amounts, weights, limits, build or
removal costs, and effect amounts.

### Flavor

Flavor can change without changing gameplay results, database state, target selection, or
RNG results. It includes names, descriptions, player-facing labels, unit labels, and display
text. Japanese skill and Item names are flavor when their stable keys and gameplay meaning do
not change.

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

## Release version discipline

Let the Owner-confirmed production Ruleset at release start be `N`.

- If the release has no semantic Ruleset change, it remains on `N`.
- If a semantic change is required, the release may introduce `N+1`.
- One release introduces at most one new Ruleset generation unless the Owner explicitly
  authorizes otherwise.
- While the release is not production-applied, `N+1` is the single release draft. Later
  features, PRs, balance changes, and stabilization in that same release amend the same
  `N+1`; they do not create `N+2`, `N+3`, or another generation per work slice.
- Source labels such as `published`, a PR, a migration file, or repository state alone do not
  prove that a draft has frozen. Production evidence and the Owner-confirmed production
  baseline determine freeze state.
- Once `N+1` is actually used by production, its key, version, full payload, definitions, and
  checksum are immutable. A later release that needs a semantic change creates the next
  generation rather than rewriting that frozen snapshot.
- If an agent believes one release requires more than one new generation, it must stop before
  implementation and report the reason and options to the Owner.
- The same version budget applies to subagents; they may not independently advance the
  generation.

A gameplay, balance, RNG, or persistence-interpretation change must not be hidden in an
authoring refactor. Editing a production-unapplied migration still requires an
Owner-confirmed production baseline.

Concrete historical generations and checksums belong to code, tests, migrations, Git, and
historical/audit documents. They are intentionally not copied into this permanent authoring
policy.
