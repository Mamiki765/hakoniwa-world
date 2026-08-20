# TEMPORARY IMPLEMENTATION CONTRACT — ver 2.3.0

> **Status: WORKING FILE — DO NOT MERGE INTO `main`.**
>
> This file records Owner-approved intent and checkpoint protocol. It is not proof that every implementation hypothesis below matches the repository. Every checkpoint must audit the current code, tests, published rulesets, migrations, and read-only references before implementation.
>
> Delete this file before the final `release/ver-2.3.0 -> main` pull request is declared merge-ready. Final validation must fail if `.codex/tasks/ver-2.3.0.md` still exists.

## Baseline and branch topology

- Repository: `Mamiki765/hakoniwa-world`
- Baseline `main`: `8dcf2c316c4f7e3646632e7d4e79071e7537d952`
- Baseline application: ver 2.2.1
- Baseline current gameplay ruleset: `hakoniwa-2s-plus-v10`
- Release integration branch: `release/ver-2.3.0`
- Production / OCI / production DB access is forbidden during implementation checkpoints.
- `_references` is read-only.

Expected integration shape:

```text
main
  └─ release/ver-2.3.0
       ├─ C0 audit/spec branch
       ├─ C1 equipment branch
       ├─ C2 item-effects branch
       ├─ C3 monster-foundation branch
       ├─ C4 monster-implementation branch
       └─ C5 v11-release branch
```

Each checkpoint branch targets `release/ver-2.3.0`, not `main`. After focused validation and review, merge the checkpoint branch into the release branch. Only C5 opens the final release PR toward `main`.

## Execution protocol

### General rule

- Owner decisions in this file are authoritative intent.
- Existing implementation details and proposed class/API names are hypotheses until audited.
- Do not treat committed scaffold or this task file as automatically correct.
- Before modifying an area, inspect the current implementation and relevant tests.
- Fix confirmed implementation defects without waiting for Owner input.
- STOP only when a true gameplay/product decision is missing or two Owner-approved requirements conflict.
- Do not stop merely because an implementation is large or because a historical path is complex.
- Do not add unrelated cleanup to this release.

### Checkpoint 0 — audit and contract completion

C0 is read-only for production code. It may update only this temporary contract and audit documentation created specifically for the checkpoint.

C0 must:

1. verify the baseline SHA and current v10 state;
2. inspect Secretary item schema, catalog, presenter, API, UI, grant path, turn snapshot, finance path, and World/TurnRun mutation guards;
3. inspect monster definition schema, spawn, movement, damage, reward, base experience, dispatch, removal, kill statistics, public projection, assets, manuals, and island creation/expansion collision handling;
4. enumerate every fixed-eight / `kind 0..7` / maximum-eight assumption;
5. inspect huge-meteor blast reuse boundaries and MonsterTurnBatch synchronization;
6. inspect Hakoniwa 2+ source-analysis documents for ordinary monster probability and movement semantics;
7. verify the proposed v11 schema and migration requirements;
8. append a `C0 audit results` section to this file without rewriting Owner decisions;
9. produce a checkpoint table: verified fact, required change, proposed implementation, tests, owner decision needed, checkpoint owner;
10. stop only for missing Owner decisions. Aoi Inora balance/probability details are delegated to the implementation team within the boundaries below and are not a STOP reason.

### Checkpoints 1–4

At each implementation checkpoint:

1. audit the release-branch diff inherited from earlier checkpoints;
2. add a red regression before fixing an observed defect when practical;
3. implement only the checkpoint scope;
4. run focused backend/frontend/static validation;
5. measure query/random impact where relevant;
6. request Codex review for that checkpoint branch;
7. resolve P0–P2 findings before merging into the release branch;
8. report any deferred finding in a next-goal table instead of expanding scope.

### Checkpoint 5

C5 publishes v11, completes integration, runs all validation, removes this temporary file, and prepares the final release PR. No merge or deploy without explicit Owner approval.

---

# Owner-approved product contract

## Release purpose

ver 2.3.0 is the first release in which Secretary equipment can be changed and equipped items can affect gameplay. It also removes the fixed-eight monster presentation boundary and adds two new monsters:

- あおいのら
- メカいのら零式

The whole release publishes one final immutable gameplay ruleset, `hakoniwa-2s-plus-v11`. Do not publish intermediate v11 variants per checkpoint.

## Explicit non-goals

Do not implement in ver 2.3.0:

- gifts;
- permanent item shop;
- NPC auction;
- player auction/trading;
- item/resource exchange;
- meteor item drops;
- item enhancement;
- item specialization/custom name/custom flavor purchase;
- power shot;
- equipment durability, affixes, sockets, rarity, ownership ledger;
- Bubble Inora;
- Metal Ghost;
- early-monster stage;
- ships, pirates, slave ships, naval combat;
- electricity;
- automatic Nation dormancy;
- generic modifier/effect engine beyond the two required effect types;
- broad `App.vue` or `CompleteTurnEngine` redesign;
- historical ruleset or migration rewriting;
- production/OCI deployment changes.

The future specialization concept remains deferred: spending Nation money to replace an item’s display name/flavor while preserving performance. It crosses User-owned Item and Nation-owned money and requires separate moderation/audit decisions.

---

# Verified baseline observations — must be rechecked by C0

These observations were read from baseline `8dcf2c3...` and are evidence, not permission to skip audit.

1. `SecretaryItemCatalog` currently knows only `old_bow`. It stores category, max level, name, flavor, and uniqueness but no gameplay effect.
2. `SecretaryItemPresenter` does not return an effect line.
3. Secretary equipment UI renders five read-only slots and states that equipment has no turn effect.
4. `SecretaryController` has show/name/rename only; no equip API exists.
5. `secretary_item_instances.equipped_slot` and a partial unique slot index already exist. Empty slots are not DB rows.
6. `SecretaryItemGrantService` already locks the Secretary row and validates inventory capacity, slot range, and category maximum using the application catalog.
7. `SecretaryTurnService` snapshots four skills only; equipment is absent from retry state.
8. Current natural monster spawn requires exactly eight definitions in `MonsterSpawnService`.
9. Current public ranking projection requires `source_metadata.kind` in `0..7`; public Nation detail and legacy docs also retain maximum-eight assumptions.
10. Current natural monster trigger arithmetic is `min(10000, owned_land_cells * 2) / 10000`, one independent draw per eligible active Nation.
11. Current `monster_dispatch` costs 3,000億, trusts a fixed command definition, and `MonsterSpawnService::dispatch()` hard-codes `mecha_inora`.
12. Current dispatched monsters do not act on their spawn turn.
13. Current kill reward splits wreckage value in half: killer money gets `floor(value/2)` and current host gets the remainder as monster meat. If host is neutral, the host half is unclaimed rather than transferred to the killer.
14. Current `MonsterInstance` does not persist a spawn/host Nation; host is derived from the current cell.
15. Current `AssetManifestResolver` contains only the original eight monster assets plus hardened state.
16. Current manuals are Markdown sections `index`, `beginner`, `intermediate`, and `advanced`; no Secretary section exists.
17. Current v10 differs from v9 only in food production-overflow timing.

C0 must confirm all observations and identify any newer branch changes before implementation.

---

# C1 — equipment mutation and UI

C1 implements safe equip/unequip state changes but does not activate gameplay effects.

