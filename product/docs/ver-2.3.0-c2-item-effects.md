# ver 2.3.0 C2 Secretary Item effects contract

Checkpoint 2 adds ruleset-owned gameplay definitions, turn-start snapshots, Old Bow damage, Ring finance, and ruleset-aware presentation for the existing User-global Secretary equipment. It does not publish ruleset v11, add a v11 migration, add a Ring acquisition path, or change the C1 lock/version/mutation API.

## Shared equipment and effect scope

Item instances, five equipment slots, and `equipment_version` belong to the Secretary/User. The same equipment state is shared by every active owned Nation and every MapSpace; there is no per-World, per-Nation, per-MapSpace, or per-screen equipment set.

Effect scope is narrower than equipment ownership. C2 supports exactly two closed effects:

- Old Bow is evaluated for each active Nation and may target only an eligible monster on that Nation's owned cells in the World's `surface` MapSpace.
- Ring is a Nation-wide economy effect with no MapSpace target. Its equipped levels apply to finance for each active Nation using the shared equipment snapshot.

C2 deliberately adds no generic layer/effect expression framework. A User with no active Nation retains the Secretary and equipment but has no gameplay Nation to receive an effect. The UI continues to omit Secretary navigation on that path. An omitted Secretary presentation `world_id` is always neutral, including for a User with no active Nation.

## Catalog and ruleset boundary

`SecretaryItemCatalog` owns stable application presentation and global equipment legality. Old Bow remains category `bow`, maximum level 1, category maximum 1, same-Item maximum 1, and the sole starter grant. Ring adds category `ring`, maximum level 10, category maximum 5, same-Item maximum 5, Japanese name `指輪`, and flavor `貴金属が使われた豪華な指輪。魔法の道具ではないが、贈り物にはぴったりだ。`. Adding Ring to this catalog does not grant it.

Probability, damage, timing, finance value, stacking, target scope, and safety policy belong only to immutable ruleset settings. Item instance rows retain identity and mutable equipment state only: key, level, slot, grant identity, and obtained time. Presentation derives its effect sentence from the selected ruleset settings and Item level; the application catalog does not provide a versionless gameplay sentence.

The only C2 gameplay authoring source is the test-only `V11SecretaryItemRulesetFixture`. Its key is `test-hakoniwa-2s-plus-v11-secretary-items`, it is inactive and absent from the production registry, and tests create its row directly. The closed contract contains exactly `bow` and `ring`, exactly `old_bow` and `ring`, integer-only parameters, and no unknown fields:

- Old Bow: `pre_normal_monster_attack`, timing `after_missile_finalization_before_normal_monsters`, 1,000 basis points, damage 1, `secretary_old_bow`, owned territory, target MapSpaces `[surface]`, safety `avoid_ineffective_or_immediate_hazard`, random stream version 1.
- Ring: `finance_income_bonus`, one money unit per level, stacking `sum_equipped_levels`.

The authoring validator invokes this contract whenever Secretary Item definitions are present. Missing, open-ended, floating-point, catalog-divergent, or unknown definitions fail closed. An Item ruleset must also declare `turn_resolution.normal_monster_stage = after_ordinary_surface_cell_events`; a missing or different stage is rejected before publication rather than failing every turn at runtime.

## Attempt snapshot and determinism

Only a TurnRun ruleset containing the Item contract creates an Item/effect snapshot during `prepare_turn`. Equipped rows are batch-loaded in Secretary, slot, and instance order. Each active Nation receives a separate immutable snapshot alongside the existing skill snapshot:

```text
nation_id -> {
  secretary_id,
  equipment_version,
  items: [{
    item_instance_id,
    item_key,
    category,
    level,
    equipped_slot,
    effects: [{
      type,
      timing,
      parameters,
      target_map_space_keys,
      random_stream_version
    }]
  }]
}
```

The snapshot accepts only valid unique slots and resolved contract fields. Unequipped inventory is excluded. Later equipment or database changes cannot alter the started attempt; transaction rollback followed by the same-target-turn, same-ruleset, same-seed retry reconstructs the same snapshot and result.

Old Bow uses two isolated labels per Nation and contract stream version:

```text
secretary_item:old_bow:nation:{nation_id}:trigger:v1
secretary_item:old_bow:nation:{nation_id}:target:v1
```

An eligible Nation with no safe candidate consumes neither stream. A Nation with candidates consumes one trigger draw; a miss consumes no target draw; a trigger success consumes one uniform target draw. Existing missile, monster, disaster, and growth stream labels and populations are unchanged.

## Old Bow stage and target safety

Old Bow runs once after every ordinary shuffled surface-cell event and missile finalization, and immediately before the existing separated normal-monster pass. It requires that explicit seam and fails closed if a ruleset with Item effects does not separate normal monsters. It reads current post-missile alive occupancy, processes active Nations in stable order, and groups candidates without per-Nation or per-monster queries.

