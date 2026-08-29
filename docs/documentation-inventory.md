# Repository documentation inventory

> Baseline: `release/3.0.0-alpha` at exact `origin/main` `8e57ef517cf2fcb531231cdf8c6df04a5812ae92` (2026-08-29)
> Scope: tracked Markdown after this navigation change; `_references/`, dependency/vendor output, build artifacts, and third-party originals are excluded.
> Current application evidence: `product/config/hakoniwa.php` loads application `3.0.0-alpha.1` and immutable surface Ruleset `hakoniwa-2s-plus-v18`; the current entrypoint, authoring guide, Item catalog, integrated handoff, current migrations, ADRs, runtime code, and Git history were cross-checked.

This file is a **navigation inventory, not a gameplay, Ruleset, schema, migration, or operations authority**.
Classification does not promote a Markdown file above current reviewed code or the immutable Ruleset.
Dates and filenames were not used as a substitute for content, code, Ruleset, ADR, handoff, and history checks.

## Classification summary

| Classification | Count |
|---|---:|
| CURRENT AUTHORITY | 24 |
| CURRENT INDEX / HANDOFF | 6 |
| MIXED / PARTIALLY CURRENT | 9 |
| HISTORICAL IMPLEMENTATION | 48 |
| AUDIT / REFERENCE ANALYSIS | 44 |
| OPERATIONS | 7 |
| FUTURE / ROADMAP | 11 |
| PLAYER-FACING | 12 |
| UNKNOWN / CONFLICT | 6 |
| **Total** | **167** |

`Default read` uses only these values:

- `STARTUP`: normal agent startup sequence.
- `TASK-SPECIFIC`: read when the task touches the named domain.
- `ONLY WHEN NEEDED`: supporting index or player-facing material selected for the task.
- `DO NOT USE AS CURRENT AUTHORITY`: mixed-era material, history, audit, future proposal, or unresolved conflict; inspect only for its stated purpose.

## Root and `docs/`