## Data and concurrency

Add an optimistic equipment version, preferably `secretaries.equipment_version`, initialized consistently for existing and future Secretaries.

Equipment mutation must:

1. resolve the authenticated User’s Secretary;
2. acquire a PostgreSQL advisory `UserMembershipMutationLock` keyed by User ID before enumerating Worlds;
3. require both current membership-set writers, `NationCreationService` and `NationAbandonmentService`, to acquire the same user lock before the target `WorldMutationLock`, reject a next non-dry pending/running/failed/blocked TurnRun for that World before any state mutation, and hold both locks through commit; future membership add/reactivate/remove/deactivate paths must join the same lock and guard;
4. enumerate every owner membership whose Nation is active; a User may own one active Nation in each of multiple Worlds while equipment remains User-global;
5. sort all affected Worlds by ID and acquire every shared `WorldMutationLock` before the DB transaction;
6. if any World lock fails, release already-held World locks and then the user lock in reverse order and return the stable conflict without mutation;
7. inside the transaction, lock affected World rows and owner membership/Nation rows in the same stable order and verify the frozen set;
8. for every affected World reject mutation while the next non-dry TurnRun is pending/running/failed/blocked, so any failed same-TurnRun retry observes unchanged equipment;
9. lock the Secretary row;
10. lock relevant Item rows in stable ID order;
11. verify `expected_version`;
12. build the complete proposed equipment state in memory;
13. validate slot range, Item ownership, category limits, same-item limits, and all final-state invariants before writes;
14. atomically clear/replace the target slot without exposing an intermediate invalid final state;
15. increment the equipment version exactly once for a meaningful mutation;
16. return a no-op result without version increment when the chosen state already matches;
17. record a private/admin audit event without leaking hidden Item state publicly;
18. release every acquired World lock and then the user lock in reverse order in `finally`.

If no active owned Nation exists, equipment may still be changed because Items belong to the User/Secretary, but retain the user lock across the Secretary/Item transaction so registration cannot add a newly affected World concurrently.

Do not hold a DB or World lock while the player is looking at the modal. Lock only when the final mutation request is submitted.

## API

Exact route names may follow repository conventions. The API must support:

- options for a specific slot;
- equip an Item into a slot;
- unequip a slot;
- optimistic version conflict with stable 409 code;
- server-side final validation even when options were previously filtered.

Candidate options must be produced by the server. Do not duplicate category/equip policy in Vue as the authoritative source.

For a target slot:

- first UI option: `外す`;
- second: currently equipped Item, selected by default;
- then: other legal unequipped Items;
- Items already equipped in another slot are not silently moved;
- illegal candidates are omitted from the selectable list;
- mutation still revalidates legality.

Example: if slot 1 contains a bow and slot 2 is opened, other bows are omitted because bow maximum is one. If slot 1 itself is opened, the current bow and legal replacement bows may appear.

## UI

Clicking/tapping an equipment slot opens a modal.

The candidate list is deliberately narrow and compact because the warehouse can hold 50 Items. Each row shows only:

- Item name;
- Lv;
- effect text.

Flavor text remains in the warehouse page, not the selection list.

Item effect text is ruleset-scoped even though Item identity, inventory, and equipment are User-global.
`GET /api/v1/me/secretary` without a World context remains ruleset-neutral and must not choose an
arbitrary owned World. When the UI is opened for an active Nation it sends that Nation's explicit
`world_id`; the server verifies an active owner membership, resolves effects from that World's exact
ruleset, and returns an effect presentation context containing `world_id`, ruleset version ID/version,
and each Item's derived `effect_text`. Omission is always ruleset-neutral, including when the User has
no active Nation; there is no configured-current fallback. Name/rename and
equipment mutation responses remain ruleset-neutral; the UI reloads the World-scoped projection after
mutation. An omitted World while one or more active memberships exist never implies first/latest/current.

Modal requirements:

- native scrollable inner list (`overflow-y: auto`, touch/wheel/keyboard compatible);
- do not reuse map-drag behavior unless C0 proves a genuine shared primitive exists;
- selected radio/listbox state is keyboard accessible;
- modal action area remains outside the scrolling inner list;
- right-bottom `変更する` action;
- no tap-to-instant-equip, to avoid mobile mis-taps;
- close/cancel without mutation;
- stale 409 reloads authoritative state and asks the user to choose again;
- loading state prevents duplicate submission;
- mobile and desktop layout regressions.

Use compact category-limit badges where helpful, for example `弓・1個まで` and `指輪・5個まで`, without making each row wide.

## C1 tests

Minimum:

- five slots remain stable;
- equip and unequip;
- replace current slot atomically;
- wrong Secretary Item rejected;
- invalid slot rejected;
- category maximum enforced;
- same-item maximum enforced;
- item already in another slot not auto-moved;
- optimistic conflict;
- no-op does not increment version;
- concurrent equipment requests serialize;
- TurnRunner/equipment mutation serialization;
- zero, one, and multiple active owned Worlds use the intended lock paths;
- a pending/running/failed/blocked next TurnRun in any affected World blocks equipment mutation;
- partial multi-World lock acquisition releases earlier locks and writes nothing;
- Nation registration in a previously unaffected World and equipment mutation serialize in both
  acquisition orders under user-lock -> World-lock ordering, without deadlock or an unguarded snapshot;
- Nation registration under the target World lock rejects each pending/running/failed/blocked next
  non-dry TurnRun before membership, Secretary/starter Item, or island writes and leaves no partial state;
- Nation abandonment and equipment mutation serialize in both acquisition orders; abandonment rejects
  each unresolved next-run status before membership/queue/monster/cell/Nation/event mutation and leaves
  the active Nation and all related state unchanged;
- no effect reaches turn processing in C1;
- options filtering and API mutation policy agree;
- keyboard/modal/mobile behavior.

---

# C2 — item gameplay definitions and effects

## Owner clarification for C2

- Secretary Items and equipment are User-global and shared across every MapSpace and active owned Nation; there is no separate equipment set per screen, target cell, World, Nation, or MapSpace.
- Old Bow is the only C2 effect scoped to a MapSpace and targets only the `surface` MapSpace. Ring is a Nation-wide economy effect and has no MapSpace target.
- C2 implements only these two closed effects. It does not introduce a generic layer/effect framework, per-space equipment state, or a second equipment version.
- Omitting `world_id` from Secretary presentation is always neutral, even when the User has no active Nation. Only an explicit authorized World may supply ruleset-derived effect text.
- The C1 equipment mutation API, lock/version contract, neutral mutation/name responses, and stale-409 modal refresh-and-reselect behavior remain unchanged.

## Definition boundary

Presentation and gameplay must be separated.

### Application presentation catalog

May contain:

- stable item key;
- Japanese name;
- category label;
- flavor text;
- optional presentation asset key.

Changing name/flavor alone must not require a gameplay ruleset bump.

### v11 gameplay contract

Must contain authoritative values for:

- item key;
- category key;
- maximum level;
- category maximum equipped count;
- same-item maximum equipped count;
- effect type;
- effect timing;
- effect parameters;
- stacking policy.

Do not copy effect probability, damage, or finance bonus into Item instance rows. Instances remain identity/state: `item_key`, `level`, `equipped_slot`, grant/obtained metadata.

