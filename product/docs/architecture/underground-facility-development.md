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

The published Surface Ruleset v19 is immutable and is not republished or replaced by this release-branch feature.

The five facility commands belong to `UndergroundCommandCatalog`, backed by `config/underground-facilities.php`. They are deliberately absent from Surface `command_definitions`. A queued Underground item still records the current World's Ruleset version as request provenance, but it has no Ruleset-bound `command_definition_id` and does not alter that published Ruleset payload.

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
- `layer`
- `slot_index` (`0..3`)
- `facility_key`

Empty slots have no row. A unique constraint on `(nation_id, layer, slot_index)` prevents concurrent double occupancy. The Nation foreign key cascades on deletion, so a replacement Nation never inherits the previous Nation's facilities. Secretary-owned `unlocked_area_layers` remains independent and is not deleted with a Nation.

The entrance and fixed ladders are presentation elements, not facility targets. Only the four slots in each unlocked layer can be selected.

## Queue and official Turn integration

Reservation uses the existing Nation development queue, request fingerprint, version check, ordering, and locking. It does not debit money or mutate a facility.

The queue stores `target_context`, `target_layer`, `target_slot_index`, and `underground_command_key` for Underground items. A small slot projection evaluates prior queued Underground commands for the same slot, allowing sequences such as remove then build while rejecting impossible projected sequences. Surface cell projection remains separate.

At official Turn execution, `DomesticCommandExecutor` revalidates ownership, Secretary entitlement, Trial 1 first clear, unlocked layer, slot range, and current occupancy. Money debit and facility mutation occur in the existing Turn transaction. Success consumes one Turn; failure follows the existing domestic-command failure, queue-removal, and automatic-finance semantics.

## Facility effects

Effects are derived from Nation-owned facility counts; Surface `MapCell` rows and Surface facility scales are unchanged.

- `underground_city`: adds 10,000 to the capital population growth ceiling. It neither adds population at construction nor truncates current population when removed.
- `underground_farm`: adds 10,000 to aggregate farm employment capacity and participates in the existing workforce and wheat-production calculation.
- `underground_factory`: adds 30,000 to aggregate factory employment capacity and participates in the existing workforce and industrial-production calculation.
- `underground_missile_base`: contributes one shot source to the existing missile pipeline, with no facility scale.

## Missile source attribution and experience

Each launched shot retains one source identity:

- `surface_missile_base` with the Surface base cell id
- `underground_missile_base` with the Underground facility row id

Surface and Underground capacity is additive, while missile types, costs, salvo restrictions, RNG order, deviation, impacts, terrain damage, monster interaction, and failure behavior remain in the canonical missile path.

When an Underground-sourced shot satisfies the same monster-hit experience condition as a Surface missile base, its existing missile-base experience amount is passed to `SecretaryExperienceAwardService::awardMonster`. This preserves canonical Secretary modifiers, caps, audit, and persistence. The shot does not also award Surface base experience, and it never changes `underground_profiles.combat_xp`, Combat Lv, STP, or Underground battle growth.

## Visibility and assets

The facility layout is returned only through the existing owner-only Underground surface-map projection. Public island previews do not receive it.

Slot images are selected through the existing external Underground asset resolver. Empty slots use `Ug_road.gif`; the four facilities use `Ug_tosi.gif`, `Ug_farm.gif`, `Ug_fact.gif`, and `Ug_kiti.gif`. No binary asset is stored in the repository, and the existing fallback remains active when external files are absent.
