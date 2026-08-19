# ver 2.3.0 Checkpoint 0 audit

Status: temporary release-integration evidence. This document audits baseline
`8dcf2c316c4f7e3646632e7d4e79071e7537d952`; it does not publish v11 or authorize
implementation, merge, deploy, OCI access, or production DB access. Before the final
`release/ver-2.3.0 -> main` PR, move durable contracts to their normal ADR/manual/operations
homes and remove this temporary evidence if it is no longer needed.

Audit date: 2026-08-19 (Asia/Tokyo)

## A. Baseline audit

| Check | Result | Evidence |
|---|---|---|
| remote `main` | exact requested baseline | `git ls-remote origin refs/heads/main` returned `8dcf2c316c4f7e3646632e7d4e79071e7537d952` |
| release and C0 base | both began at `7650fcaf5d024cf636af022c00ff540c0a65cfe6`; their merge-base with `main` is the exact baseline | `git ls-remote`; `git merge-base HEAD origin/main` |
| inherited release delta | two temporary contract files only; no application/docs/runtime delta | `git diff --name-status origin/main...HEAD` before C0 edits |
| application version | `2.2.1` | `product/config/hakoniwa.php:29` |
| current ruleset | `hakoniwa-2s-plus-v10` | `product/config/hakoniwa.php:25-31`; `product/tests/Feature/WorldInitializationTest.php:103` |
| v10 validation | valid: version 10, 5 resources, 13 facilities, 25 commands, 3 production definitions | `php artisan hakoniwa:ruleset:validate --key=hakoniwa-2s-plus-v10` in `hakoniwa-web` |
| v1-v10 immutability | all ten authored configs and existing ruleset migrations match `origin/main`; no C0 edits | `git diff --exit-code origin/main -- product/config/hakoniwa/rulesets/... product/database/migrations` |
| `_references` | zero diff and never written | `git diff --name-status -- _references` |
| Open gates | contract valid: 77 IDs; Decided 55, Deferred 17, Open 5; no C0-reached Open gate | `docs/scripts/validate_open_questions.py`; `docs/open-questions.md` |
| focused baseline tests | 11 tests, 103 assertions pass | v10 contract/migration and World initialization tests in testing DB |
| legacy migration chain | current HEAD `migrate:fresh` succeeds through v10 in the isolated `hakoniwa_test` DB | `APP_ENV=testing DB_DATABASE=hakoniwa_test php artisan migrate:fresh --force` |

The current v1-v10 config Git object IDs were recorded during the audit. In version order
they are `81cf13c`, `b27aff8`, `62f1e08`, `6761b00`, `e66eb8b`, `4c0efd8`, `c7b671c`,
`1fbeb33`, `8b61207`, and `42de47a`. These are verification evidence, not new checksums
written into published payloads.

## B. Current implementation map