Because v1–v10 do not contain Item effect definitions, C0/C2 must design an explicit compatibility boundary for reading existing `old_bow` state before v11 publication. Do not silently reinterpret v10 TurnRuns with v11 effects.

Presentation follows the same version boundary without becoming turn state: an explicit owned v10
World returns no gameplay effect for the existing Item, while an explicit owned v11 World derives the
v11 sentence and parameters. The User-global catalog continues to own only stable presentation such as
name and flavor; it does not supply a versionless gameplay sentence.

Avoid a generic expression language or arbitrary effect registry. Implement the two required explicit effect types cleanly:

- pre-normal-monster Secretary attack;
- finance income bonus.

## Item: 古びた弓

Stable key: `old_bow`

- category: `bow`
- maximum level: 1
- category maximum equipped: 1
- same-item maximum equipped: 1
- existing starter grant remains exactly one per Secretary
- existing starter Item remains equipped in slot 1 unless the player changes it

Displayed effect:

> 10%の確率で、自領の地上にいる怪獣に1ダメージを与える。

Internal v11 parameters may preserve the earlier scalable formula (`9% + Lv × 1%`) as long as Lv1 resolves exactly to 10%, but do not show unnecessary formula detail to players.

### Old Bow stage

Turn order for v11:

```text
ordinary shuffled surface-cell events, including missiles
  ↓
Secretary equipment effects (Old Bow)
  ↓
normal monsters
```

Do not implement early monsters in this release.

At `prepare_turn`, snapshot equipped Item identity/key/level/slot and resolved v11 effect identity into TurnState together with Secretary skills. A started TurnRun must never observe later equipment changes.

Old Bow behavior:

- active Nation only;
- equipped bow only;
- at most one attempt per Nation per turn;
- candidate must currently occupy the Nation’s own territory;
- no candidate means no attack and no unnecessary random draw;
- 10% trigger on a dedicated Secretary Item random stream;
- on success choose uniformly from safe candidates using a separate dedicated stream;
- apply 1 damage through the authoritative monster damage path;
- firing base is null, therefore no missile-base experience;
- attributed kill increments the Nation’s normal kill statistics and reward path;
- do not grant an existing Secretary skill XP merely because an Item attacked;
- miss should not create repetitive player-facing log spam;
- hit, blocked state that should have been filtered, and kill must remain auditable;
- no existing monster/missile random stream labels or draw populations may change.

### Secretary target safety

Target filtering is an explicit policy, not ad-hoc monster-key checks.

Exclude a candidate when:

1. the current attack cannot deal damage, such as an ordinary 1-damage attack against a currently hardened monster; or
2. after applying the attack, the monster remains alive and the resulting state is guaranteed to trigger an immediate harmful self-effect before normal player benefit.

Examples for Mecha Inora Zero:

- HP2 + Old Bow damage1 -> HP1 alive -> guaranteed nuclear self-destruct in normal monster phase -> avoid;
- HP1 + damage1 -> killed -> legal candidate;
- HP3 + damage1 -> HP2 -> legal candidate;
- future damage2 bow against HP2 -> killed -> legal candidate.

The UI effect sentence stays simple. Explain Secretary target judgment in the Secretary manual rather than bloating every Item effect line.

## Item: 指輪

Stable key: `ring`

Presentation:

- name: `指輪`
- flavor: `貴金属が使われた豪華な指輪。魔法の道具ではないが、贈り物にはぴったりだ。`
- emphasize that it is primarily a flavor/gift-like Item with a small practical benefit, not a powerful magical artifact.

Gameplay:

- category: `ring`
- maximum level: 10
- category maximum equipped: 5
- same-item maximum equipped: 5
- multiple equipped copies stack additively
- no production acquisition path in ver 2.3.0
- no starter grant, shop, auction, gift, or drop
- tests may grant multiple instances with distinct grant keys

Displayed effect:

> 資金繰りの際、追加で{Lv}億円を得る。

Apply the sum of equipped Ring levels to both:

- explicit `finance` command;
- automatic finance when no normal command consumes the turn.

Example: equipped Lv1 + Lv3 + Lv5 => additional 9億円.

The bonus is capacity-bounded and must not exceed resolved money capacity. Record base finance, equipment bonus requested/applied/overflow, and final result without duplicating player logs.

## Warehouse and equipment presentation

Add an `effect` line to every Item DTO and warehouse card. Effect text must be derived from authoritative current ruleset parameters plus Item level; do not hard-code a separate numeric sentence that can drift.

Warehouse card order:

1. name and Lv;
2. effect;
3. category / equipped slot state;
4. subdued gray italic flavor text.

## Secretary manual

Add a Secretary manual section/page through the existing Markdown manual system. Explain:

- skills;
- warehouse capacity;
- five equipment slots;
- equip/unequip procedure;
- category limits;
- effects only apply while equipped;
- equipment is snapshotted at turn start;
- Old Bow’s simple effect;
- Secretary automatically avoids attacks that are certainly ineffective or would leave a target alive in an immediately dangerous state;
- Ring stacking and finance behavior;
- Items belong to the Secretary/User and survive Nation abandonment.

Do not expose raw RNG seeds or internal implementation terminology.

## C2 tests

Minimum:

- v10 historical TurnRun remains effect-free;
- v11 fixture snapshots equipment exactly once;
- equipment change after snapshot does not change that attempt;
- unequipped Old Bow makes no draw and no attack;
- equipped Old Bow exact 10% boundary;
- dedicated random stream isolation;
- safe-target filtering: hardening, Zero HP2 avoid, Zero HP1 legal, Zero HP3 legal;
- damage and kill path reuse, no duplicate reward/stat;
- Ring max Lv10 validation;
- five Rings may equip and stack;
- sixth Ring category/same-item equip rejected;
- explicit and automatic finance receive the same Ring bonus;
- money capacity and overflow;
- rollback/retry determinism;
- explicit v11-World DTO and warehouse/modal effect text matches v11 parameters;
- the same User owning active Nations in v10 and v11 Worlds receives distinct World-scoped projections,
  an omitted World never selects either implicitly, unauthorized World context is rejected, and the
  omission remains neutral even when the User has zero active Nations.

## C2 progress/results

- Implemented on `codex/ver-2.3.0-c2-item-effects` from verified release/C1 baseline
  `41ed06294939b63b6796069d589abb262c12baeb`. The global catalog now contains exact Ring presentation
  and five-copy legality without adding any grant/acquisition path or changing the historical Old Bow
  grant. No production schema migration, formal v11 file, publication migration, production registry
  entry, World rebind, or published v1-v10 payload change was added.
- The test-only inactive `test-hakoniwa-2s-plus-v11-secretary-items` fixture defines the two closed,
  integer-only effects. Authoring validation rejects missing/unknown/open-ended/catalog-divergent
  fields. `prepare_turn` snapshots Secretary/equipment identity, equipped Item identity/slot/level, and
  resolved effect identity once per active Nation; v1-v10 retain zero Item query, snapshot, draw, and
  effect, including same-seed historical retry.
- Old Bow runs after missile finalization and before the existing normal-monster pass, uses isolated
  Nation trigger/target streams, filters current surface/ownership/alive/hardening/ruleset-owned hazard
  state, and delegates damage, reward, occupancy, batch synchronization, and kill statistics to the
  existing authoritative services. Ring level sums run through the centralized explicit/automatic
  finance path after base finance against the same money capacity; logging and other income remain
  unchanged, and no-Ring finance preserves the legacy metadata shape.
