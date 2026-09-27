# ver 2.4.0 KARMA and recovery

## Scope and authority

This is the formal gameplay extension for application version `2.4.0`. The
Owner-supplied KARMA/recovery contract is the authority for behavior that does
not exist in the third-party references.
Implementation remains under `product/` and reuses the canonical Turn,
missile, monster, lifecycle, command, economy, event, API, and UI paths.

The supported Ruleset is the immutable `hakoniwa-2s-plus-v13` payload with
checksum
`27c5d58d80e55bf2807cecd147b99b80e57ea0e1afd836eea150982445723b1f`.
Published v12 rows and payload remain unchanged historical audit records. The
only supported transition is an exact, forward-only v12 to v13 migration.

## Canonical implementation boundaries

| Contract | Canonical owner | v13 local delta |
|---|---|---|
| Turn ordering and retry | `CompleteTurnEngine`, `TurnState`, existing locked `TurnRun` | Freeze KARMA and monster A/B snapshots, accumulate an in-memory ledger, settle once. |
| Missile execution | `DomesticCommandExecutor`, `MissileImpactResolver`, canonical interception and impact | Classify anti-monster context per `LaunchIntent`, record the highest single meaningful-impact category, alliance rewards, recovery entry, and overflow sanctions. |
| Monster behavior | Existing spawn, movement, dispatch, damage, removal, and reward paths | Exclude recovery territory and remove resident monsters without rewards on recovery entry. |
| Nation lifecycle | `NationLifecycleService` | Add the 84-full-Turn recovery state and exact exit transition without a recovery-only scheduler. |
| Commands and territory | Existing registration validation and execution revalidation | Reject hostile recovery interactions before costs; keep aid, domestic work, and neutral expansion. |
| Presentation and audit | Existing resources, public ranking/detail, owner detail, event recorder | Add exact state/KARMA fields, ordered badges, public-safe events, and admin ledgers. |

No parallel missile or monster engine, speculative defender buff, broad UI
redesign, production reset, or v12 payload rewrite is introduced.

## KARMA contract

KARMA is an integer from `-10` through `100`; existing Nations enter v13 at
`0`. The target Turn freezes every Nation's starting KARMA before gameplay.
All reward, victim, and target-alignment decisions use that snapshot.

For a meaningful hostile player-missile impact against a Nation whose starting
KARMA is at most zero, v13 records the highest single matching category:

| Category | Points |
|---|---:|
| land or seabed oil-field destruction | 10 |
| seabed-base destruction | 3 |
| settlement/facility destruction or Capital damage above its minimum | 2 |
| meaningful terrain damage | 1 |
| Capital already at its 100-person minimum | 0 |

Normal, PP, and SPP missiles are anti-monster exempt when their complete
deviation footprint intersects a living monster in snapshot A (Turn start) or
snapshot B (missile-resolution boundary). The classification is frozen once
per `LaunchIntent`; later movement or death does not reclassify it. Land
destruction missiles are never exempt.

An SPP plan that changes an eligible foreign self-destructing monster from HP
above one to HP one adds `20` once per LaunchIntent and records the exact
private Secretary warning. A hostile monument against a target whose starting
KARMA is at most zero adds `15`. A foreign-territory monster final blow reduces
KARMA by `1` at most once per Nation and Turn.

Settlement order is fixed:

1. starting KARMA plus crime points;
2. one sanction shot per point above 100, then cap the candidate at 100;
3. victim reduction of one per meaningful hostile impact, bounded by positive
   starting KARMA and never crossing zero at this step;
4. one point of natural decay on every sixth Turn when both the starting and
   candidate values are positive;
5. three points for entering recovery, without crossing zero at this step;
6. the once-per-Turn foreign-monster reduction, then clamp to `-10`.

A non-positive-start attacker receives one internal money unit (1億円) per
target starting KARMA point for every meaningful impact against a positive-start target. The
same frozen alignment controls the refugee bonus. Credits use the existing
money-capacity path and preserve requested/applied/overflow audit values.

Overflow sanctions select the sanctioned Nation's owned surface coordinates
with replacement through the dedicated versioned KARMA-sanction RNG stream.
Each shot uses canonical defense/Secretary interception and ordinary missile
impact behavior. Sanction impacts do not feed back into KARMA, rewards,
refugees, or recovery qualification in the same Turn.

## Recovery contract

A Nation qualifies when a hostile player-missile sequence starts above 100
total population and a canonical impact first reduces it to exactly the
Capital minimum of 100. Qualification is latched at that impact; later
population growth in the same Turn does not revoke it. The current volley
completes, and only the lifecycle boundary changes the Nation state to
`recovery`, assigns `resume_at_turn = T + 85`, and applies the entry KARMA
reduction.

`T+1` through `T+84` are complete recovery Turns. At the start of `T+85`, a
meaningful queued non-finance command returns the Nation to `active`; without
one, an idle counter of at least 360 returns it to `dormant`, otherwise it
returns to `active`. Recovery never becomes dormant before this exit boundary.

Registration and execution both reject, before costs:

- foreign player missiles selected against a recovery Nation or its protected
  territory, and recovery-Nation missiles selected against foreign territory;
- monster dispatch and monument flight involving a recovery Nation;
- hostile territory expansion into recovery territory and territory influence
  from or into recovery territory.

Missile launches selected against self-owned or neutral coordinates remain
legal during recovery. If an established impact from such a launch produces
canonical `crime_points > 0`, that impact is not rolled back and recovery ends
immediately afterward; the remaining shots are processed as an ordinary active
Nation. A crime-zero anti-monster impact does not end recovery. If a Nation
that exited recovery for crime later qualifies again from a different hostile
player missile in the same Turn, the latched recovery ledger takes priority at
the lifecycle boundary and the Nation re-enters recovery.

Money/food aid, domestic commands, neutral territory expansion, production,
resource sales, disasters, and the normal owner UI remain active. Recovery is
not dormant winter protection: disasters still apply. On entry, living
monsters on the Nation's territory are removed without money, experience,
kill-stat, or KARMA rewards. Recovery territory is excluded from natural/world
spawn, movement, dispatch, and monster-origin actions.

## Persistence, migration, and provenance

The migration adds bounded Nation `karma`, expands lifecycle integrity to the
`recovery` state, publishes v13, and then invokes the exact upgrader. Preflight
requires one supported shared World on the exact published v12 source, all
existing Nation KARMA values at zero, valid lifecycle values, intact integrity
triggers, and no unresolved non-dry `pending`, `running`, `failed`, or
`blocked` TurnRun. It fails closed before changing the World when any condition
is not met.

The transaction preserves World/Nation lifecycle and idle data, live monsters,
command queues and positions, Secretary/equipment/Item state, request keys and
fingerprints, terminal Turn history, audit events, and historical provenance.
Its preservation digests stream ordered rows into incremental hashes so that
production history is not retained in one in-memory JSON array. It changes only
the current World Ruleset reference to the published v13 row; there is no reset
or backfill reinterpretation.

## API, UI, and audit

Owner and public Nation payloads expose `state`, exact `karma`, and exact
remaining recovery Turns. Rankings render achievements, state badge, then
positive `KARMA:n`; positive names and values use the existing red emphasis.
Zero and negative values remain exact in detail views without a badge or red
emphasis.

Public events announce recovery entry/exit, alliance monster removal, and
sanction decisions/launches without exposing private target or RNG details.
Admin-only events retain Turn-start snapshots, A/B anti-monster classification,
impact category and alignment, recovery qualification, sanction coordinates,
and the final ordered ledger. The deliberate SPP warning is private to its
Nation.

Defender buffs are deferred and have only an extension boundary in v13.

## Test impact forecast

Recorded before implementation:

| Metric | Forecast | Rationale |
|---|---:|---|
| New test files | 0 | Extend existing representative lifecycle, missile, monster, migration, API, and UI contracts. |
| New identifiers | about 20 | Cover materially different policy categories and state transitions without a Cartesian matrix. |
| Production World constructions | about 8 | Use the existing lightweight representative World fixture. |
| Official Turn executions | about 12 | Exercise ordering and exact lifecycle boundaries; avoid 84 repeated Turns. |
| Migration/fresh-install executions | 3 | Exact upgrade, fail-closed source/run checks, and current fresh install. |
| World expansions | 0 | No expansion contract changes. |
| Concurrency/lock orchestrations | 0 | Existing transaction and World lock owners are unchanged. |
| Performance profiles | 0 | No new performance threshold. |
| Estimated runtime delta | medium | Missile and migration integration paths dominate; pure RNG arithmetic remains unit-level. |

## Test impact actual

| Metric | Forecast | Actual | Reason for difference |
|---|---:|---:|---|
| New test files | 0 | 0 | Existing representative feature, unit, and UI files own every new contract. |
| New identifiers | about 20 | 21 | One focused regression was added after review found that a meaningful zero-point monster impact skipped victim reduction and alliance money. |
| Production World constructions | about 8 | 17 lightweight Worlds | Missile categories share one World per broad scenario, but recovery also required distinct disaster, monster, dispatch, lifecycle, equipment, install, and migration ownership checks. No production-size World is constructed. |
| Official Turn executions | about 12 | 4 | Most ordering contracts are proven at the canonical service boundary; the recovery-exit boundary now proves that first-Turn dormancy cannot immediately abandon before the following Turn, while the post-migration runnable check remains separate. |
| Migration/fresh-install executions | 3 | 4 contract paths | Two current-install assertions, one exact v12-to-v13 migration, and one fail-closed wrong-source migration keep install and upgrade evidence separate. |
| World expansions | 0 | 0 | No expansion fixture is used. |
| Concurrency/lock orchestrations | 0 | 0 | Existing transaction and World-lock owners remain unchanged. |
| Performance profiles | 0 | 1 bounded query contract | The required 100-shot LaunchIntent check fixes the production regression boundary at two full monster snapshots and one classification, without timing-only assertions. |
| Estimated runtime delta | medium | medium | Missile integration and migration paths remain the dominant additions. |

The Ready-PR handoff records exact-head verification evidence for this final
test shape.
