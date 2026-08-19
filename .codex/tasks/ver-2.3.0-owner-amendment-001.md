# TEMPORARY OWNER AMENDMENT 001 — Monster Dispatch Selection

> **Status: WORKING FILE — DO NOT MERGE INTO `main`.**
>
> Read this file immediately after `.codex/tasks/ver-2.3.0.md`. This amendment supersedes every conflicting statement in the main temporary contract, especially the C4 `Dispatch command`, C4 Zero tests, C5 v11 content, and C0 prompt assumptions. Delete this file together with the main temporary contract before the final `release/ver-2.3.0 -> main` PR is declared merge-ready.

## Owner-approved correction

Do **not** add a separate `monster_dispatch_zero` command.

Keep one player-facing command:

- stable command key: `monster_dispatch`
- player-facing name: `怪獣派遣`

The player chooses the dispatched monster from a trusted selector attached to that command:

| selector value | player label | execution cost |
|---|---|---:|
| `mecha_inora` | `メカいのら（1,000億円）` | 1,000億円 |
| `mecha_inora_zero` | `メカいのら零式（5,000億円）` | 5,000億円 |

The regular Mecha Inora dispatch cost changes from the v10 value of 3,000億円 to 1,000億円 in v11. Mecha Inora Zero costs 5,000億円.

## Selector semantics

Use an explicit normalized selector parameter such as `monster_key` or `monster_variant`; choose the exact field name after C0 audits current command-parameter conventions.

Do not overload the universal command `quantity` field to encode monster type. Quantity remains unused/default 1 for `monster_dispatch`. The monster selector is identity, not repetition count.

The command UI must show the two server-provided options and their costs. `mecha_inora` is the default selection unless the player explicitly chooses Zero.

The server must:

- expose only the two v11-authorized options;
- reject arbitrary client-supplied monster keys;
- derive the selected definition and execution cost from the active v11 ruleset, not from client labels or client cost values;
- revalidate the selector, target Nation, funds, and spawn candidate at execution;
- preserve existing secrecy, target-Nation validation, idempotency, and no-spawn-turn-action behavior for both variants;
- include the normalized monster selection in the command request fingerprint, so reusing one request key with a different selected monster returns the existing stable 409 conflict;
- return/present the selected monster and its effective cost in queue and execution previews without misleading static `cost_money` output.

C0 must audit whether the existing generic parameter-schema UI can safely represent an option-specific cost. If not, add the smallest explicit monster-dispatch option projection; do not create a generic dynamic-pricing framework.

## Existing queued v10 commands

An existing queued v10 `monster_dispatch` row has no monster selector. During v11 migration/rebind it must resolve only to ordinary `mecha_inora`; it must never become Zero by inference from quantity, position, cost, or any other mutable field.

C0 must determine the safest auditable compatibility method:

- migration-scoped normalization of live queued rows; or
- a narrowly versioned default for missing selector state.

Do not rewrite completed, failed, or cancelled command history. Do not guess historical request payloads. Preserve/fail closed around request fingerprints as required by the audited 2.2.1 idempotency contract.

Under v11, a still-queued ordinary dispatch uses the new ordinary v11 execution cost of 1,000億円 because command cost is checked and deducted at execution rather than reservation.

## Revised C4 tests

Replace the old separate-command expectations with at least:

- one `monster_dispatch` command exposes exactly two authorized monster choices;
- default/explicit `mecha_inora` costs 1,000億円 and dispatches ordinary Mecha Inora;
- `mecha_inora_zero` costs 5,000億円 and dispatches Zero;
- quantity is not interpreted as monster identity;
- arbitrary/disabled monster key is rejected without mutation or cost;
- insufficient funds are evaluated against the selected option cost;
- exact request retry with the same selector converges;
- same request key with a different selector returns the stable conflict and does not mutate the queue;
- queue/presentation text clearly identifies the selected monster and cost;
- existing queued v10 rows with no selector migrate/default only to ordinary Mecha Inora;
- existing historical completed/failed/cancelled rows remain unchanged;
- both variants use the same target eligibility, candidate rules, secrecy, and spawn-turn defer boundary.

## Revised C5 v11 content

The v11 payload contains one `monster_dispatch` command with an audited, ruleset-owned two-option selector and option-specific costs. There is no new `monster_dispatch_zero` stable command key.
