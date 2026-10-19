# Underground facility development

## Scope boundary

Underground facility development is ordinary Nation development below the player's own island. It is separate from the Secretary's Turn-independent Underground dungeon gameplay.

| System | Owner | Time contract | Experience contract |
|---|---|---|---|
| Underground dungeon exploration and battle | Secretary | Turn-independent | Underground combat EXP |
| Underground facility build and removal | Nation | One official World Turn | none |
| Missile fired by an Underground missile base | Nation infrastructure | Existing missile command and official World Turn | Existing missile-base award amount is granted to the Secretary's canonical monster experience |

The facility feature does not add a second scheduler, a second missile engine, Underground combat EXP, or a 3D World model.

## Surface Ruleset boundary

Use the target HEAD's [configured current Ruleset and authoring contract](current-ruleset-baseline.md). Facility and Underground command definitions remain in the dedicated `underground_facility_development` section, absent from Surface `command_definitions`. A queued Underground item has no Surface `command_definition_id`; its `underground_command_key` identifies the command and `request_ruleset_version_id` preserves request provenance for duplicate validation.

Since the current-World resolution adopted in [5.4.0](../releases/5.4.0-queue-provenance-energy.md), display, queue projection and official execution resolve that stable command key against the current World Ruleset. This does not rewrite the stored request identity or reinterpret a duplicate request using current values. [QueuedCommandDefinitionResolver](../../app/Application/QueuedCommandDefinitionResolver.php) is the shared resolution path.

### Historical introduction: release/3.1.0

At introduction, v19 was the in-development Ruleset for release/3.1.0 and could be completed before that release without adding v20; it froze at release. That historical draft status is not permission to edit v19 now. The original design resolved queued Underground values from the reservation snapshot; the later current-World contract above supersedes that part while retaining request provenance.

## Definition isolation

This separation is enforced on both boundaries:

- `surface_cell` targets resolve only Surface `CommandDefinition` records.
- `underground_slot` targets resolve only `UndergroundCommandDefinition` records.
- A request cannot contain Surface coordinates and Underground coordinates together.
- The queue database constraint requires exactly one valid target identity.

Surface command availability continues to expose commands that can become valid after earlier development-plan steps. The isolation does not narrow the Surface catalog to only commands executable on the cell's current terrain.

## Target and persistence

The canonical Underground facility target is `(nation_id, layer, slot_index)`. Player-facing `(x, y, z)` values are rendering coordinates only.

`nation_underground_facilities` stores occupied Nation-owned slots:

- `nation_id`
- `ruleset_version_id`
- `layer`
- `slot_index` (`0..3`)
- `facility_key`

Empty slots have no row. Stored `ruleset_version_id + facility_key` retains the construction provenance. Current effects resolve `facility_key` through the Nation's current World Ruleset in [UndergroundFacilityBenefits](../../app/Application/Underground/UndergroundFacilityBenefits.php); a World Ruleset advance does not rewrite the facility row. A unique constraint on `(nation_id, layer, slot_index)` prevents concurrent double occupancy. The Nation foreign key cascades on deletion, so a replacement Nation never inherits the previous Nation's facilities. Secretary-owned `unlocked_area_layers` remains independent and is not deleted with a Nation.

The entrance and fixed ladders are presentation elements, not facility targets. Only the four slots in each unlocked layer can be selected.

## Queue and official Turn integration

Reservation uses the existing Nation development queue, request fingerprint, version check, ordering, and locking. It does not debit money or mutate a facility.

The queue stores `target_context`, `target_layer`, `target_slot_index`, `underground_command_key`, and request Ruleset provenance for Underground items. Queue projection and Turn execution use the current World Ruleset, not a latest unversioned config; duplicate validation retains the original request Ruleset and fingerprint. A small slot projection evaluates prior queued Underground commands for the same slot, allowing sequences such as remove then build while rejecting impossible projected sequences. Surface cell projection remains separate.

At official Turn execution, `DomesticCommandExecutor` revalidates ownership, Secretary entitlement, Trial 1 first clear, unlocked layer, slot range, and current occupancy. Money debit and facility mutation occur in the existing Turn transaction. Success consumes one Turn; failure follows the existing domestic-command failure, queue-removal, and automatic-finance semantics.

## Facility effects

Effects are derived from Nation-owned facility counts; Surface `MapCell` rows and Surface facility scales are unchanged.

- `underground_city`: adds 10,000 to the capital population growth ceiling. It does not add population at construction or truncate on removal; while population exceeds the new effective maximum, the existing above-maximum contract reduces it by 100 per official Turn until the maximum is reached.
- `underground_farm`: adds 10,000 to aggregate farm employment capacity and participates in the existing workforce and wheat-production calculation.
- `underground_factory`: adds 30,000 to aggregate factory employment capacity and participates in the existing workforce and industrial-production calculation.
- `underground_missile_base`: contributes one shot source to the existing missile pipeline, with no facility scale.

## Missile source attribution and experience

Each launched shot retains one source identity:

- `surface_missile_base` with the Surface base cell id
- `underground_missile_base` with the Underground facility row id

Surface and Underground capacity is additive, while missile types, costs, salvo restrictions, RNG order, deviation, impacts, terrain damage, monster interaction, and failure behavior remain in the canonical missile path.

An Underground base has no MapCell, but its Nation's canonical capital cell is its processing anchor. Bases run once in stable `layer, slot_index` order when that capital cell is reached in the ordinary Surface scan; missile finalization and the later Secretary bow stage remain after all launch sources.

When an Underground-sourced shot satisfies the same monster-hit experience condition as a Surface missile base, its existing missile-base experience amount is passed to `SecretaryExperienceAwardService::awardMonster`. This preserves canonical Secretary modifiers, caps, audit, and persistence. The shot does not also award Surface base experience, and it never changes `underground_profiles.combat_xp`, Combat Lv, STP, or Underground battle growth.

## Visibility and assets

The facility layout is returned only through the existing owner-only Underground surface-map projection. Public island previews do not receive it.

Slot images are selected through the existing external Underground asset resolver. Empty slots use `Ug_road.gif`; the four facilities use `Ug_tosi.gif`, `Ug_farm.gif`, `Ug_fact.gif`, and `Ug_kiti.gif`. No binary asset is stored in the repository, and the existing fallback remains active when external files are absent.