- Secretary reads/options derive effect text only from an explicit authorized active-owner World.
  Omission is always neutral and does no ruleset query. Mutation/name responses remain neutral, while
  the active-Nation UI reloads scoped presentation and preserves the C1 stale-409 reselect contract.
  Warehouse and modal show ruleset-derived effects; the five slot cards remain compact. The Secretary
  manual is the dedicated player-facing `秘書について` page and records all four passive skills and
  their experience sources, equipment limits and changes, surface-only Old Bow, Nation-wide Ring,
  snapshots, safety, stacking, capacity priority, and abandonment persistence. The ordinary beginner,
  intermediate, and advanced manuals do not duplicate those details.
- Query results: v10 prepare Item increase 0; v11-shaped equipped Item load exactly 1 for one/many
  Nations, 50 inventory Items, five equipped Items, and multiple MapSpaces; Old Bow candidate loading
  has a stable upper bound of 5 and is exactly 5 in the many-monster/two-Nation fixture; neutral/explicit
  presentation remains exactly 2/3 queries with no per-Item SQL.
- Local validation passes: 71 focused backend tests / 481 assertions; full frontend 128 tests plus
  ESLint, `vue-tsc --noEmit`, and Vite production build; full-app PHPStan; Pint over 245 files;
  open-question validator; isolated `migrate:fresh`; v10 ruleset validation; and all 106 PHPUnit files
  across the complete 16-shard plan. Ruleset-source/migration and `_references` diffs from the release
  baseline are zero.
- The first exact-head Codex review found two P2 UI transaction-boundary cases: a successful equipment
  mutation and a successful name/name-change mutation could be reported as failed when only the following
  scoped projection refresh failed. Red regressions now require the committed neutral mutation result and
  new equipment version/name to remain authoritative locally, close the completed equipment modal, and
  report only the effect-projection refresh failure. The C1 stale-409 retry/reselect branch is unchanged.
- The following exact-head review found one P2 documentation gap: the Secretary navigation advertised
  skill guidance without documenting the four skill effects and experience sources. The dedicated page
  now carries that guidance, uses the same navigation immediately after `上級編`, and states abandonment
  behavior without implying that equipment can be changed through the normal UI while no island is active.
- The next exact-head review found one P2 authoring gap: Item definitions could validate without the
  separated normal-monster stage that Old Bow requires at runtime. The Item gameplay contract now rejects
  a missing or incompatible stage before publication, and authoring/runtime share the same stage constant.
- Verified C0 divergence: post-missile eligibility is safest as one bounded current-state occupancy
  load followed by in-memory Nation grouping, while the authoritative damage/removal services keep the
  already-loaded monster batch synchronized. This is the audited combination boundary rather than
  treating the pre-missile batch alone as candidate truth. The C1 2/3-query presentation bound was
  preserved despite v11-derived effect text, and no production schema change was required.

C3 may start only after this C2 PR is green in repository-required Quality, reviewed at its exact final
HEAD with unresolved P0/P1/P2 all zero, explicitly integrated into `release/ver-2.3.0`, and a clean C3
branch is created from the reverified release HEAD. C3 owns the monster-extension foundation and must
preserve the C2 Item contract; final v11 authoring/publication/migration remains C5 only.

---

# C3 — monster extension foundation

## `display_order`

Add a durable monster display order independent from legacy `source_metadata.kind`.

Current intended order:

| display_order | monster |
|---:|---|
| 0 | メカいのら |
| 50 | メカいのら零式 |
| 100 | いのら |
| 200 | サンジラ |
| 300 | レッドいのら |
| 400 | ダークいのら |
| 450 | あおいのら |
| 500 | いのらゴースト |
| 600 | クジラ |
| 700 | キングいのら |

For existing source kinds, `kind × 100` is the compatibility order. New monsters use insertion values.

Requirements:

- add nullable schema support without rewriting published v1–v10 definition history;
- historical definition rows may fall back to legacy kind × 100;
- every v11 monster definition has explicit non-null unique display order;
- v11 validation rejects duplicates and invalid order values;
- public kill species lists sort by display order;
- representative ranking monster is the killed species with greatest display order;
- existing eight-monster order and representative behavior remain unchanged;
- remove runtime assumptions requiring exactly eight definitions or `kind 0..7` for current v11;
- do not erase original source kind metadata.

Audit and remove/replace all fixed-eight assumptions, including:

- spawn catalog exact count;
- public Nation detail limit/total;
- ranking projection kind range;
- ruleset validator/contract tests;
- migration stable-key assumptions;
- docs/manual statements;
- test fixtures.

## Monster manual table

Update the manual with a display-order-sorted table including at least:

- name;
- HP range;
- natural/world/dispatch appearance;
- wreckage value;
- missile-base experience;
- special ability.

Use v11 values as the source. Avoid unvalidated drift between manual text and contract tests.

## Assets

New asset identities are Owner-approved:

- `hakoniwa_custom.monster.aoi_inora` -> `monster-aoi-inora.gif`
- `hakoniwa_custom.monster.mecha_inora_zero` -> `monster-mecha-inora-zero.gif`

Only the filenames/contracts are added to code. Do not create, generate, transform, commit, or distribute GIF binaries. The operator will later place matching GIF files in the existing external asset directory.

Missing files must use existing safe fallback rendering, not broken images.

## Reward contract extension

Current hostless kill handling loses the host half. Add an explicit, narrow reward contract that supports Aoi Inora’s full hostless payout without changing existing monster rewards.

- existing monsters retain current half killer / half host behavior;
- Aoi Inora killed on neutral sea gives 100% of wreckage money to the attributed killer Nation;
- no host meat for ownerless sea;
- capacity applies;
- normal kill statistic and base experience remain available;
- self-destruction/removal paths remain rewardless.

Do not infer reward behavior from `hostNation === null` alone for every monster; make it a v11 definition/system contract.

## C3 tests

Minimum:

- existing eight definitions preserve order and representative asset;
- v11 supports more than eight monsters without API 500;
- all species counts contribute to total;
- duplicate display order rejected;
- historical fallback works;
- v1–v10 payloads/rows unchanged;
- asset filenames and GIF extension contract;
- missing custom GIF uses fallback;
- existing rewards unchanged;
- Aoi hostless full payout contract.

---

# C4 — new monsters

## Aoi Inora / あおいのら

Stable contract:

- key: `aoi_inora`
- name: `あおいのら`
- asset key: `hakoniwa_custom.monster.aoi_inora`
- filename: `monster-aoi-inora.gif`
- display order: 450
- HP: base 2 + uniform variation 0..1 => 2–3
- movement limit: 1
- Nation natural-spawn pool: excluded
- World sea-monster disaster: enabled
- wreckage value: 1,200億円
- missile-base experience: 18
- ordinary attributed kill statistic: yes
- hostless killer payout: 100%

### Spawn probability

Owner delegated the exact formula. The selected default contract is:

```text
active_owned_land_cells = sum of owned land cells for active Nations
numerator = min(10000, active_owned_land_cells)
probability = numerator / 10000
one World-level trigger draw per target turn
```