| Area | Current fact | Primary evidence |
|---|---|---|
| command definition | v10 has one private target-Nation `monster_dispatch`, static cost 3,000, target parameter, and fixed `mecha_inora` metadata | `product/config/hakoniwa/rulesets/roadmap-pr22-v1.php:188-190` |
| quantity semantics | `SELECTOR` is selected by string `metadata.quantity_selects_catalog`; otherwise dispatch is `UNUSED` | `product/app/Application/CommandQuantitySemantics.php:18-37` |
| existing selector | only `monument_definitions` is supported; enabled rows are ordered by sort/id and the DB primary key is persisted as `quantity` | `CommandQuantitySemantics.php:50-71` |
| selector safety | registration requires an explicit option, queue label resolves it, and the generic quantity editor rejects selectors | `CommandQuantitySemantics.php:74-110` |
| command parameters | schemas accept integer/range/default only and reject unknown names and nested `quantity` | `product/app/Domain/Command/CommandParametersValidator.php:14-81` |
| queue persistence | item stores definition, target, `quantity`, parameters, request key, and fingerprint | `product/app/Application/CommandQueueService.php:218-270` |
| request identity | fingerprint contains command key, canonical parameters, quantity, requested position, ruleset key/version, and target coordinates | `CommandQueueService.php:277-314` |
| catalog/queue API | catalog returns static definition cost; queue returns selector semantics/label but not an effective item cost | `product/app/Http/Controllers/Api/CommandQueueController.php:36-178,370-440` |
| Vue | an existing non-ordinary selector is rendered as a `<select>`; queue shows `quantity_label`; definition cards show static cost | `product/resources/js/components/CommandQueuePanel.vue:120-148,517-580,619-640` |
| execution | static money validation precedes `executionCost()`; deduction uses the resolved result and success event records cost | `product/app/Application/DomesticCommandExecutor.php:72-215,403-452,501-547` |
| dispatch spawn | target must remain active; eligible populated settlement is selected deterministically; definition is hard-coded to `mecha_inora` | `product/app/Application/MonsterSpawnService.php:210-289` |
| spawn-turn defer | dispatch source is recorded and excluded for only the spawn turn | `product/app/Domain/Monster/MonsterSpawnSource.php:5-15`; `product/tests/Unit/TurnStateTest.php:82-90` |
| item storage | Item rows hold key, level, nullable slot and grant metadata; there is a partial unique Secretary/slot index | `product/database/migrations/2026_08_17_010000_create_secretary_items_and_inquiries.php:13-68` |
| item catalog/UI | only `old_bow` exists; five slots are read-only; DTO has no effect text | `product/app/Domain/Secretary/SecretaryItemCatalog.php`; `product/app/Application/SecretaryItemPresenter.php:13-55`; `product/resources/js/App.vue:1467-1488` |
| item grant | Secretary is locked; capacity, slot, unique-item, and bow category limits are enforced | `product/app/Application/SecretaryItemGrantService.php` |
| equipment mutation | no options/equip/unequip API and no optimistic version | `product/app/Http/Controllers/Api/SecretaryController.php:15-55`; `product/app/Models/Secretary.php` |
| turn snapshot | active Nation owners and four Secretary skills are batch loaded; Items are absent | `product/app/Application/SecretaryTurnService.php:21-66`; `product/app/Domain/Turn/TurnState.php:388-456` |
| phase seam | v9+ finalizes missiles, then reuses the ordinary cell order for the normal monster pass | `product/app/Application/CompleteTurnEngine.php:327-441` |
| natural monster spawn | per active Nation probability is `min(10000, owned_land_cells * 2) / 10000`; runtime demands eight definitions | `product/app/Application/MonsterSpawnService.php:41-65,119-167` |
| monster damage | damage, hardening, kill, split reward, kill stat, cycle, and nullable firing-base XP are centralized | `product/app/Application/MonsterDamageService.php:29-276` |
| removal/batch | terrain removal is rewardless and updates the active `MonsterTurnBatch`; alive damage refreshes the batch snapshot | `product/app/Application/MonsterRemovalService.php:28-190`; `product/app/Domain/Monster/MonsterTurnBatch.php:81-91` |
| huge meteor | public radius-0..2 resolver removes monsters through the rewardless terrain path and updates a shared mutable cell index | `product/app/Application/DisasterTurnService.php:672-748,946-1004` |
| initial island | Nation creation holds `WorldMutationLock` and one DB transaction; generator locks and validates the full reservation footprint but does not inspect monster occupancy | `product/app/Application/NationCreationService.php:45-188`; `product/app/Application/LegacyInspiredInitialIslandGenerator.php:19-47` |

## C. Delta from the Owner contract

No C0 production change is allowed. Future checkpoints must close these gaps:

1. The old separate-command/5,000 proposal and the superseded 1,000/5,000 explicit-parameter
   amendment conflicted with the latest decision. The task now has one command, selector values
   `1/2`, and costs `3,000/9,999`.
2. The generic selector substrate exists, but its resolver/label path is monument-specific and
   DB-ID-based. It cannot be reused unchanged.
3. Catalog, queue, and execution assume one static cost; selected effective cost is not a first-class
   result.
4. Equipment has storage/presentation but no mutation, optimistic version, turn guard, or gameplay
   snapshot.
5. v10 has no Item gameplay definitions, display order, Aoi/Zero contract, World sea spawn, water
   movement, hostless full payout, or nuclear action.
6. Current monster runtime/presentation intentionally encodes the historical eight-species boundary.

## D. Monster dispatch selector comparison

| Criterion | Generalized `quantity` selector | New explicit selector parameter |
|---|---|---|
| UI semantics | already renders a selector and excludes ordinary quantity editing | parameter renderer is numeric except the special Nation selector |
| persistence | existing non-null bounded `quantity`; reorder/cancel already preserve it | parameters JSON works but needs a new enum schema and renderer |
| request fingerprint | quantity is already canonical input | parameters are canonical too |
| historical v10 | existing rows already normalize to quantity 1 | missing parameter requires extra normalization |
| arbitrary key defense | authored numeric options resolve server-side | must add enum validation and still hide keys/cost |
| change surface | generalize catalog resolver, default, label, DTO and cost resolution | validator, schema grammar, Vue control, DTO, labels, defaults and cost resolution |

Decision: generalize `CommandQuantitySemantics::SELECTOR`. The current parameter grammar cannot
represent a ruleset-owned enumeration without a second generic feature, while selector presentation,
fingerprinting, queue persistence, and edit exclusion already exist. The implementation must remove
the monument-specific DB-ID assumption rather than copy it to monsters.

## E. Adopted selector storage contract

The v11 `monster_dispatch` definition keeps `cost_money = 3_000` because that is the real default
option cost, not a fictional minimum or placeholder. Its metadata names the authored catalog, for
example `quantity_selects_catalog = monster_dispatch_options`. The immutable v11 ruleset contains:

```text
value 1 -> mecha_inora      / メカいのら       / 3000
value 2 -> mecha_inora_zero / メカいのら零式   / 9999
```

The `value` is an explicit authored positive integer. It is not a monster-definition ID, array index,
or display order. The queue stores it in `nation_command_queue_items.quantity`. The API exposes a
default of 1 for presentation, but a v11 registration request must explicitly send the value; missing,
non-integer, disabled, or unknown values fail before mutation. Arbitrary monster keys and client costs
are unknown request fields and fail.