| Path | Classification | Default read | Scope/version | Authority / purpose | Notes |
|---|---|---|---|---|---|
| `AGENTS.md` | CURRENT AUTHORITY | STARTUP | Current repository policy | Agent scope, safety, test, review, migration, Ruleset policy | Does not override code / published Ruleset semantics. |
| `LICENSING.md` | CURRENT AUTHORITY | TASK-SPECIFIC | Current legal status | Repository licensing status | License remains undecided as stated. |
| `README.md` | UNKNOWN / CONFLICT | DO NOT USE AS CURRENT AUTHORITY | Mixed early MVP/current | Repository overview | Says production/consumption and combat are unimplemented; current code and 2.8.0 handoff show otherwise. |
| `THIRD_PARTY_NOTICES.md` | CURRENT AUTHORITY | TASK-SPECIFIC | Current provenance notice | Third-party acknowledgements and repository distribution boundary | Pair with license/provenance audit for asset work. |
| `docs/README.md` | CURRENT INDEX / HANDOFF | STARTUP | Current | Progressive documentation navigation | Navigation only; does not restate specifications. |
| `docs/documentation-inventory.md` | CURRENT INDEX / HANDOFF | ONLY WHEN NEEDED | Current inventory | Per-file role and default-read map | This table is not authority. |
| `docs/open-questions.md` | CURRENT INDEX / HANDOFF | STARTUP | Current design gates | Decided/Open/Deferred decision index | Follow linked ADR/record; stop at reached Open gate. |
| `docs/architecture/authentication-and-identities.md` | UNKNOWN / CONFLICT | DO NOT USE AS CURRENT AUTHORITY | Mixed MVP and implemented auth | Early auth domain design | Opening/undecided sections conflict with its later implemented package note, ADR-0006, and code. |
| `docs/architecture/capital-and-territory.md` | MIXED / PARTIALLY CURRENT | DO NOT USE AS CURRENT AUTHORITY | Current invariants plus v5/MVP/proposal material | Capital, territory, damage rationale | Starts with active invariants but mixes v5 values, unadopted recovery/emergency proposals, data-model ideas, and MVP history. Use current Ruleset, code, ADR-0014, and current Decisions first. |
| `docs/architecture/chunk-storage.md` | MIXED / PARTIALLY CURRENT | DO NOT USE AS CURRENT AUTHORITY | Initial storage contract with retained invariants | Chunk arithmetic and early schema/API design | `chunk_size = 16` arithmetic remains useful, but turn/event/outbox and lock policy are described as future. Use ADR-0003, current schema, and `MapChunkService` first. |
| `docs/architecture/command-api.md` | HISTORICAL IMPLEMENTATION | DO NOT USE AS CURRENT AUTHORITY | Roadmap PR2 | Queue-only API contract at that checkpoint | Current command execution exists; use code / v18 definitions. |
| `docs/architecture/configuration-management.md` | MIXED / PARTIALLY CURRENT | DO NOT USE AS CURRENT AUTHORITY | Immutable-policy rationale plus v1-v9/MVP state | Ruleset/configuration history and retained principles | Correct immutability guidance coexists with “current v5”, v6-v9 migration history, unimplemented economy claims, and future admin proposals. Start with v18 config, authoring guide, baseline, and code. |
| `docs/architecture/initial-ocean-and-island-generation.md` | CURRENT AUTHORITY | TASK-SPECIFIC | Current foundational contract | World/Nation initial generation | Current Ruleset and generator code own exact values. |
| `docs/architecture/monster-system.md` | MIXED / PARTIALLY CURRENT | DO NOT USE AS CURRENT AUTHORITY | PR21 foundation with later additions and v5 framing | Monster responsibility and execution rationale | Retained actor/occupancy ideas coexist with “current v5”, PR21/v1-v10 catalog, and checkpoint observability text. Start with v18 fragments, canonical services, schema, and ADR-0007. |
| `docs/architecture/mvp-implementation.md` | HISTORICAL IMPLEMENTATION | DO NOT USE AS CURRENT AUTHORITY | Initial MVP | Early implementation topology | Later releases substantially expanded the application. |
| `docs/architecture/nation-lifecycle.md` | UNKNOWN / CONFLICT | DO NOT USE AS CURRENT AUTHORITY | ver 2.4.0 / v13 framing | Dormancy/KARMA architecture summary | Calls v13 current; application is v18. Start with ADR-0014/0015, v18 fragment, and code. |
| `docs/architecture/public-lobby-and-island-dashboard.md` | HISTORICAL IMPLEMENTATION | DO NOT USE AS CURRENT AUTHORITY | Roadmap PR5 | Public lobby/dashboard checkpoint | Current UI/API may retain parts; verify code. |
| `docs/architecture/registration-and-world-expansion.md` | MIXED / PARTIALLY CURRENT | DO NOT USE AS CURRENT AUTHORITY | Pre-implementation design plus MVP/PR19/expansion record | Registration and expansion rationale | Implemented flow is interleaved with “decide before Nation creation”, unimplemented economy, candidate algorithms, and MVP wording. Start with v18 world fragment and current registration/expansion code. |
| `docs/architecture/roadmap-pr2-systems.md` | HISTORICAL IMPLEMENTATION | DO NOT USE AS CURRENT AUTHORITY | Roadmap PR2 | Command/facility/map checkpoint contract | Explicitly excluded execution later implemented. |
| `docs/architecture/target-architecture.md` | CURRENT AUTHORITY | TASK-SPECIFIC | Foundational architecture | Domain boundaries and design goals | Not approval to implement named future systems. |
| `docs/architecture/underground-combat-laboratory.md` | CURRENT AUTHORITY | TASK-SPECIFIC | `secretary-underground-alpha-v0` PR101〜PR104 and player-inaccessible alpha-v1 PR105 | Pure combat/build laboratory, Secretary-owned persistence/runtime, first-player Tutorial, versioned build/status/AI/equipment simulation contract | Does not define manual combat, player skill/equipment persistence/UI, formal shop, normal hunt/Trial content, facility implementation/effects, or surface bridge. |
| `docs/architecture/turn-pipeline.md` | HISTORICAL IMPLEMENTATION | DO NOT USE AS CURRENT AUTHORITY | Initial pipeline proposal | Early Turn design rationale | Header says phase table is not implementation authority. |
| `docs/architecture/turn-randomness.md` | MIXED / PARTIALLY CURRENT | DO NOT USE AS CURRENT AUTHORITY | T-01 algorithm plus pre-missile/scaffold scope | Deterministic RNG rationale and fixed vector | Core derivation may remain valid, but the document says required phases stay stubs and missile execution is not connected. Start with current Turn/RNG code and Ruleset; use this for algorithm provenance. |
| `docs/architecture/turn-runner-scaffold.md` | UNKNOWN / CONFLICT | DO NOT USE AS CURRENT AUTHORITY | Mixed PR7 scaffold and later additions | TurnRun schema/lock/retry history | Still states required phases are stubs and production returns non-zero; current runtime implements them. |
| `docs/architecture/ui-and-map-loading.md` | MIXED / PARTIALLY CURRENT | DO NOT USE AS CURRENT AUTHORITY | x/y migration and PR21 UI contract | Map API/projection and renderer rationale | Useful parity/viewer-safety material coexists with queue-only scope and a whole-world 60x60 footprint despite supported expansion. Start with current API/Vue code and ADR-0003. |
| `docs/architecture/world-and-map-space.md` | MIXED / PARTIALLY CURRENT | DO NOT USE AS CURRENT AUTHORITY | Coordinate migration checkpoint | World/MapSpace/storage rationale | Canonical x/y remains active, but expansion and TurnRunner are described as future/unimplemented. Start with ADR-0003, v18 fragment, current schema, and runtime services. |
| `docs/architecture/world-expansion-foundation.md` | UNKNOWN / CONFLICT | DO NOT USE AS CURRENT AUTHORITY | ver 1.5.0 expansion plus mixed lifecycle text | Expansion invariants and history | Says automatic dormancy/abandonment is unimplemented; ADR-0014 and code implement it. |
| `docs/assets/hakoniwa-original-tile-inventory.md` | AUDIT / REFERENCE ANALYSIS | DO NOT USE AS CURRENT AUTHORITY | Legacy asset inventory | Original image inventory | Not the current manifest or distribution authority. |
| `docs/assets/tile-asset-mapping.md` | MIXED / PARTIALLY CURRENT | DO NOT USE AS CURRENT AUTHORITY | Initial design plus PR21 and later mapping notes | Asset mapping/provenance rationale | Candidate IDs, PR-era mappings, proposed delivery checks, and unresolved choices coexist with implemented entries. Use `AssetManifestResolver` and current config as exact current mapping. |
| `docs/decisions/ADR-0002-reference-integration-policy.md` | CURRENT AUTHORITY | TASK-SPECIFIC | Accepted | Reference integration policy | `_references/` remains read-only. |
| `docs/decisions/ADR-0003-hex-coordinate-system.md` | CURRENT AUTHORITY | TASK-SPECIFIC | Accepted | Canonical staggered x/y coordinate decision | Active. |
| `docs/decisions/ADR-0004-nation-dormancy-lifecycle.md` | HISTORICAL IMPLEMENTATION | DO NOT USE AS CURRENT AUTHORITY | Superseded | Earlier dormancy proposal | Explicitly superseded by ADR-0014. |
| `docs/decisions/ADR-0005-authentication-identities.md` | CURRENT AUTHORITY | TASK-SPECIFIC | Accepted | User/external identity separation | Active. |
| `docs/decisions/ADR-0006-oauth-packages.md` | CURRENT AUTHORITY | TASK-SPECIFIC | Accepted | OAuth packages/session/origin | Active; resolves stale auth architecture sections. |
| `docs/decisions/ADR-0007-monster-actor-and-occupancy.md` | CURRENT AUTHORITY | TASK-SPECIFIC | Accepted | Monster actor/occupancy boundary | Active. |
| `docs/decisions/ADR-0008-first-production-release.md` | CURRENT AUTHORITY | TASK-SPECIFIC | Accepted | Go-live, data, deploy, backup boundary | Active production-safety decision. |
| `docs/decisions/ADR-0009-ruleset-v2-missile-targeting.md` | CURRENT AUTHORITY | TASK-SPECIFIC | Accepted/inherited | Explicit missile target contract | Current Ruleset/code own later additions. |
| `docs/decisions/ADR-0009-ver-1.3.0-awards-and-classic-top.md` | CURRENT AUTHORITY | TASK-SPECIFIC | Accepted; projection amended later | Nation awards and classic TOP decision | Duplicate ADR number; use full path. C3 supersedes only noted projection detail. |
| `docs/decisions/ADR-0010-product-generations-and-2x-identity.md` | CURRENT AUTHORITY | TASK-SPECIFIC | Accepted | Product generation / 2.x identity boundary | Active; roadmap names are not automatic approval. |
| `docs/decisions/ADR-0011-secretary-v1-contract.md` | CURRENT AUTHORITY | TASK-SPECIFIC | Accepted ver 2.0.0 | Secretary foundation | Later ADRs and current Ruleset extend it. |
| `docs/decisions/ADR-0012-ver-2.1.0-defense-and-secretary-rename.md` | CURRENT AUTHORITY | TASK-SPECIFIC | Accepted ver 2.1.0 | Missile defense and rename decision | Active where not superseded. |
| `docs/decisions/ADR-0013-ver-2.1.3-resolution-and-legacy-queue.md` | CURRENT AUTHORITY | TASK-SPECIFIC | Accepted ver 2.1.3 | Monster resolution / queue residue decision | Active provenance/integrity contract. |
| `docs/decisions/ADR-0014-ver-2.4.0-nation-dormancy.md` | CURRENT AUTHORITY | TASK-SPECIFIC | Accepted ver 2.4.0 | Current dormancy/abandonment decision | Supersedes ADR-0004. |
| `docs/decisions/ADR-0015-ver-2.4.0-karma-recovery.md` | CURRENT AUTHORITY | TASK-SPECIFIC | Accepted ver 2.4.0 | KARMA/recovery decision | Current Ruleset owns later values/additions. |
| `docs/decisions/ADR-0016-ver-2.5.0-secretary-profile.md` | CURRENT AUTHORITY | TASK-SPECIFIC | Accepted ver 2.5.0 | Secretary profile/capacity decision | Active where inherited. |
| `docs/future-systems/deployment-integration.md` | FUTURE / ROADMAP | DO NOT USE AS CURRENT AUTHORITY | Future | Deployment integration proposal | Current production operations require current runbooks/Owner approval. |
| `docs/future-systems/event-log-and-notifications.md` | FUTURE / ROADMAP | DO NOT USE AS CURRENT AUTHORITY | Future | Event/outbox/notification proposal | Some event foundations exist; future delivery is not approved by this file. |
| `docs/future-systems/mariachang-integration.md` | FUTURE / ROADMAP | DO NOT USE AS CURRENT AUTHORITY | Future | Mariachang integration idea | Requires separate Owner-approved roadmap. |
| `docs/future-systems/meteor-items-and-placeables.md` | FUTURE / ROADMAP | DO NOT USE AS CURRENT AUTHORITY | Future | Meteor Item/placeable idea | Not current gameplay. |
| `docs/future-systems/meteor-targeting.md` | FUTURE / ROADMAP | DO NOT USE AS CURRENT AUTHORITY | Future | Meteor targeting idea | Not current gameplay. |
| `docs/future-systems/modifiers.md` | FUTURE / ROADMAP | DO NOT USE AS CURRENT AUTHORITY | Deferred generic system | Generic Modifier proposal | Current local modifier contracts do not authorize this framework. |
| `docs/future-systems/post-release-backlog.md` | FUTURE / ROADMAP | DO NOT USE AS CURRENT AUTHORITY | Future backlog | Post-release candidates | Recheck handoff/Open gates; items may be done, changed, or deferred. |
| `docs/future-systems/proficiency-and-research.md` | FUTURE / ROADMAP | DO NOT USE AS CURRENT AUTHORITY | Future | Proficiency/research proposal | Not current approval. |
| `docs/future-systems/resources.md` | FUTURE / ROADMAP | DO NOT USE AS CURRENT AUTHORITY | Mixed future resource design | Resource extension proposal | Existing resources are current code/Ruleset; future portions are not authority. |
| `docs/operations/docker-compose.md` | OPERATIONS | TASK-SPECIFIC | Current local/default plus production boundary | Compose operations and data-volume safety | Current production topology must be reverified; never use `down -v` casually. |
| `docs/operations/existing-server-context.md` | HISTORICAL IMPLEMENTATION | DO NOT USE AS CURRENT AUTHORITY | Pre-integration future plan | Earlier server integration boundary | Handoff says production integration/deploy later occurred. |
| `docs/operations/hotfix-1.1.1-live-ruleset-references.md` | HISTORICAL IMPLEMENTATION | DO NOT USE AS CURRENT AUTHORITY | hotfix 1.1.1 | One historical repair operation | Do not execute as a current generic procedure. |
| `docs/operations/local-development.md` | OPERATIONS | TASK-SPECIFIC | Current local workflow | Local Compose/test workflow | Environment/timing examples may drift; verify current files. |
| `docs/operations/moderation-records.md` | OPERATIONS | TASK-SPECIFIC | Current | Initial moderation record operation | Does not authorize gameplay state changes. |
| `docs/operations/oauth-setup.md` | OPERATIONS | TASK-SPECIFIC | Current | Discord/Google OAuth setup | Keep secrets outside Git. |
| `docs/operations/turn-cron.md` | OPERATIONS | TASK-SPECIFIC | Current | Turn trigger, status, manual retry, incident boundary | Current deployed state and TurnRun must be reverified. |
| `docs/operations/world-reset.md` | OPERATIONS | TASK-SPECIFIC | Current local/testing; pre-go-live production history | Local/testing reset safety | Production reset is prohibited after go-live. Pre-go-live sequence is historical only. |
| `docs/requirements/initial-game-direction.md` | HISTORICAL IMPLEMENTATION | DO NOT USE AS CURRENT AUTHORITY | 2026-07-26 initial MVP | Original requirements record | Explicitly historical; many exclusions were later implemented. |
| `docs/roadmap-pr4-staggered-xy.md` | HISTORICAL IMPLEMENTATION | DO NOT USE AS CURRENT AUTHORITY | Roadmap PR4 | Coordinate migration checkpoint | Current authority is ADR-0003/code. |
| `docs/roadmap/2.x.md` | FUTURE / ROADMAP | DO NOT USE AS CURRENT AUTHORITY | Mixed completed sequencing and future candidates | 2.x roadmap boundaries | Not automatic approval; handoff/current code determine completed work. |
| `docs/roadmap/3.0.0-alpha-underground.md` | FUTURE / ROADMAP | TASK-SPECIFIC | Active Underground alpha sequencing | Owner-approved roadmap, PR101〜PR105 scope, reached gates, PR sequence, first-playable conditions | Approval is slice-specific; PR105 is DB-free/player-inaccessible, while UG-04 and player skill/equipment/shop access remain gated. |
| `docs/testing/staggered-xy-test-plan.md` | HISTORICAL IMPLEMENTATION | DO NOT USE AS CURRENT AUTHORITY | PR4 migration | Coordinate transition test plan | Current test contract is repository tests/policy. |

