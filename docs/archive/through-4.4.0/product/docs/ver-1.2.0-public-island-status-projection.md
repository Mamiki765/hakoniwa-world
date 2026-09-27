# ver 1.2.0 public island status projection

## Owner decision

ver 1.2.0 adopts the original Hakoniwa island-list visibility model as an application projection contract. It does not change a published ruleset or the underlying economy.

The public island ranking and public island preview expose this basic status set:

| Status | Public value |
|---|---|
| Population | exact people |
| Area | exact owned surface-land cell count |
| Money | estimated bucket and display only |
| Food | exact aggregate tons across every food-category balance |
| Farm scale | exact people capacity |
| Factory scale | exact people capacity |
| Mine scale | exact people capacity |

The owner development screen exposes the same seven basic statuses continuously. Its money value is exact rather than estimated.

## Superseded PR19 boundary

PR19 stated that public endpoints expose no exact inventory or capacity. That remains the historical PR19 contract, but this owner decision supersedes it for one field only: aggregate exact food is public in ver 1.2.0.

This decision does not make food composition public. Public responses must not expose wheat, fish, monster meat, or future food-category balances separately. They also must not expose exact industrial-goods or minerals inventory, inventory capacities or remaining capacities, private facilities, raw audit metadata, or exact money.

Farm, factory, and mine scale are public basic-status values rather than private facility records. Their coordinates, individual cells, and other viewer-sensitive facility data continue to use the existing map projection boundary.

## Shared calculation boundary

`NationBasicStatusProjection` is the shared application aggregate for the public list, public preview, authenticated non-owner Nation response, and owner development response. It is the single calculation path for population, territory cell count, owned land area, aggregate food, and farm/factory/mine people capacities.

The projection reuses `NationLandAreaCalculator` for exact surface-land area and `FacilityCapacityService` for facility-scale conversion. Frontend code only renders projected values and does not reproduce either calculation.

## Owner screen information hierarchy

The seven basic statuses remain visible in the owner HUD. Detailed food balances, exact non-food inventory, money and resource capacities, and future operational values such as estimated food consumption or missile launch capacity belong in the expandable detail region. Adding a resource definition must not add another basic HUD column automatically.

## Compatibility

- Published ruleset v1/v2 payloads are unchanged.
- No ruleset v3 is introduced.
- Existing World, Nation, resource, facility, map, queue, and event rows are not rewritten.
- The change affects application projection, API visibility, documentation, and UI only.