A narrow `MonsterDispatchOptionResolver` should return a typed option containing value, definition
key, label, and cost from the queue item's ruleset. `CommandQuantitySemantics` delegates option and
label resolution to it. Queue serialization and execution use the same resolver. Because quantity is
already fingerprinted, an exact same-value retry converges and a different value with the same request
key produces the existing conflict. Reorder/cancel never changes the stored value.

## F. v10 queued dispatch compatibility

Application-created v10 dispatch rows are quantity 1: absent quantity normalizes to 1, and dispatch is
currently `UNUSED`, which rejects any other value (`DevelopmentPlanQuantity.php:16-29` and
`CommandQuantitySemantics.php:74-89`). The DB check itself only proves 1..99, so application history
alone is insufficient for a production migration assertion.

C5 must inspect counts grouped by status, quantity, parameter-key shape, request-fingerprint nullness,
and definition ruleset. Before publication/rebind it must prove every live queued v10 dispatch has:

- source definition key `monster_dispatch` in v10;
- `quantity = 1`;
- exactly the historical integer `target_nation_id` parameter;
- a valid target snapshot and request key;
- when a fingerprint exists, the existing 64-lowercase-hex DB constraint holds and the definition is
  owned by v10; do not attempt to validate its contents by recomputation.

Any structural anomaly aborts the whole transaction for operator review. No inference from position,
cost, display order, or monster rows is permitted. Accepted rows are normalized to selector 1, rebound
to the v11 definition, and retain 3,000 cost semantics. The existing fingerprint must not be recomputed
or compared with current queue position: it contains the original requested insertion position
(`CommandQueueService.php:277-299`), while reordering changes the only persisted position, so the
original input cannot always be reconstructed.

Add a narrow nullable `request_ruleset_version_id` provenance field. The fingerprint column was added
immediately before the v10 publication migration (`2026_08_19_000000_add_command_request_fingerprint.php`):
pre-v10 upgraded rows received null, while service-created non-null rows after publication were required
to use the locked World's v10 definition. C5 therefore attributes provenance from exact source World,
definition ruleset, release chronology, request key, and the DB hash-format constraint, never from the
unrecoverable hash input. It backfills v10 for every safely attributable non-null fingerprint across
queued, completed, failed, and cancelled rows; a non-null row attached to any other definition ruleset
aborts publication. New registrations store their active ruleset. A null historical fingerprint receives
no guessed provenance and remains conflict-only under the current contract.

Duplicate handling must lock the existing request-key row before current-v11 selector validation. Only
when that row proves v10 provenance, stable command key `monster_dispatch`, and stored quantity 1 may an
omitted selector be normalized to 1 for duplicate fingerprint comparison. The candidate hash uses the
persisted immutable request ruleset rather than the rebound execution definition. Therefore the original
v10 selector-less payload and explicit value 1 can converge to `duplicate = true`; value 2 conflicts.
A missing selector for a new request or an unproved historical row still fails normal v11 validation,
and no other payload field or unknown requested position is inferred. Completed, failed, and cancelled
definitions, parameters, quantities, statuses, and fingerprints remain historical; only the new immutable
provenance field is populated where proof succeeds.

## G. Dynamic cost design

Use one narrow domain resolution path, not a generic pricing engine:

1. registration resolves the active-v11 option, returns its effective cost/shortfall, and fingerprints
   the normalized selector; it does not reserve money;
2. catalog DTO exposes two options with value, label, and effective cost; the definition's static 3,000
   remains the default display value only;
3. queue DTO resolves the stored value against the item's ruleset and returns selected monster label
   plus effective cost;
4. execution resolves the option again from the TurnRun ruleset, checks funds against that exact cost,
   deducts it through the existing resolved-cost path, and passes only the resolved definition key to
   dispatch;
5. success/failure audit metadata records selector value, resolved monster key, and effective cost;
   player visibility remains as private as current dispatch targeting.

The existing `executionCost()` seam is the correct insertion point. Static validation at
`DomesticCommandExecutor.php:403-452` must call the same result rather than checking
`CommandDefinition.cost_money` first. `MonsterSpawnService::dispatch()` accepts a resolved trusted
option/definition, never a raw request key. Insufficient funds remain an execution failure that consumes
the head item under existing queue semantics; the preview is advisory, not authority.

## H. Required schema and migration inventory

| Checkpoint | Required change | Migration boundary |
|---|---|---|
| C1 | add `secretaries.equipment_version` default 1 with positive check | forward-only; backfill existing Secretaries to 1; no Item/slot rewrite |
| C2 | add v11-shaped test ruleset fixture and Item snapshot/effect services | no production schema; Ring instances only via explicit test grant in this release |
| C3 | nullable `monster_definitions.display_order` plus non-negative check and partial unique `(ruleset_version_id, display_order)` when non-null | never backfill v1-v10 rows; publisher/model/state includes the nullable field |
| C4/C5 | selector and dynamic cost | selector uses existing quantity; add nullable `nation_command_queue_items.request_ruleset_version_id` for exact fingerprint provenance across live rebind and terminal duplicate retry; no monster key/cost column |
| C3/C4 | movement/reward/special-action contracts | no monster-instance column required; use immutable ruleset JSON |
| C5 | publish one v11 and migrate live references | one World lock/transaction, next-run guard, exact v10 source, fail-closed preflight, publication, rebind and postconditions |

