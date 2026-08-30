# Project instructions

## Work area

The new application must be implemented only under `product/`.

## Agent guardrails

### Production migrations

Existing migrations are append-only by default. Unless the Owner explicitly identifies the
production baseline, do not modify, delete, squash, or rebaseline an existing migration.
Schema or persisted-data changes require a new forward migration. Repository state is not
evidence of production migration state.

Retire an existing migration only when the Owner has identified that baseline and the
schema/data effects and supported runtime, fresh-install, test, and upgrade paths prove that
they no longer depend on it.

### Current Ruleset classification

Behavior describes how the application acts or interprets a value: paths, identities,
selectors, timing, state transitions, RNG semantics, and semantic sentinels. Data is an input
to unchanged Behavior, such as HP, prices, probabilities, capacities, durations, and effect
amounts. Flavor is presentation-only, such as names, descriptions, player-facing labels, and
`unit_label` values.

`product/docs/architecture/ruleset-authoring.md` is the detailed authority. Do not duplicate
its large example set in this file.

### Development handoff

`product/docs/handoffs/development-history-and-current-handoff.md` is read-only context for
Codex and implementation agents. Do not modify, regenerate, format, or commit it unless the
Owner explicitly requests a handoff update.

The Owner and the Web ChatGPT development-advisor workflow maintain that document separately
after implementation, review evidence, and Owner decisions have been examined.

### Documentation navigation

After reading this file, start with
`product/docs/handoffs/development-history-and-current-handoff.md`, then `docs/README.md`,
then `docs/open-questions.md`. Follow the guide to select only the current, task-specific
architecture, operations, ADR, code, and Ruleset sources needed for the work. Do not load all
documentation indiscriminately.

Historical implementation, audit/reference-analysis, and future/roadmap documents are not
current authority. Do not judge freshness from filenames or modification dates alone. If a
document conflicts with current reviewed code, the immutable Ruleset, schema/current
migration contract, or an accepted active ADR, report the conflict instead of silently
reconciling it. Keep `docs/README.md` as navigation; do not duplicate gameplay specifications
there.

### Player manual

Prioritize information that changes a player's decisions; do not exhaustively document
internal processing. Describe ordering, RNG consumption, fail-closed behavior, transactions,
or rare race conditions only when players need them to make decisions or understand visible
behavior. Do not pre-document behavior that causes no disadvantage or loss of control when
unknown and can be learned through ordinary play.

## Read-only references

Everything under `_references/` is third-party reference material.

Never modify, format, rename, delete, or commit files under `_references/`.

## Reference roles

`_references/hakoniwa-2plus/source` is used to study:

- shared-world behavior
- player and nation placement
- territory and borders
- turn processing
- disasters
- missiles
- world expansion
- legacy game rules

`_references/yamanity/repository` is used to study:

- Laravel architecture
- Vue user interface
- Docker development environment
- entity and persistence separation
- JSON-based management
- administration features
- maintainability

Neither reference implementation is the target architecture.

Do not translate the C source directly into PHP.

Do not copy third-party images, text, or substantial code into `product/`.

Extract behavior into documentation and tests before implementing it independently.

## Encoding

Preserve the original encoding of files under `_references/`.

All newly created source code, documentation, database text, APIs, and user input must use UTF-8.

## Current phase

Game implementation under `product/` has been explicitly approved by the repository owner.

The shared-world MVP is being implemented through roadmap-scoped pull requests. The current approval includes the game state, commands, turn processing, economic loop, and the supporting API, UI, persistence, tests, documentation, and operations needed by those roadmap slices.

Keep each implementation within its approved roadmap scope. Do not implement a `Deferred` item early without separate explicit approval. The design gates in `docs/open-questions.md` remain in force: when an `Open` item reaches its `Required before` gate, report the options and obtain a decision instead of deciding it implicitly or implementing around it.

## Production data boundary

PR23 is the first production-release baseline. Development Worlds and provisional data may
be fresh-reset only until all three go-live conditions have occurred:

1. the production World has been generated for the final time;
2. Nation registration has been opened to general users; and
3. the first official production turn has started.

After that boundary, existing Worlds, Nations, cells, command queues, TurnRuns, and events
are production data. Do not destroy or silently reinterpret them. Every schema or gameplay
data change must provide a forward migration or an explicit, reviewed conversion path.
Ruleset changes must state their effect on existing Worlds and keep the production World
runnable through compatible runtime support or an explicit migration.

Published ruleset rows, settings, command definitions, and production definitions are
immutable audit records before and after go-live. Never overwrite a published payload.
Before deploy, verify that the next non-dry TurnRun is not pending, running, or failed.
Resolve such a run before deploy; never retry it automatically across a release. Preserve
the same-target-turn, same-ruleset, same-seed manual retry boundary and audit records.

The pre-release reset exception ends permanently at go-live. Do not copy it into future
roadmaps or use PR23 rebaseline work as precedent for resetting production player data.

## Compatibility policy

Production compatibility carries the immediately preceding supported source into the
current version through an explicit forward migration. The current runtime and normal CI
are not required to execute, reproduce, or directly upgrade unsupported historical
versions. Preserve historical application source in Git history and preserve historical
production and audit records in the database.