### `docs/reference-analysis/`

All entries in this table are read-only analysis inputs. They are never current runtime authority.

| Path | Classification | Default read | Scope/version | Authority / purpose | Notes |
|---|---|---|---|---|---|
| `docs/reference-analysis/comparison-matrix.md` | AUDIT / REFERENCE ANALYSIS | DO NOT USE AS CURRENT AUTHORITY | Legacy comparison | Cross-reference matrix | Use only for comparative investigation. |
| `docs/reference-analysis/h2-realignment-and-world-expansion-audit.md` | AUDIT / REFERENCE ANALYSIS | DO NOT USE AS CURRENT AUTHORITY | H2 / ver 1.5-era audit | Realignment/expansion evidence | Later current code/Ruleset wins. |
| `docs/reference-analysis/hakoniwa-2-vs-2plus-spec-audit.md` | AUDIT / REFERENCE ANALYSIS | DO NOT USE AS CURRENT AUTHORITY | Legacy variants | Specification provenance audit | Does not decide new gameplay. |
| `docs/reference-analysis/hakoniwa-2plus-build.md` | AUDIT / REFERENCE ANALYSIS | DO NOT USE AS CURRENT AUTHORITY | Legacy source | Build/runtime analysis | Reference only. |
| `docs/reference-analysis/hakoniwa-2plus-call-graph.md` | AUDIT / REFERENCE ANALYSIS | DO NOT USE AS CURRENT AUTHORITY | Legacy source | Call-graph analysis | Reference only. |
| `docs/reference-analysis/hakoniwa-2plus-configuration.md` | AUDIT / REFERENCE ANALYSIS | DO NOT USE AS CURRENT AUTHORITY | Legacy source | Configuration analysis | Values are not current Ruleset values. |
| `docs/reference-analysis/hakoniwa-2plus-data-format.md` | AUDIT / REFERENCE ANALYSIS | DO NOT USE AS CURRENT AUTHORITY | Legacy source | Persistence-format analysis | Not current schema authority. |
| `docs/reference-analysis/hakoniwa-2plus-facilities.md` | AUDIT / REFERENCE ANALYSIS | DO NOT USE AS CURRENT AUTHORITY | Legacy source | Terrain/facility analysis | Compare only when scope requires. |
| `docs/reference-analysis/hakoniwa-2plus-modernization-gaps.md` | AUDIT / REFERENCE ANALYSIS | DO NOT USE AS CURRENT AUTHORITY | Legacy/new concept gap | Modernization analysis | Ideas are not approved scope. |
| `docs/reference-analysis/hakoniwa-2plus-new-island.md` | AUDIT / REFERENCE ANALYSIS | DO NOT USE AS CURRENT AUTHORITY | Legacy source | Registration/new-island analysis | Current registration code/ADR wins. |
| `docs/reference-analysis/hakoniwa-2plus-open-questions.md` | AUDIT / REFERENCE ANALYSIS | DO NOT USE AS CURRENT AUTHORITY | Legacy analysis | Questions found in reference source | Not the current gate index. |
| `docs/reference-analysis/hakoniwa-2plus-overview.md` | AUDIT / REFERENCE ANALYSIS | DO NOT USE AS CURRENT AUTHORITY | Legacy source | Static-analysis overview | Reference only. |
| `docs/reference-analysis/hakoniwa-2plus-turn-processing.md` | AUDIT / REFERENCE ANALYSIS | DO NOT USE AS CURRENT AUTHORITY | Legacy source | Turn-order/source evidence | Current phase order is code/Ruleset. |
| `docs/reference-analysis/hakoniwa-2plus-world-map.md` | AUDIT / REFERENCE ANALYSIS | DO NOT USE AS CURRENT AUTHORITY | Legacy source | Shared-world/map evidence | Current ADR/code wins. |
| `docs/reference-analysis/license-and-provenance.md` | AUDIT / REFERENCE ANALYSIS | DO NOT USE AS CURRENT AUTHORITY | Reference provenance | License/source evidence | Pair with current legal notices. |
| `docs/reference-analysis/source-inventory.md` | AUDIT / REFERENCE ANALYSIS | DO NOT USE AS CURRENT AUTHORITY | Reference corpus | Investigation entrypoint | `_references/` remains read-only. |
| `docs/reference-analysis/yamanity-administration.md` | AUDIT / REFERENCE ANALYSIS | DO NOT USE AS CURRENT AUTHORITY | yamanity | Administration analysis | Architecture inspiration only. |
| `docs/reference-analysis/yamanity-api.md` | AUDIT / REFERENCE ANALYSIS | DO NOT USE AS CURRENT AUTHORITY | yamanity | API analysis | Not current API authority. |
| `docs/reference-analysis/yamanity-architecture.md` | AUDIT / REFERENCE ANALYSIS | DO NOT USE AS CURRENT AUTHORITY | yamanity | Laravel responsibility analysis | Not target architecture. |
| `docs/reference-analysis/yamanity-auth-and-registration.md` | AUDIT / REFERENCE ANALYSIS | DO NOT USE AS CURRENT AUTHORITY | yamanity | Auth/registration analysis | Current ADR/code wins. |
| `docs/reference-analysis/yamanity-commands.md` | AUDIT / REFERENCE ANALYSIS | DO NOT USE AS CURRENT AUTHORITY | yamanity | Command analysis | Not current command contract. |
| `docs/reference-analysis/yamanity-data-model.md` | AUDIT / REFERENCE ANALYSIS | DO NOT USE AS CURRENT AUTHORITY | yamanity | Data-model analysis | Not current schema authority. |
| `docs/reference-analysis/yamanity-directory-structure.md` | AUDIT / REFERENCE ANALYSIS | DO NOT USE AS CURRENT AUTHORITY | yamanity | Directory analysis | Reference only. |
| `docs/reference-analysis/yamanity-docker-and-operations.md` | AUDIT / REFERENCE ANALYSIS | DO NOT USE AS CURRENT AUTHORITY | yamanity | Docker/operations analysis | Not current runbook. |
| `docs/reference-analysis/yamanity-facilities-and-events.md` | AUDIT / REFERENCE ANALYSIS | DO NOT USE AS CURRENT AUTHORITY | yamanity | Facility/event analysis | Not current gameplay. |
| `docs/reference-analysis/yamanity-json-storage.md` | AUDIT / REFERENCE ANALYSIS | DO NOT USE AS CURRENT AUTHORITY | yamanity | JSON storage analysis | Not current persistence contract. |
| `docs/reference-analysis/yamanity-license.md` | AUDIT / REFERENCE ANALYSIS | DO NOT USE AS CURRENT AUTHORITY | yamanity | Third-party license analysis | Current notices remain separate. |
| `docs/reference-analysis/yamanity-logs.md` | AUDIT / REFERENCE ANALYSIS | DO NOT USE AS CURRENT AUTHORITY | yamanity | Log/history analysis | Not current event authority. |
| `docs/reference-analysis/yamanity-open-questions.md` | AUDIT / REFERENCE ANALYSIS | DO NOT USE AS CURRENT AUTHORITY | yamanity | Reference open questions | Not `docs/open-questions.md`. |
| `docs/reference-analysis/yamanity-overview.md` | AUDIT / REFERENCE ANALYSIS | DO NOT USE AS CURRENT AUTHORITY | yamanity | Static-analysis overview | Reference only. |
| `docs/reference-analysis/yamanity-tests.md` | AUDIT / REFERENCE ANALYSIS | DO NOT USE AS CURRENT AUTHORITY | yamanity | Test/maintainability analysis | Not current test contract. |
| `docs/reference-analysis/yamanity-turn-processing.md` | AUDIT / REFERENCE ANALYSIS | DO NOT USE AS CURRENT AUTHORITY | yamanity | Turn-processing analysis | Not current Turn order. |
| `docs/reference-analysis/yamanity-ui.md` | AUDIT / REFERENCE ANALYSIS | DO NOT USE AS CURRENT AUTHORITY | yamanity | UI/map analysis | Not current frontend authority. |