The current DB `skill_key` check should not be broadened just to encode Aoi/Zero. Their water movement,
hostless reward, and nuclear action are explicit v11 system/definition contracts, not legacy exclusive
skills. C5 cannot reuse v10's exact monster-set equality assertion: command stable keys remain exactly
equal, but the target monster set must equal the eight source keys plus exactly `aoi_inora` and
`mecha_inora_zero`.

For live data C5 rebinds only queued commands, alive monsters for common stable keys, and existing
positive kill-stat rows for common stable keys. It creates no zero-count stat rows for new species.
Killed/removed instances and completed/failed/cancelled command definitions and payload history stay on
v10; the only terminal-row addition is proved immutable request-ruleset provenance. The migration
restores/verifies deferred constraints and triggers, supports an exact idempotent second run, and proves
forced failure leaves no partial v11 row or reference change.

### Historical migration dependency/freeze audit

Historical migrations currently call three mutable Application services:

| Mutable service | Historical callers | Indirect mutable dependency | ver 2.3.0 risk |
|---|---:|---|---|
| `RulesetPublisher` | 19: roadmap PR6/7/11/14/15/18/19/21/22, first-production v1, and production v2-v10 publishers | `RulesetAuthoringValidator`, Eloquent definition models, current publisher payload/state shape | C3 adding `display_order` can break early fresh migrations before that column exists, or make old eight-definition payloads fail current validation |
| `SecretaryV1MigrationSafetyGuard` | 6: Secretary v1, v7, v8, v9, Secretary Item/inquiry, and v10 | current TurnRun/World model and status scopes | changing guard/status semantics can alter old migration stopping behavior |
| `SecretaryItemGrantService` | 1: `2026_08_17_010000_create_secretary_items_and_inquiries.php:29-34` | `SecretaryItemCatalog` through constructor and `grant()` validation | C1/C2 can make a v2.2.0 migration depend on equipment-version columns, v11 ruleset definitions, new audit behavior, or changed old-bow limits |

No historical migration directly resolves a Domain catalog. The Item migration nevertheless calls
`grantStarterOldBow()`, which calls generic `grant()` with current constants and the current catalog
definition (`SecretaryItemGrantService.php:21-40`). It therefore does not freeze the historical values
`old_bow`, level 1, slot 1, grant key `starter:old_bow`, max level 1, or bow uniqueness within the
migration itself. The existing DB indexes do freeze one old bow per Secretary and one Item per occupied
slot, but do not remove the mutable service dependency.

This is not an immediate C0 blocker because the current full migration chain succeeds and C1-C5 can
preserve compatibility without editing an applied migration. It becomes an implementation gate:

1. C1 must keep `grantStarterOldBow()` independent of `equipment_version`, equip mutation services,
   World ruleset lookup, and tables created after the v2.2.0 migration. Add new equipment mutation as a
   separate service; do not retrofit it into the historical grant path.
2. C2 must keep the historical old-bow identity, max level 1, category `bow`, slot 1 starter grant and
   grant key unchanged. Adding Ring/presentation/effects must not make the historical grant require a v11
   Item-effect definition. Effects are resolved only at a v11 TurnRun snapshot.
3. C3 must keep legacy ruleset validation version-aware. v1-v10/roadmap payloads retain the exact
   historical eight contract; only v11 accepts the additive species/display-order contract. Because old
   publisher migrations execute before the new nullable column exists during `migrate:fresh`, publisher
   payload/state handling must omit `display_order` while `Schema::hasColumn` is false, accept null for
   historical rows after the column exists, and require/persist it for v11. Unconditional insertion would
   be a confirmed fresh-install defect.
4. C1-C5 must not change `SecretaryV1MigrationSafetyGuard` behavior for its historical call sites. A
   new migration guard abstraction may wrap/reuse it only with regression coverage for all six callers.
5. Every checkpoint runs isolated `migrate:fresh`, the historical Secretary migration twice/idempotently,
   and asserts exactly one `old_bow` level 1 in slot 1 with `starter:old_bow`, no Ring, no v11 effect
   execution, and unchanged historical ruleset checksums.

High-priority post-2.3.0 cleanup: create a reviewed frozen fresh-install baseline rather than rewriting
or deleting applied migrations. The smallest strategy is a PostgreSQL schema/data baseline plus a
manifest of covered migration names/hashes, with migration-local literal starter Item data and frozen
published-ruleset snapshots/checksums. Existing upgraded installations keep the append-only migration
history. CI must run both (a) the legacy chain with current code and (b) the frozen baseline followed by
post-baseline migrations, then compare schema constraints, published rows/checksums, starter Item rows,
and current World references. Only after equivalence is reviewed may new environments use the frozen
baseline. Until then, the compatibility gates above are mandatory.