Historical provenance retained in a supported current database is current production data.
It must remain interpretable for read-only presentation, integrity checks, and request
idempotency even when its originating runtime version is no longer supported. Do not add
runtime or tests for an unsupported historical version unless the Owner explicitly restores
that support.

## Design gates

Before starting implementation work, read `docs/open-questions.md`.

If an `Open` item is related to the implementation scope and its `Required before` gate has been reached, do not decide it implicitly or implement around it. Report the item, the viable options, and the effect on the planned implementation.

For `Deferred` items, preserve only a clear extension boundary. Do not implement the deferred feature early as part of the MVP.

## Pull request scope and cross-cutting changes

Do not combine substantial TurnRunner implementation with unrelated coordinate-system
changes, existing-game-data migrations, World-reset redesigns, or broad rendering
changes in the same pull request.

Schema migrations that create or update TurnRunner-owned tables, indexes, constraints,
and audit records are part of the TurnRunner scope.

Small compatibility, integrity, safety, and operator-reporting changes directly required
by the TurnRunner schema or behavior may be included in the same pull request only when
they are explicitly identified, narrowly scoped to the integration boundary, and covered
by regression tests.

Unrelated refactoring, broad UI redesign, coordinate conversion, World-reset redesign,
or migration of existing gameplay data must be split into a separate or stacked pull
request.

If a required compatibility change grows beyond a small and reviewable boundary, stop
and propose the split before implementing it.

## Tool-call batching

In Code Mode, within each bounded stage, run independent, functions.exec-available tool calls concurrently in one functions.exec call. Use await Promise.allSettled([...]) when partial results are useful, and inspect every result; use await Promise.all([...]) only when any failure should abort the batch. Keep dependencies, waits/resumes, approvals, conflicting or interdependent mutations, and adaptive investigations where each result may change the next step sequential. Do not split otherwise batchable inspections across outer tool calls.

## Implementation reuse policy

Before introducing a new service, resolver, policy, handler, or execution path for a
feature variant, identify:

- the existing canonical runtime path for the same subsystem;
- which behavior is already shared;
- the smallest behavior that is actually unique to the new variant; and
- why that difference cannot be expressed as authored configuration, an existing
  capability, or a local mutation/result difference.

The default is to reuse the canonical path and localize only the unique behavior.

A new Item, monster, command, facility, disaster, or Ruleset variant does not receive a
parallel execution engine merely because its behavior differs.

Prefer:

canonical path + explicit local delta

over:

variant-specific copy of the canonical path.

If a separate execution path is proposed, identify the concrete contract that requires it.
Separate paths are justified when ordering, RNG streams, transaction or lock boundaries,
persistence semantics, or event semantics are materially different.

Do not create a generic framework merely to avoid a small explicit conditional or to
prepare for hypothetical future variants.

Reuse existing concrete code first. Extract a shared abstraction only when the common
contract already exists in current supported behavior and the abstraction is clearer than
the duplicated paths.

Aoi Inora is the reference example: it remains an ordinary monster for movement candidate
selection, occupancy, movement limits, protection, defense contact, damage, kill handling,
and the normal monster action flow.

Its unique contracts are limited to its distant-sea World spawn, ability to traverse water,
and neutral-sea result when consuming a destination. Those differences do not justify a
separate monster engine.

## Test scope policy

Add a test only when it protects at least one of:

- a new supported production contract;
- a previously observed real regression;
- a production-reachable integrity boundary;
- a production-reachable transaction, concurrency, or lock boundary;
- a current security or authorization boundary; or
- a currently supported install, upgrade, or release boundary.

Prefer extending an existing representative contract test. Do not add another fixture,
matrix, or file for the same invariant. Do not add tests solely for unsupported historical
applications or Rulesets, direct upgrades outside the product contract, states rejected by
request validation or database integrity, theoretical integer or identifier maxima, the same
invariant at every internal layer, every Item/command/monster variant of a shared subsystem,
speculative future compatibility, or precautionary abnormal cases without a production
path. A bug fix does not require a new test file when an existing contract test can contain
the regression.

## Test impact forecast policy

Before implementing a feature or fix, produce a test impact forecast.

The forecast must identify:

- the supported production contract being added or changed;
- the existing test file or representative contract that should own it;
- the expected number of new test files and identifiers;
- every expensive fixture expected to be added or executed:
  - fresh database installation;
  - migration or upgrade;
  - production-size World construction;
  - World expansion;
  - official non-dry Turn;
  - concurrency or lock orchestration;
  - performance profile;
- the expected runtime impact; and
- if a new test file or expensive fixture is proposed, why an existing representative
  test cannot cover the contract.

The runtime forecast may be qualitative when no reliable measurement exists yet (e.g.
negligible / small / significant). Do not run expensive benchmarks solely to make the
forecast numerically precise.

The default forecast is:

- zero new test files;
- extension of an existing representative contract test;
- one representative integration path for one new production contract; and
- small unit tests only for pure logic that does not require database, World, or Turn setup.

This is a planning budget, not a hard cap. Necessary tests may exceed it, but the reason
must be stated before implementation.