This uses half of the current normal monster per-land numerator (`1` instead of `2`) while making Aoi Inora a World event rather than one independent draw per Nation. C0 must compare this against source-analysis documents and measured world size. It may adjust the exact formula without Owner STOP if it preserves the stated intent, is explicitly documented, and does not make probability depend on arbitrary sea expansion size.

Use dedicated labelled streams for trigger, candidate, and HP. Do not perturb existing disaster or monster streams.

### Spawn candidate

Candidate cell:

- surface map;
- terrain `sea` or `shallow`;
- owner null;
- facility null;
- monster occupancy null;
- no land terrain within hex radius 3, meaning nearest actual land is at least 4 hexes away.

For this rule, land means terrain other than `sea` and `shallow`. World out-of-bounds is not land.

If selected candidate is shallow, convert it to neutral sea before placing the monster.

If trigger succeeds but no candidate exists, no destructive fallback is used in v2.3.0. Record an admin/phase metric and no player damage. Do not destroy seabed bases/oil fields merely to create a spawn candidate in the initial version.

The World-disaster substage must be documented relative to existing global disasters and Nation monster spawn. New Aoi Inora does not move on its spawn turn.

### Water movement and trample

Aoi Inora moves one cell through `sea`/`shallow` only.

A valid destination is normalized to:

- terrain `sea`;
- owner null;
- population 0;
- removable water facility cleared.

It may destroy seabed bases, seabed oil fields, and future removable water actors/facilities when moving. It must not enter ordinary land, mountains, Capital, monuments, or any cell prohibited by the audited monster movement contract.

Generalize movement/trample contract only as far as required. Existing land monster behavior must remain byte-for-behavior compatible.

Events:

- normal movement remains publicly observable under existing monster visibility rules;
- if a Nation-owned or facility-bearing cell is destroyed, record a clear public event and affected-Nation owner event with pre-impact facility/owner/terrain and coordinates;
- empty neutral sea movement must not flood logs with redundant destruction text.

### Island creation collision

Aoi Inora must never block initial Nation placement or expansion-driven island creation.

Inside the same reviewed World mutation transaction, any Aoi Inora occupying cells that the initial island operation will rewrite is removed with:

- reason `island_creation_displacement` or equivalent stable key;
- no reward;
- no base experience;
- no kill statistic;
- occupancy/index cleanup before terrain generation.

Before building that plan or creating any Nation/Secretary/Item state, registration must also apply the
shared next non-dry pending/running/failed/blocked TurnRun guard while holding the target World lock.
This preserves every same-ruleset/same-seed retry input; C4 must not weaken the C1 guard when adding Aoi
displacement.

Reservation radius is not the removal set. Lock and validate reservation cells in stable ID order, then
derive one immutable island plan from the seed and locked pre-state. That plan must contain the exact
dirty cell writes for land/growth/facility/capital and selected shallow conversion and must be the same
plan later applied, so RNG is consumed once. Lock occupancies only for those planned changed cell IDs in
ID order, remove Aoi there, and leave Aoi in reserved-but-unchanged cells untouched. Do not remove
ordinary monsters; an ordinary occupancy may continue to make registration fail closed under existing
integrity rules.

## Mecha Inora Zero / メカいのら零式

Stable contract:

- key: `mecha_inora_zero`
- name: `メカいのら零式`
- asset key: `hakoniwa_custom.monster.mecha_inora_zero`
- filename: `monster-mecha-inora-zero.gif`
- display order: 50
- HP: fixed 4
- movement limit: 1 while HP > 1
- natural spawn: disabled
- dispatch: enabled
- dispatch cost: 9,999億円
- wreckage value: 0
- missile-base experience on normal attributed kill: 35
- normal attributed kill statistic: yes
- spawn-turn action after dispatch: disabled, matching existing dispatch balance boundary

### Dispatch command

Keep exactly one stable player-facing command, `monster_dispatch` (`怪獣派遣`). Do not add `monster_dispatch_zero`.

The command uses the existing selector presentation semantics, not an ordinary quantity input. The v11 ruleset owns exactly these stable authored options:

| selector value | monster key | player label | execution cost |
|---:|---|---|---:|
| `1` | `mecha_inora` | `メカいのら` | 3,000億円 |
| `2` | `mecha_inora_zero` | `メカいのら零式` | 9,999億円 |

The initial UI selection is value `1`. The client sends the selector value but never supplies an arbitrary monster key or cost. The server resolves the selected monster and effective cost from the active v11 ruleset and revalidates that mapping at both registration and execution. Queue and preview DTOs show the selected monster and effective cost without describing the selector as quantity.

The selected value remains in the queue item’s immutable `quantity` field and therefore participates in the existing request fingerprint. An exact retry with the same value converges; reusing a request key with another value returns the existing stable conflict. Selector values are authored constants and never derive from a database ID, monster display order, or array position. New v11 requests must explicitly send the selector.

Queued v10 `monster_dispatch` rows are eligible for v11 rebind only after a fail-closed preflight proves `quantity = 1` and the historical target-only parameter shape. Such a row becomes selector value `1`, keeps the ordinary 3,000億円 execution cost, and never infers Zero. Completed, failed, and cancelled definitions and payload history remain attached to v10.

C5 adds nullable immutable request-ruleset provenance. The fingerprint column was introduced immediately before v10 publication: pre-v10 upgraded rows are null, and later service-created non-null rows must use the locked World's v10 definition. Before rebind, C5 attributes from source World, definition ruleset, release chronology, request key, and the existing hash-format constraint—not by recomputing the hash with the mutable queue position—and backfills v10 across queued and terminal statuses. Any non-null row attached to another ruleset aborts publication; null historical fingerprints remain conflict-only. Duplicate lookup occurs before current-v11 selector validation. Only a matching stored request key with proved v10 provenance, stable `monster_dispatch`, and stored quantity 1 may normalize an omitted selector to 1 for fingerprint comparison. The original selector-less v10 payload can therefore return `duplicate = true`; selector 2 conflicts, while a missing selector for a new or unproved request still fails normally.

Both selector choices retain target-Nation validation, retry/idempotency, spawn candidate rules, secrecy, and no movement on the spawn turn.

### Nuclear self-destruction

At the normal monster stage, before movement/hardening/candidate selection:

```text
if alive Mecha Inora Zero current HP == 1:
    self-destruct at current cell
    do not move
```

Use the current huge-meteor blast contract as the damage shape unless C0 finds an incompatible invariant. The explosion center is the Zero’s current cell.

Processing boundary:

1. remove Zero from occupancy/MonsterTurnBatch as `nuclear_self_destruct` without reward/stat/experience;
2. resolve one huge-meteor-compatible blast at the fixed center;
3. synchronize changed cells and monster occupancy indexes;
4. remove other monsters hit by the terrain blast through the existing unattributed rewardless removal path;
5. emit exactly one public trigger event plus normal cell-damage events;
6. prevent chain re-trigger and double processing.

Player-facing trigger text:

> ○○島(X,Y)のメカいのら零式が突然輝きだし、とてつもない爆発を起こしました！

Use the current host Nation at explosion time for the name; define a sensible neutral fallback if the cell has no owner.

Self-destruction results:

- Zero reward: 0;
- missile-base experience: 0;
- kill statistic: none;
- monster cycle count: none;
- other monsters removed by the blast: no reward/stat/experience.

Normal HP damage kill before self-destruction:

- wreckage money: 0;
- attributed kill statistic: yes;
- missile-base experience: 35 only when a firing base delivered the final blow;
- Old Bow kill: statistic/reward path as normal, but no firing-base experience.

Because HP1 self-destructs before moving, defense-facility contact can only occur while HP2–4. Existing defense self-destruct behavior remains unchanged.

## C4 tests

Minimum Aoi tests:

- exact HP range;
- probability arithmetic and stream isolation;
- radius-3 no-land candidate rule;
- shallow spawn converts to sea;
- facility/owner/occupancy candidate rejection;
- no-candidate no destructive fallback;
- no spawn-turn movement;
- water-only movement;
- seabed facility destruction and clear log;
- neutralization to sea/owner null;
- ordinary land-monster behavior unchanged;
- 100% hostless killer payout, capacity, base XP18, kill stat;
- island creation removes Aoi only from exact planned writes without reward/stat and proceeds;
- Aoi on a reserved but unchanged cell survives, and ordinary occupied writes fail closed without removal.

Minimum Zero tests:

- one `monster_dispatch` command exposes exactly selector values `1` and `2`;
- default/explicit selector `1` costs 3,000億 and dispatches ordinary Mecha Inora;
- selector `2` costs 9,999億 and dispatches Zero;
- missing/invalid selector, arbitrary monster key, and client-supplied cost are rejected without mutation;
- selected cost is revalidated at execution and shown with the selected monster in queue/preview DTOs;
- same-selector retry converges and same request key with a different selector returns the stable conflict;
- valid queued v10 dispatch maps only to selector `1`; anomalous live rows fail migration closed;
- non-null v10 fingerprints receive immutable provenance for queued/completed/failed/cancelled rows;
- a repositioned queued v10 dispatch migrates without recomputation, retains its hash byte-for-byte, and
  an exact retry uses the original requested position rather than the mutable current position;
- the original selector-less v10 payload returns `duplicate = true` in every status, selector `2`
  conflicts, and selector omission without a proved v10 request key is rejected;
- completed/failed/cancelled v10 definitions, payloads, statuses, and fingerprints remain unchanged;
- fixed HP4 and no natural spawn;
- no spawn-turn movement;
- HP4/3/2 normal movement;
- HP1 nuclear self-destruct before movement;
- exact public message;
- huge-meteor-equivalent cell effects;
- self and collateral monster reward/stat/XP all zero;
- normal kill gives stat and base XP35 but no money;
- defense contact at HP2+ unchanged;
- Old Bow HP2 avoids, HP1 legal, HP3 legal;
- retry/rollback/MonsterTurnBatch synchronization;
- no chain explosion or duplicate event.

---

# C5 — one v11 publication and release integration

Do not create the final published `hakoniwa-2s-plus-v11.php` during C1–C4. Earlier checkpoints may use purpose-built test RulesetVersion fixtures/settings. C5 creates the single final authored v11 payload.

## v11 content

v11 inherits v10 and adds only the final ver 2.3.0 gameplay contract:

- equipment effect stage after ordinary cell events and before normal monsters;
- Secretary item gameplay definitions;
- bow/ring category and same-item limits;
- Old Bow effect and target-safety contract;
- Ring finance stacking;
- monster display order;
- fixed-eight removal contract;
- Aoi Inora definition, World spawn, water movement, hostless reward, island displacement;
- Mecha Inora Zero definition, the ruleset-owned two-option selector on the existing `monster_dispatch` command, option-specific 3,000/9,999億 costs, and nuclear self-destruct;
- any minimal random stream version labels required for deterministic replay.

v1–v10 config files, rows, checksums, and applied migrations remain unchanged.

## v11 migration

Forward-only, idempotent, all-or-nothing.

Minimum boundaries:

- common World advisory lock;
- World row lock;
- reject next non-dry pending/running/failed/blocked TurnRun;
- exact source v10;
- publish v11;
- stable command/resource/facility/terrain/monster key checks;
- World rebind;
- immutable request-ruleset provenance backfill for every safely attributable non-null v10 fingerprint,
  including completed/failed/cancelled rows; ambiguous rows fail closed and null hashes stay null;
- queued command definition rebind only;
- alive monster instance rebind only;
- current/live kill-stat total rebind only where current contract requires it;
- killed/removed MonsterInstance history remains on historical definitions;
- completed/failed/cancelled queue definitions and payload history remain historical;
- Secretary Item instances, equipped slots, grant identity, and equipment version preserved;
- constraints/triggers restored and verified;
- exact second-run idempotency;
- forced failure leaves no partial publication.

Existing starter Old Bow instances are already equipped in slot 1. After v11 activation, they begin working from the first v11 TurnRun unless the player has unequipped them. This is intentional and must be included in player release notes.

## Final validation

At minimum:

- all focused checkpoint suites;
- full backend PHPUnit;
- serial/16-shard identifier equivalence;
- full PHPStan app scan;
- Pint;
- frontend Vitest;
- ESLint;
- `vue-tsc --noEmit`;
- Vite production build;
- ruleset validator;
- v1–v10 immutability;
- v11 contract/checksum;
- all new forward migrations;
- `migrate:fresh`;
- open-question validator;
- retry determinism and rollback;
- random-stream isolation;
- query/performance profiles for equipment options, 50-Item warehouse, Secretary snapshots, normal monster pass, Aoi candidate scan, and huge-meteor self-destruct;
- asset fallback tests;
- manual rendering;
- `git diff --check`;
- `_references` diff 0;
- final Codex review and unresolved P0–P2 = 0.

Before declaring PR readiness:

1. delete `.codex/tasks/ver-2.3.0.md`;
2. verify no `.codex/tasks/ver-2.3.0*` file remains in the final diff;
3. ensure durable decisions are recorded in normal docs/ADR/manual instead;
4. do not merge/deploy/production-connect without explicit Owner approval.

---

# C0 audit results

Completed 2026-08-19. The full A-R evidence and design record is
[`product/docs/ver-2.3.0-c0-audit.md`](../../product/docs/ver-2.3.0-c0-audit.md).

## Owner amendment incorporated

- Keep only `monster_dispatch`; never add `monster_dispatch_zero`.
- Reuse/generalize the existing non-ordinary quantity selector.
- Persist authored selector value `1 = mecha_inora / 3,000億` and
  `2 = mecha_inora_zero / 9,999億` in queue `quantity`.
- Value 1 is the UI default, but v11 registration sends the selector explicitly.
- Server/v11 ruleset owns monster key and cost; arbitrary keys/costs are rejected.
- Registration, queue presentation, fingerprint, and execution all resolve the same option.
- A valid queued v10 dispatch proves quantity 1 and maps only to ordinary Mecha Inora at
  3,000億. Proved non-null v10 fingerprints receive immutable provenance in every status so the original
  selector-less retry converges; ambiguous rows fail closed, and terminal definition/payload history is
  otherwise untouched.

The superseded standalone amendment used a 1,000/5,000 explicit-parameter proposal and has been
removed so this file is the single temporary Owner-contract source.

## Checkpoint audit table