## I. Equipment and Item-effect design

### C1 equipment mutation

Resolve the authenticated Secretary under a new PostgreSQL advisory `UserMembershipMutationLock` keyed
by User ID. `NationCreationService` must acquire the same lock before its target `WorldMutationLock` and
hold it through membership insertion/Secretary initialization/commit; equipment holds it before
enumerating Worlds through its own commit. Any future path that can add or reactivate an owner
membership must join this lock. The global order is user-membership lock, then World locks by ID, then
DB rows. TurnRunner takes only its World lock and never waits for the user lock, so this adds no reverse
edge.

With the membership set frozen, enumerate every owner membership whose Nation is active. The
`(user_id, world_id)` uniqueness permits one active owned Nation in each of multiple Worlds, while the
Secretary and Items are User-global. Sort all affected Worlds by ID and acquire every
`WorldMutationLock` before the transaction. If any acquisition fails, release the already-held locks and
then the user lock in reverse order and return the stable conflict without writing. Inside the
transaction, lock the same World rows and owner membership/Nation rows in stable order and verify the
frozen set. For every affected World reject a next non-dry pending/running/failed/blocked TurnRun. Then
lock Secretary and relevant Items in ID order.

If the affected set is empty, retain the user-membership lock and lock only Secretary/Items; registration
cannot create a newly affected World until the equipment transaction commits.
In both paths compare `expected_version`, construct the complete proposed five-slot state in memory,
validate ownership, slot, category and same-item limits, then write atomically. A no-op does not
increment; one meaningful mutation increments once. Return stable 409 on version conflict and a private
audit event. Release every acquired World lock and then the user-membership lock in reverse order in
`finally`.

Add server-side slot options plus equip/unequip routes. The modal performs no locking and filters only
for convenience; submission repeats all final validation. Vue must support mouse, touch, keyboard focus,
Escape/cancel, loading/error states, and the current desktop/mobile layouts. The API returns
`equipment_version` with options and mutations.

### C2 gameplay snapshot and effects

At `prepare_turn`, only when the TurnRun ruleset contains the v11 Item contract, batch-load equipped
Item identity/key/level/slot and resolve effect identity into `TurnState`. v1-v10 TurnRuns never load or
execute Item effects, including a same-seed retry after deployment.

Old Bow runs after missile finalization and before the normal-monster pass. Build all currently safe
owned-territory candidates in one joined query, grouped by active Nation. A Nation with no candidate
makes no draw. Otherwise use distinct trigger and target streams, stable-sort candidates before uniform
selection, and call `MonsterDamageService` with damage type `secretary_old_bow`, killer Nation, and
null firing base. Existing kill/reward/stat/cycle behavior applies; base XP and Secretary skill XP do not.
The safety service evaluates ruleset effect identity/hardening and resulting immediate hazards, not raw
monster keys: Zero HP2 is excluded, HP1 is legal because it dies, and HP3 is legal because it becomes 2.

Ring levels are summed from the immutable snapshot and applied once in the centralized finance path for
both explicit and automatic finance. Record base, bonus requested/applied/overflow, final amount and
capacity without duplicating player logs.

Item identity, inventory, equipment, name, and flavor remain User-global/ruleset-neutral. Effect text is
a separate presentation projection. `GET /api/v1/me/secretary` must never infer one ruleset from the
first/latest active membership. When opened from a Nation, the client supplies its explicit `world_id`;
the controller proves an active owner membership and the presenter returns an effect context containing
that World ID, exact ruleset version ID/version, and Item-level-derived sentences. Thus the same User's
v10 World projects no Item gameplay effect while their v11 World projects the v11 effect. If there is no
active membership, an omitted World may use the configured current ruleset only with an explicit
`configured_current` context marker. If any active membership exists, omission remains ruleset-neutral
and returns no effect text. Name/rename/equipment mutation responses also remain neutral and the UI
reloads the explicit World projection after mutation. Flavor stays in the application catalog.

## J. Fixed-eight and display-order design

The complete active assumptions found are:

1. `RulesetAuthoringValidator.php:619-697` requires the exact eight keys in source order and their
   legacy source tuples.
2. `MonsterSpawnService.php:45-52` rejects any definition count other than eight even though pools are
   key based.
3. `PublicWorldService.php:82-113` sorts detail stats by key and truncates to eight; the sum therefore
   omits a ninth species.
4. `PublicRankingAchievementProjection.php:101-142` requires source kind 0..7, sorts by kind, and uses
   the maximum kind species as the representative.
5. `MonsterDefinition`, the original monster migration, and `RulesetPublisher` have no display-order
   field.
6. v10 and earlier migration helpers use exact command and monster stable-key set equality; that
   monster assertion is invalid for an additive v11.
7. `MonsterRulesetContractTest.php:50`, relevant validator/public API tests, and the eight-row scenario
   in `MonsterSystemTest.php:800-801` encode or exercise the old boundary.