## `product/` documentation

| Path | Classification | Default read | Scope/version | Authority / purpose | Notes |
|---|---|---|---|---|---|
| `product/README.md` | CURRENT INDEX / HANDOFF | ONLY WHEN NEEDED | Current application entrypoint | Product commands and code entrypoints | Use task-specific guide/runbook for details. |
| `product/config/hakoniwa/rulesets/README.md` | UNKNOWN / CONFLICT | DO NOT USE AS CURRENT AUTHORITY | Mixed v17/current wording | Ruleset directory note | Says config/tests/validator load current v17 and gives a v17 command; config and current docs load v18. |
| `product/docs/architecture/current-ruleset-baseline.md` | CURRENT AUTHORITY | TASK-SPECIFIC | ver 2.8.0 / v18 | Current Ruleset, install, supported source boundary | Code/migration/checksum remain higher authority. |
| `product/docs/architecture/install-upgrade-rebaseline.md` | HISTORICAL IMPLEMENTATION | DO NOT USE AS CURRENT AUTHORITY | ver 2.4.0 origin; later boundary notes | Historical install/upgrade architecture | Header marks it historical; mixed v11-v18 eras are not one current procedure. |
| `product/docs/architecture/modifier-stacking.md` | CURRENT AUTHORITY | TASK-SPECIFIC | Current bounded modifier contract | Implemented percentage stacking/rounding | Does not approve deferred generic Modifier framework. |
| `product/docs/architecture/ruleset-authoring.md` | CURRENT AUTHORITY | TASK-SPECIFIC | Current v18 | Behavior/Data/Flavor authoring authority | Current code/immutable payload still take precedence. |
| `product/docs/architecture/ruleset-configuration-layers.md` | HISTORICAL IMPLEMENTATION | DO NOT USE AS CURRENT AUTHORITY | Historical 2.3.1 / v11 map | Core/Balance/Flavor responsibility history | Superseded classification terminology; use ruleset-authoring. |
| `product/docs/architecture/ruleset-runtime-retirement.md` | HISTORICAL IMPLEMENTATION | DO NOT USE AS CURRENT AUTHORITY | ver 2.6.1 retirement inventory, v17 wording | Historical executable-runtime deletion proof | Present-tense v17 identity is stale under v18; role remains historical. |
| `product/docs/archive/ruleset-history.md` | HISTORICAL IMPLEMENTATION | DO NOT USE AS CURRENT AUTHORITY | Pre-MVP through v11 | Human Ruleset history index | Not application instructions or payload source. |
| `product/docs/archive/rulesets/formal-history.md` | HISTORICAL IMPLEMENTATION | DO NOT USE AS CURRENT AUTHORITY | Formal v1-v15 | Historical formal Ruleset index | Recorded Git/DB source is authoritative for exact history. |
| `product/docs/archive/rulesets/index.md` | HISTORICAL IMPLEMENTATION | DO NOT USE AS CURRENT AUTHORITY | Archive captured around v16 | Ruleset archive entrypoint | Embedded “current v16” is historical; not current v18 identity. |
| `product/docs/archive/rulesets/roadmap-history.md` | HISTORICAL IMPLEMENTATION | DO NOT USE AS CURRENT AUTHORITY | Roadmap Rulesets | Development-stage Ruleset history | Never formal production payload authority. |
| `product/docs/command-audit-pr14.md` | AUDIT / REFERENCE ANALYSIS | DO NOT USE AS CURRENT AUTHORITY | PR14 | Non-combat command audit | Use for provenance/regression only. |
| `product/docs/command-audit-pr22.md` | AUDIT / REFERENCE ANALYSIS | DO NOT USE AS CURRENT AUTHORITY | PR22 | Command/queue/missile/Turn-log audit | Current runtime/Ruleset wins. |
| `product/docs/community-guidelines.md` | PLAYER-FACING | TASK-SPECIFIC | Current served page | Community rules | Served by application; not internal architecture authority. |
| `product/docs/disaster-oil-audit-pr15.md` | AUDIT / REFERENCE ANALYSIS | DO NOT USE AS CURRENT AUTHORITY | PR15 | Disaster/oil pre-implementation audit | Contains PR15-era historical contracts. |
| `product/docs/handoffs/development-history-and-current-handoff.md` | CURRENT INDEX / HANDOFF | STARTUP | Current integrated handoff | Current location, release history, Owner context | Read-only unless Owner explicitly requests an update; below code/Ruleset/ADR. |
| `product/docs/items/current-item-catalog.md` | CURRENT INDEX / HANDOFF | TASK-SPECIFIC | ver 2.8.0 / v18 | Implemented Item navigation index | Values are indexed from current code/Ruleset; not a future idea list. |
| `product/docs/land-subsidence-audit-pr18.md` | AUDIT / REFERENCE ANALYSIS | DO NOT USE AS CURRENT AUTHORITY | PR18 | Land-subsidence audit | Provenance/regression only. |
| `product/docs/manual/advanced.md` | PLAYER-FACING | TASK-SPECIFIC | Current served manual | Advanced player guidance | Player-facing, not internal processing authority. |
| `product/docs/manual/beginner.md` | PLAYER-FACING | TASK-SPECIFIC | Current served manual | Beginner guidance | Player-facing. |
| `product/docs/manual/index.md` | PLAYER-FACING | TASK-SPECIFIC | Current served manual | Manual entrypoint | Player-facing. |
| `product/docs/manual/intermediate.md` | PLAYER-FACING | TASK-SPECIFIC | Current served manual | Intermediate guidance | Player-facing. |
| `product/docs/manual/secretary.md` | PLAYER-FACING | TASK-SPECIFIC | Current served manual | Secretary player guidance | Use Item catalog/code for internal contract. |
| `product/docs/manual/trading-post.md` | PLAYER-FACING | TASK-SPECIFIC | Current served manual | Trading Post player guidance | Internal settlement authority remains code/Ruleset. |
| `product/docs/message-board-secret-communication-ver-1.4.0.md` | HISTORICAL IMPLEMENTATION | DO NOT USE AS CURRENT AUTHORITY | ver 1.4.0 | Message board/secret communication release contract | Use for design history/regression only. |
| `product/docs/missile-scorch-ver-1.4.0.md` | HISTORICAL IMPLEMENTATION | DO NOT USE AS CURRENT AUTHORITY | ver 1.4.0 | Missile scorch release contract | Current missile code/Ruleset wins. |
| `product/docs/monster-audit-pr21.md` | AUDIT / REFERENCE ANALYSIS | DO NOT USE AS CURRENT AUTHORITY | PR21 | Monster source audit and implementation evidence | Current monster architecture/code/Ruleset first. |
| `product/docs/operations/database-backup-and-restore.md` | OPERATIONS | TASK-SPECIFIC | Current | PostgreSQL backup/restore and rehearsal | Reverify environment, authorization, and current deployment before use. |
| `product/docs/operations/ver-1.2.0-utc-session-and-timestamp-repair.md` | HISTORICAL IMPLEMENTATION | DO NOT USE AS CURRENT AUTHORITY | ver 1.2.0 operation | One-time timestamp repair | Historical operation; do not generalize. |
| `product/docs/operations/ver-1.3.0-monster-cycle-seed.md` | HISTORICAL IMPLEMENTATION | DO NOT USE AS CURRENT AUTHORITY | ver 1.3.0 operation | One-time monster-cycle seed runbook | Historical exact operation. |
| `product/docs/operations/ver-1.4.0-release.md` | HISTORICAL IMPLEMENTATION | DO NOT USE AS CURRENT AUTHORITY | ver 1.4.0 | Release checklist | Not a current deploy runbook. |
| `product/docs/operations/ver-1.4.1-release.md` | HISTORICAL IMPLEMENTATION | DO NOT USE AS CURRENT AUTHORITY | ver 1.4.1 | Production update checklist | Not a current deploy runbook. |
| `product/docs/operations/ver-1.5.0-world-expansion.md` | HISTORICAL IMPLEMENTATION | DO NOT USE AS CURRENT AUTHORITY | ver 1.5.0 exact 60x60-to-64x64 operation | One reviewed expansion runbook | Never reuse for another expansion without a reviewed procedure. |
| `product/docs/operations/ver-1.7.0-release-record.md` | HISTORICAL IMPLEMENTATION | DO NOT USE AS CURRENT AUTHORITY | ver 1.7.0; partial postflight | Historical production release record | Not current status. |
| `product/docs/operations/ver-1.7.0-v6-ruleset-migration.md` | HISTORICAL IMPLEMENTATION | DO NOT USE AS CURRENT AUTHORITY | ver 1.7.0 / v5-to-v6 | Historical migration runbook | Do not execute for current v18. |
| `product/docs/operations/ver-2.3.0-v11-migration.md` | HISTORICAL IMPLEMENTATION | DO NOT USE AS CURRENT AUTHORITY | ver 2.3.0 / v11 | Historical operator checklist | Explicitly says not to execute from current tree. |
| `product/docs/post-2.3.0-simplification-handoff.md` | HISTORICAL IMPLEMENTATION | DO NOT USE AS CURRENT AUTHORITY | Post-2.3.0 cleanup handoff | Historical Owner intent / investigation boundary | Later 2.4.0-2.6.1 work resolved or changed many candidates. |
| `product/docs/resource-profile-audit-pr19.md` | AUDIT / REFERENCE ANALYSIS | DO NOT USE AS CURRENT AUTHORITY | PR19 | Resource/profile audit | Some content explicitly superseded; current economy code/Ruleset wins. |
| `product/docs/territory-expansion-influence-ver-1.4.0.md` | HISTORICAL IMPLEMENTATION | DO NOT USE AS CURRENT AUTHORITY | ver 1.4.0 | Territory expansion/influence release contract | Current Ruleset/ADR/code first. |
| `product/docs/ver-1.2.0-command-input-semantics.md` | HISTORICAL IMPLEMENTATION | DO NOT USE AS CURRENT AUTHORITY | ver 1.2.0 | Command input contract | Historical release slice. |
| `product/docs/ver-1.2.0-public-island-status-projection.md` | HISTORICAL IMPLEMENTATION | DO NOT USE AS CURRENT AUTHORITY | ver 1.2.0 | Public status projection contract | Current presenter/API wins. |
| `product/docs/ver-1.3.2-announcement.md` | PLAYER-FACING | ONLY WHEN NEEDED | Historical ver 1.3.2 | Release announcement | Historical player-facing record. |
| `product/docs/ver-1.4.0-announcement.md` | PLAYER-FACING | ONLY WHEN NEEDED | Historical ver 1.4.0 | Release announcement | Historical player-facing record. |
| `product/docs/ver-1.4.1-announcement.md` | PLAYER-FACING | ONLY WHEN NEEDED | Historical ver 1.4.1 | Release announcement | Historical player-facing record. |
| `product/docs/ver-1.5.0-beta-auto-expansion-and-ruleset-v4.md` | HISTORICAL IMPLEMENTATION | DO NOT USE AS CURRENT AUTHORITY | ver 1.5.0 beta / v4 | Auto-expansion/disaster/seabed-base release slice | Current expansion/code/Ruleset wins. |
| `product/docs/ver-1.5.0-beta3-sea-edge-removal.md` | HISTORICAL IMPLEMENTATION | DO NOT USE AS CURRENT AUTHORITY | ver 1.5.0 beta.3 | Sea-edge removal release slice | Historical rationale. |
| `product/docs/ver-1.6.0-nation-lifecycle.md` | HISTORICAL IMPLEMENTATION | DO NOT USE AS CURRENT AUTHORITY | ver 1.6.0 | Manual Nation lifecycle contract | Current dormancy/KARMA ADRs extend it. |
| `product/docs/ver-1.6.1-logging-terrain-fix.md` | HISTORICAL IMPLEMENTATION | DO NOT USE AS CURRENT AUTHORITY | ver 1.6.1 | Logging terrain fix record | Historical implementation. |
| `product/docs/ver-2.1.0-defense-missile-log-audit.md` | AUDIT / REFERENCE ANALYSIS | DO NOT USE AS CURRENT AUTHORITY | ver 2.1.0 | Defense/missile-log audit | Current code/Ruleset/ADR first. |
| `product/docs/ver-2.2.0-secretary-inventory-and-inquiries.md` | HISTORICAL IMPLEMENTATION | DO NOT USE AS CURRENT AUTHORITY | ver 2.2.0 | Inventory/equipment/inquiry foundation | Current Item/profile/inquiry code extends it. |
| `product/docs/ver-2.2.1-correctness-hardening.md` | HISTORICAL IMPLEMENTATION | DO NOT USE AS CURRENT AUTHORITY | ver 2.2.1 | Correctness/idempotency/privacy release slice | Use only for retained invariant provenance. |
| `product/docs/ver-2.3.0-announcement.md` | PLAYER-FACING | ONLY WHEN NEEDED | Historical ver 2.3.0 | Release announcement | Historical player-facing record. |
| `product/docs/ver-2.3.0-c0-audit.md` | AUDIT / REFERENCE ANALYSIS | DO NOT USE AS CURRENT AUTHORITY | ver 2.3.0 C0 | Historical checkpoint audit | Temporary integration evidence. |
| `product/docs/ver-2.3.0-c1-equipment.md` | HISTORICAL IMPLEMENTATION | DO NOT USE AS CURRENT AUTHORITY | ver 2.3.0 C1 | Secretary equipment checkpoint | Current equipment work starts with current catalog/Ruleset/code. |
| `product/docs/ver-2.3.0-c2-item-effects.md` | HISTORICAL IMPLEMENTATION | DO NOT USE AS CURRENT AUTHORITY | ver 2.3.0 C2 | Item effect checkpoint | Current Item work starts with current catalog/Ruleset/code. |
| `product/docs/ver-2.3.0-c3-monster-foundation.md` | HISTORICAL IMPLEMENTATION | DO NOT USE AS CURRENT AUTHORITY | ver 2.3.0 C3 | Monster extension checkpoint | Current monster architecture/code first. |
| `product/docs/ver-2.3.0-c4-new-monsters.md` | HISTORICAL IMPLEMENTATION | DO NOT USE AS CURRENT AUTHORITY | ver 2.3.0 C4 | New-monster checkpoint | Read only for historical rationale/regression. |
| `product/docs/ver-2.3.0-v11-release.md` | HISTORICAL IMPLEMENTATION | DO NOT USE AS CURRENT AUTHORITY | ver 2.3.0 / v11 | Formal Ruleset release/conversion record | Not current Ruleset authority. |
| `product/docs/ver-2.3.1-announcement.md` | PLAYER-FACING | ONLY WHEN NEEDED | Historical ver 2.3.1 | Release announcement | Historical player-facing record. |
| `product/docs/ver-2.3.1-release.md` | HISTORICAL IMPLEMENTATION | DO NOT USE AS CURRENT AUTHORITY | ver 2.3.1 | Runtime/Ruleset/test simplification release | Later rebaseline/retirement superseded current-state claims. |
| `product/docs/ver-2.3.1-simplification-audit.md` | AUDIT / REFERENCE ANALYSIS | DO NOT USE AS CURRENT AUTHORITY | ver 2.3.1 | Historical simplification audit | Evidence for that cleanup only. |
| `product/docs/ver-2.3.1-test-rationalization.md` | HISTORICAL IMPLEMENTATION | DO NOT USE AS CURRENT AUTHORITY | ver 2.3.1 | Historical test-ownership contract | Current AGENTS/test suite own verification policy. |
| `product/docs/ver-2.4.0-compatibility-cutoff-audit.md` | AUDIT / REFERENCE ANALYSIS | DO NOT USE AS CURRENT AUTHORITY | ver 2.4.0 | Historical compatibility audit | Use for cutoff rationale, not current migration procedure. |
| `product/docs/ver-2.4.0-dormancy-winter-protection.md` | HISTORICAL IMPLEMENTATION | DO NOT USE AS CURRENT AUTHORITY | ver 2.4.0 | Dormancy/winter release record | Current ADR-0014/code/Ruleset first. |
| `product/docs/ver-2.4.0-karma-recovery.md` | HISTORICAL IMPLEMENTATION | DO NOT USE AS CURRENT AUTHORITY | ver 2.4.0 | KARMA/recovery release record | Current ADR-0015/code/Ruleset first. |
| `product/docs/ver-2.5.0-secretary-profile.md` | HISTORICAL IMPLEMENTATION | DO NOT USE AS CURRENT AUTHORITY | ver 2.5.0 | Secretary profile release slice | Current ADR-0016/code/UI first. |