Candidates must currently be alive under the TurnRun ruleset, occupy the exact active World `surface` MapSpace, and stand on the attacking Nation's owned cell. Hardened monsters are excluded using the shared hardening policy. Ruleset-owned monster metadata may additionally declare `secretary_item_target_safety = {policy: certain_self_action_at_remaining_hp, remaining_hp: N}`. A nonlethal result at exactly that hazardous HP is excluded; a kill and every non-hazardous remaining HP are allowed. JSON object field order has no meaning, while the exact field set and value types remain fail-closed. Malformed safety metadata fails closed. The policy does not branch on a raw monster key.

On a hit, `MonsterDamageService` is the sole damage path. The active Nation is the killer and firing base is null. Nonlethal damage updates the shared monster batch before normal movement. A kill detaches occupancy, credits the existing wreckage/reward path, increments normal Nation and cycle kill statistics exactly once, and grants no missile-base or Secretary skill experience. The exact player-facing effect remains:

> 10%の確率で、自領の地上にいる怪獣に1ダメージを与える。

## Ring finance priority

The centralized finance function serves both an explicit `finance` command and automatic finance. It first applies the legacy base amount against the resolved money capacity, then applies the sum of equipped Ring levels against the capacity remaining after base finance. Thus base finance has priority and Ring overflow cannot displace it. Other money sources, including logging, receive no Ring bonus.

The existing finance event remains a single player log. When a Ring bonus is present, its audit metadata separates base requested/applied/overflow, equipped level sum, Ring requested/applied/overflow, total applied, final money, source, and resolved capacity. With no Ring snapshot, the exact legacy metadata shape is retained. Turn metrics aggregate Ring Nations and requested/applied/overflow amounts without duplicating finance.

## Presentation and UI

The neutral Secretary, rename, and equipment mutation responses do not resolve a ruleset. `GET /api/v1/me/secretary` and equipment options accept an optional explicit `world_id`. Omission performs no World/ruleset query and returns root `effect_context: null` plus per-Item `effect_text: null`. An explicit context requires an authenticated active owner membership and resolves the exact World, immutable RulesetVersion row, key, version, and settings in one joined query. Unauthorized, inactive, missing, or invalid World context returns the stable player-safe 422 response; there is no configured-current or implicit active-Nation fallback.

An authorized v1-v10 World still returns null effect text. The test-only v11-shaped World derives the Old Bow and per-level Ring sentence. The active-Nation UI always sends its explicit World for reads/options, ignores neutral mutation/name response presentation, and reloads the scoped Secretary. The C1 stale-409 modal reload-and-reselect behavior is unchanged.

Warehouse cards render name/level, effect, category/equipped state, then subdued gray italic flavor. The equipment modal shows effect text but no Warehouse flavor. The five slot cards remain compact and omit effect text; this avoids making the equipment row vertically unstable while the modal and Warehouse retain full effect information.

## Query contracts

- v1-v10 `prepare_turn`: Item-effect query increase is exactly 0; no Item snapshot or Item random draw is created.
- v11-shaped `prepare_turn`: equipped Item instances add exactly 1 query for one or many active Nations, 50 inventory rows, five equipped Items, and multiple MapSpaces. Unequipped inventory size and MapSpace count do not affect it.
- Old Bow candidate construction: a stable upper bound of 5 SQL statements covers active Nations plus current occupancy, monster definition, and cell eager loads. The many-monster/two-Nation fixture executes exactly 5, independent of candidate count; there is no per-Nation or per-monster query.
- Secretary/options presentation: exactly 2 queries for neutral context and 3 for an explicit owned World, including 50 Items. Resolving effect text from already-loaded ruleset settings adds no per-Item query.

## Historical compatibility and later checkpoints

Rulesets v1-v10 contain no Item gameplay contract. They add no Item query at prepare, create no Item/effect snapshot, consume no Item stream, and execute no Item effect. A failed historical v10 TurnRun remains effect-free on same-seed retry after C2 code is deployed. Published ruleset sources, rows, checksums, existing TurnRuns, the historical starter migration, and its idempotent Old Bow grant are unchanged. C2 adds no production schema migration and no production data conversion.

C3 may begin only after this C2 PR is green in repository-required Quality, reviewed at its exact final HEAD with unresolved P0/P1/P2 all zero, explicitly integrated into `release/ver-2.3.0`, and a clean C3 branch is created from the reverified release HEAD. C3 owns the monster-extension foundation such as durable display order and removal of fixed-eight projection assumptions; it must preserve this Item snapshot/effect contract. A final `hakoniwa-2s-plus-v11.php`, immutable v11 publication, publication migration, production World rebind, and v11 compatibility migration remain exclusively C5 work.