| Verified fact | Required change | Proposed implementation | Test/evidence | Owner decision | Checkpoint |
|---|---|---|---|---|---|
| `CommandQuantitySemantics` already has selector UI/validation/label/edit exclusion but supports monument DB IDs only (`product/app/Application/CommandQuantitySemantics.php:18-110`) | safe ruleset-authored dispatch choices | delegate authored value/label/key/cost to narrow `MonsterDispatchOptionResolver`; keep `quantity` storage/fingerprint | values independent of DB/display/order; invalid/missing/client key/cost; retry/conflict | decided | C4 |
| queue fingerprint includes canonical parameters, quantity, ruleset, target and original requested position, which is not separately retained after reorder (`CommandQueueService.php:277-314`); duplicate lookup currently occurs after selector validation | normalize v10 retries without forging or reconstructing hash input and without rejecting the original selector-less payload | attribute provenance from v10 release chronology/source definition and DB format, never current position; preserve hash byte-for-byte; lock duplicate before v11 selector validation and normalize omitted selector to 1 only for a proved old request key | repositioned hash preservation/original-position retry, queued/completed/failed/cancelled selector-less retry, selector-2 conflict, null/ambiguous provenance, rollback/idempotent second run | decided | C5 |
| catalog/queue/executor assume static definition cost (`CommandQueueController.php:36-178,370-440`; `DomesticCommandExecutor.php:403-547`) | 3,000/9,999 selected cost | retain truthful default definition cost 3,000; one typed effective-cost result for preview, queue, validation, deduction and events | shortfall, insufficient execution funds, queue label/cost, no fictional static cost | decided | C4 |
| five Item slots/storage exist but no mutation/version, and `(user_id, world_id)` permits one active owned Nation in each of multiple Worlds (`SecretaryItemPresenter.php:13-55`; `SecretaryController.php:15-55`; `2026_07_26_000000_create_hakoniwa_schema.php:109-115`) | atomic globally consistent equip/unequip without a phantom membership insert | shared User membership-set advisory lock is first for registration/equipment, then every active owned World in stable order, then Secretary/Items; zero-World path retains the user lock | registration race in both orders, zero/one/multiple Worlds, guard in any World, partial lock release, no-op/version conflict/mobile/keyboard | decided | C1 |
| current membership-set writers are registration create and abandonment delete; abandonment holds only the World lock, deletes the membership/queue, removes monsters, and rewrites cells/Nation without an unresolved-run guard (`NationCreationService.php:88-156`; `NationAbandonmentService.php:45-224`) | freeze the complete membership set and retry inputs across equipment, create, and delete | require registration and abandonment to take User then World lock and apply the same next non-dry four-status guard before mutation | equipment/create/delete races in both orders, four statuses, active state and all dependent rows unchanged on rejection | decided | C1 |
| `/api/v1/me/secretary` has no World identifier while Item effects are ruleset-owned and a User may own Nations in multiple Worlds (`SecretaryController.php:15-25`; `SecretaryItemPresenter.php:13-55`) | prevent cross-ruleset effect misrepresentation | keep the User-global DTO neutral and derive effect text only for an explicit authorized World; omission always remains neutral, including with no active Nation | same User v10/v11 projections, omitted/unauthorized World, neutral mutation/name response, zero-Nation omission | decided | C2 |
| turn state snapshots four skills only; v9+ has a missile-finalize/normal-monster seam (`SecretaryTurnService.php:21-66`; `CompleteTurnEngine.php:327-441`) | deterministic Item effects | snapshot v11 Item/effect identity at prepare; Old Bow after missiles/before monsters; Ring in centralized explicit/automatic finance | v10 retry effect-free, stream isolation, safe target, reward/XP, capacity | decided | C2 |
| validator/spawn/public detail/ranking encode exact eight/kind 0..7 (`RulesetAuthoringValidator.php:619-697`; `MonsterSpawnService.php:45-52`; `PublicWorldService.php:82-113`; `PublicRankingAchievementProjection.php:101-142`) | additive v11 display | nullable DB order, null historical fallback `kind*100`, v11 explicit unique order, no public limit, max killed order representative | 10+ species, totals/order/representative, duplicate/null validation, v1-v10 immutable | decided | C3 |
| huge meteor/removal/batch paths are reusable (`DisasterTurnService.php:672-748,946-1004`; `MonsterRemovalService.php`) | Aoi/Zero behavior | Aoi World substage after subsidence/before natural spawn; water-neutralizing movement; Zero removal before one fixed-center blast | candidate radius/query bounds, displacement, hostless payout, self/collateral rewardless, no chain | delegated details fixed by C0 | C4 |
| initial island holds World lock/transaction and validates radius-5 reservation, but registration has no unresolved-next-TurnRun guard and actual writes are the smaller seed-dependent dirty set whose occupancy is ignored (`NationCreationService.php:45-188`; `LegacyInspiredInitialIslandGenerator.php:19-181`) | preserve retry inputs while ensuring Aoi cannot block an actual island write and unrelated reserved Aoi survives | under the user then World lock, reject next non-dry pending/running/failed/blocked before any registration write; then build one immutable seed-derived plan, lock exact changed-cell occupancies, remove only Aoi there, and apply it once | four unresolved statuses/no partial registration, changed-cell displacement, outer reserved survival, one RNG consumption, ordinary occupancy fail closed, rollback | decided | C1/C4 |
| v10 migration uses common stable-key equality and live-only rebind (`2026_08_19_010000_publish_hakoniwa_2s_plus_v10.php:50-131`) | additive, all-or-nothing v11 | exact same command keys; target monsters = common eight + exact two; rebind queued/alive/live stats only; preserve history | source/guard/constraint/trigger/forced-failure/second-run tests | decided | C5 |
| historical migration calls mutable `SecretaryItemGrantService::grantStarterOldBow()` (`2026_08_17_010000_create_secretary_items_and_inquiries.php:29-34`) | prevent fresh-install semantic drift | keep grant path independent of C1/v11; version-aware publisher/validator and schema-aware nullable display field; run fresh chain every checkpoint | `migrate:fresh`, migration rerun, exact old-bow row, old ruleset checksums | engineering constraint, no STOP | C1-C5 |

## Frequency, random, and query boundaries

Aoi keeps `min(10000, active_owned_land_cells) / 10000`, one World trigger per target turn.
For `L > 0`, expected trigger interval is `10000 / min(10000, L)` turns; it is one turn at
`L >= 10000`, while `L = 0` never triggers.
Dimension-only maximum trigger probabilities are 36.00% at 60x60, 40.96% at 64x64,
51.20% at 80x64, and 92.16% at 96x96; actual frequency uses live active-owned land and is
zero on a triggered turn with no valid remote-water candidate. Candidate scan is bounded to one
surface snapshot plus in-memory radius-3 marking, at most 340,992 coordinate marks at 96x96.

New streams are dedicated to Old Bow trigger/target and Aoi trigger/candidate/HP. Existing stream
labels/draw populations are unchanged. Stable candidate ordering, TurnRun ruleset/seed/equipment
snapshot, transaction rollback, and batch synchronization preserve same-attempt retry.

## Historical migration dependency result

Historical migrations call mutable Application services through `RulesetPublisher` (19 files),
`SecretaryV1MigrationSafetyGuard` (6 files), and `SecretaryItemGrantService` (1 file). No migration
directly calls a Domain catalog, but GrantService indirectly uses `SecretaryItemCatalog` and Publisher
uses the current `RulesetAuthoringValidator`. Current isolated test-DB `migrate:fresh` succeeds.