8. `docs/architecture/monster-system.md:45` and
   `docs/architecture/public-lobby-and-island-dashboard.md:59-61` state an eight-row public contract;
   ADR-0009 states max source-kind representation.
9. `product/docs/monster-audit-pr21.md` and source/reference-analysis documents correctly describe the
   historical eight source monsters and must remain historical, with an explicit v11 follow-up note
   rather than falsifying source facts.
10. `AssetManifestResolver.php:39-47` has the eight normal original assets plus the hardening asset;
    the intermediate manual lists only a subset and has no complete data-driven table.

C3 adds nullable `display_order`. Historical rows use `source_metadata.kind * 100` only as a runtime
fallback. Every v11 definition supplies one unique value and the validator rejects missing, duplicate,
negative, or non-integer values. Public detail removes the limit and orders by effective display order;
its total covers every positive stat. Ranking species use that order and the greatest killed display
order as representative. Raw source kind remains private compatibility metadata.

The authoritative v11 order is `0 mecha`, `50 zero`, `100 inora`, `200 sanjira`, `300 red`, `400 dark`,
`450 aoi`, `500 ghost`, `600 whale`, `700 king`. Original relative order and historical representative
behavior are unchanged. Custom GIF manifest entries are added by filename only; binaries remain external
and existing GIF validation/CSS fallback applies.

## K. Aoi Inora design and expected frequency

The audited formula is retained:

```text
L = active-Nation-owned land cells at the World-level disaster snapshot
p(trigger) = min(10000, L) / 10000
```

This is one World draw per target turn and uses half the current ordinary per-land numerator without
making probability depend on added empty sea. Raw Hakoniwa 2+ instead performs `disMonster` random
coordinate attempts, not probability/1000 (`docs/reference-analysis/hakoniwa-2plus-turn-processing.md:233-241`),
so the selected formula is an explicit 2S+ balance contract, not a claimed source copy.

World dimensions alone do not determine `L`; presenting one exact live frequency without production
data would be false. For any World with `L > 0`, the expected trigger interval is
`10000 / min(10000, L)` turns; it bottoms out at one turn when `L >= 10000`. At `L = 0` the trigger is
impossible and the interval is unbounded.
The geometry-only maxima below assume every cell were active-owned land; because that leaves no remote
sea candidate, they are arithmetic upper bounds, not realized successful-spawn rates.

| bounds | cell ceiling | maximum trigger probability | minimum expected trigger interval |
|---|---:|---:|---:|
| current 60x60 | 3,600 | 36.00% | 2.778 turns |
| 64x64 | 4,096 | 40.96% | 2.441 turns |
| 80x64 | 5,120 | 51.20% | 1.953 turns |
| 96x96 | 9,216 | 92.16% | 1.085 turns |

At the actual snapshot, successful-spawn probability is the trigger probability when at least one
candidate exists, otherwise zero. After existing World disasters and land subsidence, but before Nation
natural spawn (`DisasterTurnService.php:134-153`), count `L`, draw trigger, then scan candidates. Load
the surface rows/terrain/facility/occupancy in a bounded query set, mark the radius-3 dilation of every
land coordinate in memory, and stable-sort remaining neutral empty sea/shallow candidates. Complexity is
O(cells + land*37), at most 340,992 coordinate marks at 96x96, with no per-candidate query. Use one
trigger draw; only a successful trigger with candidates draws candidate and HP.

Dedicated labels are:

- `global_disasters:aoi_inora:trigger:v1`;
- `global_disasters:aoi_inora:candidate:v1`;
- `global_disasters:aoi_inora:hp:v1`.

No candidate produces an admin/phase metric and no player event or destruction. Shallow spawn first
normalizes to neutral sea. Record source `world_aoi_disaster`, whose spawn-turn action flag is false.
Water movement uses a narrow `water_neutralizing` contract: sea/shallow only, destination sea, owner
null, population zero, removable water facility cleared. Empty neutral movement uses the normal movement
event only; owner/facility destruction adds clear public and affected-owner metadata.

Initial-island displacement runs inside the already-held World lock/transaction. Reservation radius 5 is
only a sea/capacity guard; the current generator writes the smaller land/growth/placement set plus only
the shallows selected by the seed. After locking and validating reservation cells in ID order, generate
one immutable `InitialIslandPlan` from the seed and locked pre-state. The plan contains the exact final
cell writes and consumes the generator RNG once. Lock occupancies only for those actually changed cell
IDs, in ID order, and remove only Aoi through
`MonsterRemovalService::removeForWorldMutation(..., island_creation_displacement)` before applying that
same plan. An Aoi in a reserved but unchanged outer cell remains alive and occupied. Ordinary monsters
are never displaced by this exception and may still make the operation fail closed under existing
integrity rules. Expansion itself still follows the existing search, exactly one expansion, one retry,
fail-closed contract.

Aoi value remains 1,200 and base XP 18. Extend `MonsterDamageService` with a narrow definition-owned
hostless reward policy: an attributed Aoi kill on ownerless water gives 100% money to the killer, no host
meat, normal capacity/stat/cycle, and optional firing-base XP. Existing species retain their split.