A new Item, monster, command, facility, or Ruleset variant does not duplicate the shared
subsystem test suite. Test only the behavior that is unique to that variant. Shared
persistence, transaction, locking, equipment, targeting, damage, or retry contracts remain
owned by their existing representative tests.

For state or policy systems, do not create a Cartesian matrix of every state × every
command × every target type. Test the shared policy once per materially different action
category, then add a small number of representative end-to-end transitions.

A new performance test must define an actual regression contract, such as a query or
runtime bound. Tests that only report timing, assert that queries are greater than zero, or
repeat intermediate sizes without a distinct product boundary are not allowed.

Before opening a PR, compare actual test growth with the forecast:

| Metric | Forecast | Actual | Reason for difference |
|---|---:|---:|---|
| New test files | | | |
| New identifiers | | | |
| Production World constructions | | | |
| Official Turn executions | | | |
| Migration/fresh-install executions | | | |
| Estimated runtime delta | | | |

If actual growth materially exceeds the forecast, consolidate fixtures and representative
contracts before declaring the implementation complete.

Reviewers requesting another test must identify:

- the supported production contract that is currently unprotected;
- why an existing representative test cannot own the regression; and
- the least expensive layer that can prove the contract.

Do not request a full integration matrix when a representative integration test plus a
focused unit or policy test proves the same production contract.

Important:

- Do not interpret this as a numerical test cap.
- Do not remove necessary current-contract tests merely to satisfy the forecast.
- Do not modify gameplay or runtime behavior for this policy addition.
- This policy is intended to force cost and ownership consideration before tests are added,
  not to discourage legitimate regression coverage.

## Review scope policy

A review finding must show at least one of:

- reachability from a supported production path;
- damage to the current migration or install contract;
- risk to persistent data;
- a security or authorization risk;
- a transaction, concurrency, or lock risk;
- a current player-visible regression; or
- a current operator-contract regression.

Do not require P1 or P2 work solely because an unsupported historical version no longer
runs, a rollback outside the product contract is unavailable, an impossible state rejected
by database or request boundaries is unhandled, a theoretical maximum is untested, the
same validation is not duplicated in every internal layer, only representative variants are
tested, or an explicit Owner decision ended compatibility. An Owner-declared product
constraint is not missing compatibility.

## Verification policy

Local backend full suites are separate verification units:

- Surface full is `composer test:surface` and contains `tests/Unit` plus `tests/Feature`; it does not contain `tests/Underground`.
- Underground full is `composer test:underground` and contains only `tests/Underground`.
- Repository-wide verification is `composer test:all`; `composer test` remains its compatibility alias.

For an Underground-only feature or fix, run focused tests, Underground full, relevant static/frontend checks, and exact-head Quality CI. Do not add a local Surface full run. For a Surface-only feature or fix, run focused tests, Surface full, relevant static/frontend checks, and exact-head Quality CI. Do not add a local Underground full run.

Run both local full suites or `composer test:all` only for a release, rebaseline, test-infrastructure change, Surface/Underground shared-runtime cross-cutting change, or explicit Owner request, and only when there is a concrete reason. Exact-head Quality CI remains repository-wide and must cover both suites with complete shard coverage, duplicate zero, missing zero, and serial/shard identifier equivalence. A test-infrastructure change may prove the separation contract with focused script/config tests and exact-head CI without adding an otherwise unnecessary long local repository-wide serial run.

For an ordinary feature or fix PR, exact-head sharded Quality CI plus appropriate focused
local tests and static checks may provide complete verification. A 30-minute-class local
full serial run is not automatically required. Require local full serial primarily for a
release or rebaseline, migration or test-infrastructure changes, shard-planner changes,
broad cross-cutting runtime changes, or an explicit Owner request. Do not request another
local serial run without a concrete reason when focused local verification and exact-head
full sharded CI are appropriate.

Do not reacquire verification evidence for the same conditions and content without a new
decision need. Exact-head evidence may be reused while the source tree or commit, test
configuration, dependency lock state, runtime or container image, database/test environment,
and performance-relevant hardware or execution environment are unchanged. If the Owner
declares an environment change, or repository evidence shows a new PC, CI runner, runtime
image, dependency, or test infrastructure, do not treat old performance numbers as the same
baseline. When more timing evidence is needed, profile the relevant test group, known slow
shard, or suspected files first. Run full serial on the meaningful final candidate state, or
after a cross-cutting change when there is a concrete reason.

## Coordinate system

The canonical surface-map coordinate system is the staggered square-tile `x`/`y` grid defined by `docs/decisions/ADR-0003-hex-coordinate-system.md`.

- Use `x`, `y`, `chunk_x`, `chunk_y`, `local_x`, `local_y`, `target_x`, and `target_y` in current code and public interfaces.
- The initial world is `0..59` on both axes with 60 cells in every row.
- Even absolute `y` rows are rendered 16px to the right; never derive parity from a capital-relative row.
- Keep the six-neighbor direction numbering identical in Backend and Frontend.
- Cube conversion is permitted only as a private distance-calculation detail.
- Historical migrations may name the retired coordinate columns only to backfill or roll back them.
