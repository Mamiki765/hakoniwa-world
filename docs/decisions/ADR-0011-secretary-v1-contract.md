# ADR-0011: Secretary v1 contract

- Status: Accepted
- Date: 2026-08-16
- Scope: hakoniwa-world ver 2.0.0

## Context

B-15 reserved User-owned Secretary data as the persistence boundary for gameplay that survives Nation abandonment and re-registration. ver 2.0.0 needs the smallest complete vertical slice: identity, naming, four passive skills, growth, turn effects, batch loading, and a production-safe immutable ruleset transition.

## Identity and lifecycle

Secretary belongs to User, never Nation. There is exactly one `secretaries` row per participating User, enforced by `UNIQUE(user_id)`; the row has no `nation_id`. `name` and `named_at` are both nullable and must transition from both null to both non-null exactly once. Secretary names are plain text, 1–30 characters, need not be globally unique, and cannot be renamed in v1.

The normal creation trigger is the User's first successful Nation registration. Creation and the four initial skill rows are idempotent. Abandonment does not delete or copy Secretary state. When the same User registers another Nation, the same Secretary, name, levels, and experience are used; Nation assets are not moved into Secretary or copied to the new Nation.

The production migration backfills exactly the Users for whom the database contains Nation registration history, whether the Nation is active or abandoned. The target set is reconstructed from owner membership, completed Nation-creation request, and Nation-created/abandoned audit history. It is not a hard-coded list of User IDs. At the verified pre-migration production snapshot this set contains 24 of 28 Users; the four Users with no Nation history are deliberately not created by the migration and receive a Secretary only after a future first successful Nation registration. The migration compares the sorted expected Nation-history `user_id` set with the sorted persisted Secretary `user_id` set for exact equality; a matching count alone is insufficient. Missing referenced Users, any missing or unexpected Secretary User, or incomplete skill initialization fails closed. The unique constraint and insert-or-ignore plus postcondition checks make both migration and normal registration paths idempotent.

## Unnamed and named presentation

An unnamed Secretary is fully active: effects and experience do not depend on `name`. The header label is `？？？`. Gameplay logs refer to `秘書`; after naming they may refer to `秘書の{name}`. Opening the unnamed Secretary page shows only the owner-provided naming story, a plain-text input initially containing `ペリドット`, and an OK action. No monster-history lookup is part of the trigger. Once named, the story is not shown again; the page shows the title `秘書`, the name in the same display typeface used for island names, the passive-skill level, current XP and required XP, and the current effect. It does not render a separate remaining-XP or `NEXT` line.

## Calendar

The TOP page displays `箱庭歴 {year}年{month}月` immediately below the current turn. It is derived without persistence for every turn `>= 1`:

```text
year  = floor((turn - 1) / 12) + 1
month = ((turn - 1) % 12) + 1
```

Thus turn 1 is 1年1月, turn 12 is 1年12月, and turn 13 is 2年1月.

## Skill state and progression

Skill state is normalized in `secretary_skills` with `UNIQUE(secretary_id, skill_key)`. v1 contains exactly these four stable keys:

| Skill | Initial level | Effect | Experience source | Next-level requirement |
|---|---:|---|---|---:|
| 農業政策 | 0 | wheat production `floor(base × (1000 + Lv) / 1000)` | each successful `build_farm` command execution | `(current Lv + 1)^2` |
| 特産品開発 | 0 | industrial production by the same multiplier | each successful `build_factory` command execution | `(current Lv + 1)^2` |
| 金鉱脈調査 | 0 | mineral production by the same multiplier | each successful `build_mine` command execution | `(current Lv + 1)^2` |
| 最終防衛ライン | 1 | after ordinary defense, intercept at most Lv otherwise-unblocked missiles per turn | each missile reaching a currently owned cell | `(current Lv)^2 × 100` |

For development skills, one successful command execution grants exactly 1 XP. A `quantity` greater than one does not multiply XP. Queue insertion, cancellation, and failed execution grant zero. Level-up consumes the requirement, retains overflow, and repeats while another threshold is met.

Production uses integer arithmetic and does not alter resource capacities or overflow policy. Each production skill affects only its corresponding output.

Final-defense XP and interception eligibility are independent. Every missile that reaches a cell currently owned by the Nation grants 1 XP, including a missile caught by ordinary defense, caught by Secretary, actually impacting, or reaching through self-fired collateral. Ordinary defense resolves before Secretary interception. Secretary does not intercept a missile targeting a monster-occupied cell, does not distinguish enemy from self-fired missiles, and keeps an in-memory per-attempt budget of Lv interceptions. Budget use is not persisted.

## Turn-attempt boundary

At each attempt start, active Nations are mapped in one batch through owner memberships and Users to Secretaries and their skills. Cell, facility, command, and missile processing read only the resulting in-memory `TurnState` snapshot; they must not query Secretary per cell or missile. This is not a persistent `TurnRun` snapshot. The snapshot, including levels and final-defense budget derived from those levels, is fixed for the running attempt.

Skill XP is accumulated in memory and written in the same transaction as the successful gameplay result. Level-up is resolved and persisted during that flush, but it does not mutate the running attempt snapshot: XP earned during a turn cannot increase production effects or the final-defense interception budget during that same turn. The new level affects those behaviors from the next turn attempt's batch load. A failed attempt rolls gameplay and XP back. A manual retry retains the established target-turn, ruleset, and random-seed contract but batch-loads the latest Secretary state at the start of the new attempt. It therefore neither reuses a failed attempt snapshot nor duplicates committed XP.

## Ruleset and migration

Published v1–v6 payloads and checksums remain unchanged. `hakoniwa-2s-plus-v7` inherits v6 and adds only the exact Secretary v1 contract. The production World moves from v6 to v7 through a forward-only, fail-closed migration under the World turn lock. It refuses unresolved next-turn production runs and unexpected rulesets, checks v6/v7 stable-key sets, and rebinds only live references whose schema requires a ruleset-specific definition ID. For command queue items, only `status = queued` is rebound; completed, failed, and cancelled historical items retain their v6 command definition. Queue payloads, quantities, and command semantics do not change.

## Out of scope

Accessory, equipment slots, 機械弓, PD, active skills, blueprints, image upload, custom illustration, conversation or generated dialogue, new monsters, Sea gameplay, Karma, generic modifiers, generic proficiency, and speculative tables/hooks/frameworks are not part of Secretary v1.