## L. Mecha Inora Zero design

The dispatch resolver maps selector 2 to the v11 Zero definition and 9,999 cost before calling the spawn
service. The service receives only that trusted resolution, creates fixed HP4, and records the existing
dispatch source so Zero cannot act on its spawn turn.

At the normal-monster cell branch, after counting the action but before hardening, movement, or defense
contact, an alive Zero at HP1 is removed from occupancy and `MonsterTurnBatch` as
`nuclear_self_destruct`, without reward/stat/cycle/XP. Then call the public huge-meteor resolver once at
the fixed origin with Zero metadata. The resolver removes collateral monsters through the existing
rewardless path and refreshes the shared batch/cell index. Removing Zero first prevents self-recursion;
forgotten collateral cannot later act or trigger. Emit one public trigger event, then ordinary blast cell
events. Use current host name when present and `無所属海域(X,Y)` as the neutral fallback.

Normal attributed damage kills remain distinct: value 0, kill stat/cycle yes, base XP35 only with a
firing base, no base XP for Old Bow. Defense contact remains unchanged at HP2-4. The manual table must
distinguish normal kill from HP1 self-destruction and show fixed HP4, dispatch-only appearance, value 0,
XP35, and the pre-movement nuclear action.

## M. Random, turn-order, and retry boundaries

The v11 order is:

```text
prepare_turn: snapshot skills + equipped Item/effect identity
development commands: Ring applies to explicit/automatic finance; selected dispatch executes and is deferred
production and the existing pre-cell phases
ordinary shuffled surface cells and missile finalization
Old Bow trigger/target draws and damage
normal monster pass in the same cell order: Zero HP1 action before movement
global disasters and land subsidence
Aoi World trigger/candidate/HP
Nation natural monster spawn
the existing post-disaster/end-turn phases
```

Old Bow labels are
`process_cells:secretary_item:nation:{nation_id}:old_bow:trigger:v1` and
`...:target:v1`. Existing dispatch retains its queue-item candidate stream; the selected definition adds
no draw because both variants have fixed dispatch HP. Aoi uses the three labels in K. Existing stream
labels and draw populations never change.

All target/candidate collections are stable-sorted before a uniform draw. TurnRun ruleset, seed, and
snapshots are immutable for the attempt. Transaction rollback removes item damage, spawns, events and
batch mutations; same ruleset/seed/snapshot retry reproduces them. v10 retries never discover v11 Item
effects. Dispatch selection is persisted/fingerprinted before execution, and Zero blast requires no new
random draw.

## N. Query and performance risks

| Risk | Bound/design | Required measurement |
|---|---|---|
| equipment options | one Secretary/Item fetch, at most 50 warehouse rows; validate in memory | query-count tests for empty/full warehouse and every slot |
| equipment mutation | one User membership-set advisory lock, then stable locks over W active owned Worlds/memberships/Nations plus one Secretary and at most 50 Items; query/lock work grows linearly with W | registration race, zero/one/multiple Worlds, concurrent no-op/conflict/replacement, guard failure in any World |
| Item effect presentation | one explicitly authorized World/ruleset lookup plus the existing bounded Secretary/Item fetch; unscoped DTO remains neutral | same User with v10/v11 Worlds, omitted/unauthorized World, zero-Nation configured-current context, empty/full warehouse |
| turn Item snapshot | eager-load owners/Secretaries/skills/items in bounded batch queries, not per Nation | 1 and many active Nations |
| Old Bow target search | one joined occupied-monster query, grouped in memory; at most one trigger and one target draw per eligible Nation | query count, candidate count, 50+ monsters |
| public monster detail/ranking | remove limit without introducing per-definition lookups; retain one detail query and one World stat batch | 10+ species and many Nations |
| Aoi scan | bounded surface query set plus <=37 marks per land cell; 340,992 marks at 96x96 | 60x60, 64x64, 80x64, 96x96 wall time/query count |
| Aoi water movement | preserve existing batch indexes; no new cell lookup per candidate direction | mixed land/water actor batch |
| Zero blast | existing 19-cell radius shape and shared mutable index | query/event count and collateral density |

No caching may cross World/ruleset/TurnRun attempt boundaries. Query reductions must not weaken locks or
replace authoritative execution checks with preview state.

## O. C1-C5 branch plan

| Checkpoint | Owner | Scope | Entry condition | Exit evidence |
|---|---|---|---|---|
| C1 | equipment | equipment version migration, mutation service/API/modal/UI, concurrency audit | C0 PR merged into an unchanged `release/ver-2.3.0`; clean branch from verified release HEAD | focused schema/API/UI/concurrency tests and review P0-P2=0 |
| C2 | Item effects | v11-shaped test fixture, snapshot, Old Bow, Ring, effect DTO/manual | C1 integrated and its migration applied in test | ordering/RNG/retry/reward/finance tests and performance profile |
| C3 | monster foundation | nullable display order, publisher/validator/projections, fixed-eight removal, assets/reward policy/manual table | C2 integrated; no final v11 file | 10+ definition, immutability, fallback, public API and reward tests |
| C4 | new monsters | one-command dispatch selector/dynamic cost, Aoi/Zero spawn/movement/action/collision | C3 contracts integrated | full selector, compatibility fixture, Aoi/Zero/batch/performance tests |
| C5 | v11 release | final immutable payload, all-or-nothing v10->v11 migration, release validation/docs | C1-C4 integrated and no unresolved review | v1-v10 diff/checksum zero, v11 checksum, migration/idempotency/rollback/full suites |

