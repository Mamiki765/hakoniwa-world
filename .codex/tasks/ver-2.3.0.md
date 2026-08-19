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
2. if the User owns an active Nation, acquire the shared `WorldMutationLock` before the DB transaction;
3. reject mutation while the next non-dry TurnRun is pending/running/failed/blocked, so a failed same-TurnRun retry cannot observe changed equipment;
4. lock the Secretary row;
5. lock relevant Item rows in stable ID order;
6. verify `expected_version`;
7. build the complete proposed equipment state in memory;
8. validate slot range, Item ownership, category limits, same-item limits, and all final-state invariants before writes;
9. atomically clear/replace the target slot without exposing an intermediate invalid final state;
10. increment the equipment version exactly once for a meaningful mutation;
11. return a no-op result without version increment when the chosen state already matches;
12. record a private/admin audit event without leaking hidden Item state publicly;
13. release World lock in `finally`.

If no active Nation exists, equipment may still be changed because Items belong to the User/Secretary, but no World TurnRun is affected. C0 must verify the cleanest locking path for this case.

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
- failed TurnRun blocks gameplay-affecting equipment mutation;
- no effect reaches turn processing in C1;
- options filtering and API mutation policy agree;
- keyboard/modal/mobile behavior.

---

# C2 — item gameplay definitions and effects

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

> 10%の確率で、自領内の怪獣に1ダメージを与える。

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
- DTO and warehouse/modal effect text matches v11 parameters.

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

Do not remove ordinary land monsters beyond the exact Owner decision. C0 must audit the reservation/generation footprint and lock ordering before C4 implementation.

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

C5 adds nullable immutable request-ruleset provenance. Before rebind it backfills v10 for every non-null fingerprint, across queued and terminal statuses, that can be structurally proved to originate from v10; any ambiguous non-null row aborts publication and null historical fingerprints remain conflict-only. Duplicate lookup occurs before current-v11 selector validation. Only a matching stored request key with proved v10 provenance, stable `monster_dispatch`, and stored quantity 1 may normalize an omitted selector to 1 for fingerprint comparison. The original selector-less v10 payload can therefore return `duplicate = true`; selector 2 conflicts, while a missing selector for a new or unproved request still fails normally.

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
- island creation removes Aoi without reward/stat and proceeds.

Minimum Zero tests:

- one `monster_dispatch` command exposes exactly selector values `1` and `2`;
- default/explicit selector `1` costs 3,000億 and dispatches ordinary Mecha Inora;
- selector `2` costs 9,999億 and dispatches Zero;
- missing/invalid selector, arbitrary monster key, and client-supplied cost are rejected without mutation;
- selected cost is revalidated at execution and shown with the selected monster in queue/preview DTOs;
- same-selector retry converges and same request key with a different selector returns the stable conflict;
- valid queued v10 dispatch maps only to selector `1`; anomalous live rows fail migration closed;
- non-null v10 fingerprints receive immutable provenance for queued/completed/failed/cancelled rows;
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
| queue fingerprint includes canonical parameters, quantity, ruleset, target and original requested position, which is not separately retained after reorder (`CommandQueueService.php:277-314`); duplicate lookup currently occurs after selector validation | normalize v10 retries without forging hash input or rejecting the original selector-less payload | preflight every status; queued quantity 1 -> regular option; preserve hash; backfill nullable v10 request provenance for every safely attributable non-null fingerprint; lock duplicate before v11 selector validation and normalize omitted selector to 1 only for a proved old request key | queued/completed/failed/cancelled selector-less retry, selector-2 conflict, null/ambiguous provenance, reorder/rollback/idempotent second run | decided | C5 |
| catalog/queue/executor assume static definition cost (`CommandQueueController.php:36-178,370-440`; `DomesticCommandExecutor.php:403-547`) | 3,000/9,999 selected cost | retain truthful default definition cost 3,000; one typed effective-cost result for preview, queue, validation, deduction and events | shortfall, insufficient execution funds, queue label/cost, no fictional static cost | decided | C4 |
| five Item slots/storage exist but no mutation/version (`SecretaryItemPresenter.php:13-55`; `SecretaryController.php:15-55`) | atomic equip/unequip | `equipment_version=1`; active Nation uses World lock + unresolved-run guard; no-Nation uses Secretary/Item transaction; stable locks and full-state validation | no-op/version conflict/concurrency/all TurnRun statuses/mobile/keyboard | decided | C1 |
| turn state snapshots four skills only; v9+ has a missile-finalize/normal-monster seam (`SecretaryTurnService.php:21-66`; `CompleteTurnEngine.php:327-441`) | deterministic Item effects | snapshot v11 Item/effect identity at prepare; Old Bow after missiles/before monsters; Ring in centralized explicit/automatic finance | v10 retry effect-free, stream isolation, safe target, reward/XP, capacity | decided | C2 |
| validator/spawn/public detail/ranking encode exact eight/kind 0..7 (`RulesetAuthoringValidator.php:619-697`; `MonsterSpawnService.php:45-52`; `PublicWorldService.php:82-113`; `PublicRankingAchievementProjection.php:101-142`) | additive v11 display | nullable DB order, null historical fallback `kind*100`, v11 explicit unique order, no public limit, max killed order representative | 10+ species, totals/order/representative, duplicate/null validation, v1-v10 immutable | decided | C3 |
| huge meteor/removal/batch paths are reusable (`DisasterTurnService.php:672-748,946-1004`; `MonsterRemovalService.php`) | Aoi/Zero behavior | Aoi World substage after subsidence/before natural spawn; water-neutralizing movement; Zero removal before one fixed-center blast | candidate radius/query bounds, displacement, hostless payout, self/collateral rewardless, no chain | delegated details fixed by C0 | C4 |
| initial island holds World lock/transaction and validates the reservation, but ignores occupancy (`NationCreationService.php:45-188`; `LegacyInspiredInitialIslandGenerator.php:19-47`) | Aoi cannot block registration | before validation, lock reservation occupancies and remove only Aoi through rewardless World-mutation removal | registration proceeds; ordinary monsters untouched; rollback | decided | C4 |
| v10 migration uses common stable-key equality and live-only rebind (`2026_08_19_010000_publish_hakoniwa_2s_plus_v10.php:50-131`) | additive, all-or-nothing v11 | exact same command keys; target monsters = common eight + exact two; rebind queued/alive/live stats only; preserve history | source/guard/constraint/trigger/forced-failure/second-run tests | decided | C5 |
| historical migration calls mutable `SecretaryItemGrantService::grantStarterOldBow()` (`2026_08_17_010000_create_secretary_items_and_inquiries.php:29-34`) | prevent fresh-install semantic drift | keep grant path independent of C1/v11; version-aware publisher/validator and schema-aware nullable display field; run fresh chain every checkpoint | `migrate:fresh`, migration rerun, exact old-bow row, old ruleset checksums | engineering constraint, no STOP | C1-C5 |

## Frequency, random, and query boundaries

Aoi keeps `min(10000, active_owned_land_cells) / 10000`, one World trigger per target turn.
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