## Mixed documents retained for scoped use

The nine `MIXED / PARTIALLY CURRENT` files remain useful for invariant provenance or design history, but none is a safe standalone description of the current application:

1. `docs/architecture/configuration-management.md`: immutable publication principles plus v1-v9 and MVP-era current-state claims.
2. `docs/architecture/monster-system.md`: retained actor/occupancy rationale plus PR21, v5, and v1-v10 framing.
3. `docs/architecture/ui-and-map-loading.md`: retained x/y and viewer-safety guidance plus queue-only and fixed-60x60 scope.
4. `docs/architecture/world-and-map-space.md`: retained coordinate/storage model plus pre-expansion and pre-Turn statements.
5. `docs/architecture/registration-and-world-expansion.md`: implementation records mixed with pre-implementation decisions and obsolete MVP exclusions.
6. `docs/assets/tile-asset-mapping.md`: provenance and mapping rationale mixed with candidate IDs, PR-era mappings, and unresolved delivery design.
7. `docs/architecture/capital-and-territory.md`: active invariants mixed with v5 values, proposals, model ideas, and MVP history.
8. `docs/architecture/chunk-storage.md`: active chunk arithmetic mixed with pre-Turn storage and locking scope.
9. `docs/architecture/turn-randomness.md`: retained deterministic algorithm/vector mixed with pre-missile and phase-stub scope.

For current work, follow `docs/README.md` to reach current code, the immutable Ruleset, schema, and active ADR first. Use these files only when the historical rationale or origin of a retained invariant is needed.

## Review-needed conflicts retained for follow-up

No existing file was moved, renamed, deleted, or bulk-rewritten in this inventory PR.
After reclassification, the `UNKNOWN / CONFLICT` count remains six. These files remain in place because their useful and stale portions need separate Owner-reviewed follow-up:

1. `README.md`: early unimplemented-feature statements conflict with current 2.8.0 code/handoff.
2. `docs/architecture/authentication-and-identities.md`: undecided and implemented OAuth sections coexist.
3. `docs/architecture/nation-lifecycle.md`: current-behavior summary still names v13 as current while config is v18.
4. `docs/architecture/turn-runner-scaffold.md`: current lock/retry material coexists with obsolete required-stub claims.
5. `docs/architecture/world-expansion-foundation.md`: expansion invariants coexist with obsolete “automatic dormancy unimplemented” text.
6. `product/config/hakoniwa/rulesets/README.md`: current v17 instructions conflict with the v18 config/entrypoint/authoring guide.

Possible follow-up PRs should update or split these documents one at a time, with current code/Ruleset/ADR evidence and without turning historical records into silent current specifications.