After C5 delivery, schedule the frozen fresh-install baseline described in H as a separate high-priority
post-2.3.0 cleanup. It is not folded into gameplay checkpoints and does not rewrite existing migration
files.

Each branch targets `release/ver-2.3.0`. A checkpoint does not merge itself and does not authorize
production access. If a compatibility change exceeds its narrow checkpoint boundary, stop and split it.

## P. Required tests

In addition to the task's existing C1-C5 lists, the audit requires:

- selector values are authored 1/2 and independent of DB ID, display order, option order and disabled
  options;
- missing/invalid selector, arbitrary key/cost, same-key different-selector fingerprint conflict, queue
  label/cost, registration preview and execution revalidation;
- v10 rows grouped by every status; quantity/parameter/fingerprint/provenance anomalies fail before
  publication; accepted queued row maps to ordinary 3,000; terminal definition/payload/fingerprint stays
  unchanged while proved non-null fingerprints receive v10 provenance;
- a v10 dispatch is registered at one requested position, repositioned, then migrates without hash
  recomputation; the stored hash is byte-identical, an exact retry with the original request position
  converges, and substituting the current position conflicts;
- the original v10 selector-less request payload retries as `duplicate = true` for queued, completed,
  failed, and cancelled rows; explicit selector 2 conflicts, and a missing selector without a proved v10
  request key still fails before mutation;
- `equipment_version` backfill/future creation, no-op, stale 409, atomic replacement, user-lock ordering,
  concurrent registration into a previously unaffected World in both acquisition orders, zero/one/multiple
  active-World paths, partial-lock release, and each unresolved TurnRun status in any owned World;
- User-global Secretary/name/mutation DTOs contain no implicit ruleset effect; explicit owned v10/v11
  World contexts for the same User return distinct effect projections, omitted/unauthorized context is
  safe, and the zero-active-Nation configured-current projection is labelled;
- v10 effect-free retry, once-only Item snapshot, Bow 10% edges/stream isolation/hardening/Zero safety,
  damage and reward single-application;
- one to five Rings, sixth rejection, Lv10 bound, explicit/automatic finance equality and capacity
  overflow metadata;
- 10+ monster definitions, complete public total/order/representative, duplicate/missing display order,
  historical fallback, publisher snapshot and v1-v10 row immutability;
- Aoi probability formula/table, four map sizes, radius-3 edge/out-of-bounds geometry, no candidate,
  shallow normalization, water facility destruction, source defer, hostless payout, exact island-plan
  displacement, reserved-but-unchanged survival, ordinary-monster fail-closed behavior; probability and
  interval boundaries at `L = 0`, `9,999`, `10,000`, and greater than `10,000`;
- Zero fixed HP4, both dispatch costs, spawn defer, HP4/3/2 movement, HP1 pre-movement explosion,
  neutral fallback, exact-once event, no chain, collateral rewardlessness, normal-kill XP/stat boundary;
- forced exception at each migration phase, trigger/constraint restoration, second-run idempotency,
  same-seed rollback/retry and serial/16-shard identifier equivalence;
- at every checkpoint, isolated `migrate:fresh` plus the historical Secretary Item migration rerun proves
  current GrantService/Catalog/Publisher/Validator changes preserve the v2.2.0 starter row and old
  ruleset publication semantics; add a red regression before any change that breaks this chain;
- backend query-count/performance profiles, frontend selector/modal accessibility, GIF extension/missing
  fallback, manual rendering, full static/backend/frontend/release suites in C5.

## Q. Unresolved Owner decisions

None. The latest dispatch amendment resolves the only contract conflict. Owner explicitly delegated Aoi
balance details; K fixes them. `無所属海域` fixes the delegated neutral Zero fallback. The external custom
GIFs are an operator-supplied release prerequisite, not an Owner gameplay decision and not C0 work.

The mutable historical-migration dependency is a confirmed engineering risk, not a missing gameplay
decision. Compatibility gates make C1-C5 safe to proceed; the frozen baseline is recorded as a
high-priority post-2.3.0 cleanup and does not authorize editing applied migrations in this release.

Open questions B-03, B-05, B-12, B-13, and T-02 do not reach a C0/C1 gate. E-04 remains Deferred, so
this release must keep two explicit Item effect types and must not create a generic modifier engine.

## R. STOP status

No STOP condition is present for C0. C0 can complete as documentation-only work. C1 may start only after
this C0 PR has final-head review with zero unresolved P0-P2, is explicitly integrated into
`release/ver-2.3.0`, the new release HEAD/base is re-verified, and a clean C1 branch is created. This
document does not authorize that merge.