C1-C5 must preserve the old-bow grant wrapper and old ruleset validation before later columns/v11
exist. In particular, C3 publisher handling must not unconditionally insert `display_order` while an
early historical migration is running before the C3 column migration. Record a frozen schema/data
fresh-install baseline with literal starter Item data and published snapshot checksums as a separate
high-priority post-2.3.0 cleanup; do not edit applied migrations in this release.

## Branch handoff and STOP

No unresolved Owner decision and no C0 STOP condition remain. C1 starts only after this C0 PR is
final-head reviewed with unresolved P0-P2 = 0, explicitly merged to `release/ver-2.3.0`, and a clean
C1 branch is created from the re-verified release HEAD. The subsequent order is C1 equipment, C2 Item
effects, C3 monster foundation, C4 Aoi/Zero/dispatch, and C5 final v11 publication/migration. Each
checkpoint targets the release branch and receives its own focused validation/review.

---

# C1 progress/results

Implemented on `codex/ver-2.3.0-c1-equipment` from release baseline
`45cfa49fdff08ad764701012b27708b61f211172`.

## Final schema and historical compatibility

- Forward migration `2026_08_20_000000_add_secretary_equipment_version.php` adds unsigned bigint
  `secretaries.equipment_version`, default/backfill `1`, plus PostgreSQL `>= 1` check. The migration is
  forward-only and does not rewrite Item rows.
- Meaningful commit increments exactly once; exact no-op, rejection, and rollback do not increment.
- The historical v2.2.0 grant path remains unchanged and independent of C1 schema/API/World/v11. Fresh
  and repeated execution regression proves exactly one level-1 `old_bow`, slot 1,
  `starter:old_bow`, no Ring, and equipment version 1.
- Runtime catalog remains Old Bow only. C1 generic category/same-item behavior uses test-only fixtures;
  Ring presentation/acquisition/effects remain C2.

## Lock ordering, affected Worlds, and TurnRun guard

`SecretaryEquipmentService` acquires `UserMembershipMutationLock`, snapshots all active owner
memberships, sorts unique Worlds ascending, acquires every `WorldMutationLock`, then opens the DB
transaction. It locks Worlds, owner memberships/Nations, Secretary, and all owned Items in stable order,
rechecks the frozen membership set, validates the complete final five-slot state, writes atomically, and
releases Worlds in reverse then the User lock in `finally`. A partial World-lock failure releases only
already-acquired locks in reverse and writes nothing. Zero active Worlds skips World locks but retains
the User lock and Secretary/Item transaction.

`NextProductionTurnRunGuard` uses the existing authoritative `TurnRun::unresolvedProduction()` scope
and the exact next target turn. Any affected World's next non-dry `pending`, `running`, `failed`, or
`blocked` run rejects the whole mutation; completed/history and dry runs do not block it.

Nation registration and abandonment now take the same User-then-World order and apply that guard before
membership/game-state writes. Abandonment otherwise retains its prior validation, history, audit,
resource, monster, cell, queue, and Secretary/User persistence behavior. Separate-process PostgreSQL
tests prove equipment-first, abandonment-first, and registration-first orderings, including authoritative
post-abandonment zero-World enumeration and newly registered World detection.

## API, policy, audit, and query result

- `GET /api/v1/me/secretary/equipment/{slot}/options`: slot, version, current Item, legal candidates,
  category-limit metadata, and nullable effect context. The active-Nation UI sends explicit `world_id`;
  the server verifies active owner membership and returns exact World/ruleset identity.
- `PUT /api/v1/me/secretary/equipment/{slot}`: nullable `item_id`, required `expected_version`, neutral
  authoritative Secretary response.
- Stable conflicts: 409 `secretary_equipment_version_conflict` plus membership/World/TurnRun conflicts;
  player-safe invalid slot/ownership/policy is 422 `secretary_equipment_invalid`.
- Options are current Item first then legal unequipped Items; Vue prepends `外す`. Items equipped in
  another slot are omitted and forged moves are rejected. Mutation repeats complete final-state
  category, same-item, instance, ownership, level, and slot validation.
- Meaningful mutation writes private `secretary.equipment_changed` metadata with User/Secretary/slot,
  prior/new Item key/ID, and prior/new version. No-op creates no audit event and no public news.
- Exact options query count is 2 for a neutral request and 3 for an explicit owned World for empty
  inventory, Old Bow only, 50 Items, and all five slots occupied; Item count adds no SQL queries.

## UI and interaction result

The existing five compact slots are buttons. `SecretaryEquipmentModal.vue` supplies a native vertical
scroll list, `外す` first, current selection default, legal server candidates, fixed external footer,
top-right close button, backdrop/Escape cancellation, focus entry/return and Tab containment, native
radio keyboard behavior, mobile layout, loading/duplicate-submit prevention, inline backend error, and
409 authoritative Secretary/options refresh that requires a fresh choice. Modal rows omit Warehouse
flavor and category-as-effect substitution. They reserve a nullable effect line; v1-v10 return no Item
effect sentence while the verified context identifies the exact owned World/ruleset. Compact category
badges remain on the equipment page. C1 keeps turn processing and v1-v10 rulesets effect-free.

## Validation and C0 divergence

- Focused backend: schema/history 3 tests/7 assertions; equipment 12/106; registration/abandonment guard
  13/27; isolated PostgreSQL concurrency 5/68.
- Focused frontend: modal 4 tests; App API/stale/error/success integration 1 test; ESLint and
  `vue-tsc --noEmit` pass.
- Local final validation: full frontend Vitest 125 tests, production build, full-app PHPStan, Pint,
  open-question validator, `migrate:fresh`, v10 ruleset command, 157 authoring/immutability tests,
  86 Secretary/Nation/TurnRunner/complete-turn tests plus the corrected 12-test equipment rerun, and
  5 existing PostgreSQL registration/abandonment lock tests pass. Ruleset source diff and `_references`
  diff are both zero.
- PR #65's initial Quality run `32334209852` passed. Three Codex review rounds found four P2 boundary
  regressions: completed registration replay ran after the unresolved-TurnRun guard, negative slot
  routes bypassed the stable equipment error, and oversized numeric slot strings overflowed typed
  controller dispatch; the active-Nation modal also omitted the Owner-required explicit World effect
  context. C1 now proves all four unresolved statuses replay the completed Nation without writes,
  GET/PUT negative and oversized slots return 422 `secretary_equipment_invalid`, and options verify the
  explicit owned World while v1-v10 return nullable effect text. Final-head CI/review/thread evidence
  belongs in the PR handoff so a documentation-only evidence edit cannot invalidate the reviewed HEAD.
- C0 hypothesis divergence: no runtime Ring definition or gameplay effect DTO was needed in C1. The
  earlier Owner C1 UI requirement outranks the C0 audit's C2 staging suggestion, so C1 includes the
  authorized World/ruleset presentation context and nullable effect line. C2 still owns Ring catalog
  data, v11-derived sentences, snapshots, and gameplay effects; C1 invents no v10 effect text.

Durable architecture and the exact C2 entry boundary are recorded in
[`product/docs/ver-2.3.0-c1-equipment.md`](../../product/docs/ver-2.3.0-c1-equipment.md). C2 begins only
after C1 is integrated into `release/ver-2.3.0` with green Quality and unresolved P0/P1/P2 all zero. It
must preserve C1 lock/version/API semantics and v1-v10 effect-free behavior; v11 publication remains C5.
