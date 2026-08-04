# PR19 resource capacity and Nation profile audit

## Scope boundary

PR19 inherits the merged PR18 behavior and publishes `roadmap-pr19-v1` as a new immutable ruleset. It does not change `NationLandAreaCalculator`, owned-land projection, land subsidence, disaster probability, Capital rules, coordinates, or territory calculations.

Historical Worlds are not migrated or repointed. They remain readable through viewer-safe APIs, while mutation returns `reset_required`. No historical-ruleset runtime fallback or data-preserving balance migration is added.

## Contract matrix

| Concern | PR19 contract | Evidence boundary |
|---|---|---|
| Industrial goods unit | canonical `unit`, label `ユニット` | immutable ruleset payload and resource catalog publication |
| Minerals unit | canonical `ton`, label `トン` | immutable ruleset payload and resource catalog publication |
| Industrial goods capacity | 9,999,000 per Nation | generic `resource_capacities` map |
| Minerals capacity | 9,999,000 per Nation | generic `resource_capacities` map |
| Enforcement order | production, sale policy, individual resource cap, money cap | phase-10 turn integration tests |
| Overflow | discard; never convert to money | `capacity.overflow` audit event and rollback tests |
| Owner API | exact amount, capacity, remaining capacity, reached state | private Nation resource only |
| Public API | owner name and comment; no exact inventory or capacity | public ranking/detail projection tests |
| Registration | explicit owner name plus optional comment | validation and no-OAuth-fallback tests |
| Profile edit | owner-only partial PATCH | authorization, validation, audit, no-op tests |
| Rendering | plain text only | API/UI HTML-shaped text regression tests |

## Capacity behavior

The current ruleset contains a generic map keyed by a published, storable, non-food resource. Authoring fails closed for unknown keys, food resources, missing overflow semantics, or money conversion. Money and aggregate food retain their existing meanings and paths.

Sale policy is evaluated before the individual cap. `sell_all` and `keep_amount` can therefore consume inventory before enforcement; `stockpile` still clamps at the cap. Each discarded excess records the resource key, before amount, capacity, after amount, overflow, source, and discarded disposition. The write and event share the turn transaction, so a failed run leaves neither.

## Profile data and API

`nations.owner_name` is a required application-level value for every new current-ruleset Nation and is limited to 1–30 characters. `nations.profile_comment` is optional and limited to 0–100 characters. Database defaults are empty strings solely so schema application does not infer personal data for pre-existing rows; current registration never substitutes an OAuth display name.

Both fields are single-line plain text. Unicode control/format characters, line separators, paragraph separators, and line breaks are rejected. Unicode edge spaces are trimmed. HTML, Markdown, and URLs are neither rendered nor expanded.

`PATCH /api/v1/nations/{nation}/profile` accepts either or both public fields, requires the Nation owner membership, locks the World and Nation, and applies the current-ruleset mutation guard. A real change records `nation.profile_updated` with changed fields, before/after values, Nation identity, actor user ID, and timestamp. A no-op records nothing. Authentication-provider IDs, OAuth names, email addresses, tokens, and credentials are excluded.

## Public and private display

The public lobby ranking and public Nation preview show the island name, island owner, and optional comment. The owner HUD shows the same profile plus exact resource stock and individual caps. Public endpoints do not expose exact money, inventory, capacity, remaining capacity, private facilities, or raw audit metadata.

## Migration and reset policy

The profile schema migration adds only the two text columns with non-personal empty defaults. The forward-only PR19 ruleset publication verifies the exact source catalog metadata, publishes a new immutable payload, and updates the global display metadata for the two resource definitions. It does not update `worlds.ruleset_version_id`, balances, commands, queues, Nations, or turn runs.

## PR22 canonical rebaseline checklist

Before public release, PR22 must rebaseline a fresh canonical schema and remove pre-release migration history only under a separately approved plan. That plan must preserve immutable published ruleset audit records, include the current PR19 resource metadata and Nation profile columns in the fresh schema, and resolve the remaining D-07 moderation gate: prohibited terms, impersonation, reports, hide/freeze/unfreeze actions, appeals, operator authorization, and moderation-log retention. PR19 does not implement those deferred operations.
