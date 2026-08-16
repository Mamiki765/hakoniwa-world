# ADR-0012: ver 2.1.0 missile defense and Secretary rename

- Status: Accepted
- Date: 2026-08-16
- Scope: hakoniwa-world ver 2.1.0

## Context

ADR-0011 records the historical ver 2.0.0 Secretary v1 contract: a Secretary could be named once and could not be renamed. Post-release requests approve a ver 2.1.0 override. Separately, a raw-source re-audit found that the documented radius-two defense behavior was never represented by an immutable production payload or the runtime.

The source and history evidence is recorded in [the ver 2.1.0 audit](../../product/docs/ver-2.1.0-defense-missile-log-audit.md).

## Secretary rename

ADR-0011 remains true for ver 2.0.0. Beginning with ver 2.1.0, an already named Secretary may be renamed any number of times through `PATCH /api/v1/me/secretary/name`. Initial naming remains the separate `POST` route. A rename never creates a Secretary row.

Secretary remains User-owned with `UNIQUE(user_id)`, not Nation-owned. Names remain duplicate-permitted, single-line plain text of 1–30 characters. Rename changes only `secretaries.name`; it preserves `named_at`, skill levels, XP, effects, and the same row across Nation abandonment and re-registration.

Each successful value change writes a private `secretary.renamed` audit row containing Secretary ID, User ID, old name, new name, and occurrence time. A no-op rename writes neither the row nor an event. Profile UI composes this User-owned operation beside the Nation profile form without merging their backend ownership models.

Missile events keep the Secretary name/label captured by the running turn attempt. Rename never rewrites historical events. An attempt already in progress continues to use its batch-loaded snapshot; a later attempt loads the latest name.

## Missile defense and logs

`hakoniwa-2s-plus-v8` inherits v7 and adds only `military.defense_interception`: a real defense facility in radius one or two intercepts a missile. When the impact cell itself is a real defense facility, the surrounding-defense check is skipped altogether; another nearby defense does not intercept that direct hit. The contract applies to normal, PP, land-destruction, and SPP missiles, without firing-Nation or monster-occupancy exceptions. A decoy has no defense effect and overlapping facilities still resolve one interception for one missile.

Resolution order is owned-cell final-defense XP, dormant-owner protection, surrounding defense, the existing direct SPP defense resistance, monster impact, then Secretary final defense. Surrounding defense therefore consumes no Secretary budget. The v6 direct-SPP owner decision remains distinct: a direct hit on the defense cell is not a surrounding interception, but SPP is ineffective against that facility.

Per-impact defense and Secretary audit events remain stored. Owner projection aggregates each class to at most one line per Nation and turn. Defense and Secretary interceptions are excluded from the ineffective-impact count. Public launch/meaningful-impact visibility and private attacker launch details do not expand.

## Ruleset and migration

Published v1–v7 payloads and checksums remain unchanged. Because radius defense was never encoded in those payloads and was absent from runtime from its introduction, applying it to v7 would silently reinterpret a published gameplay contract. v8 is therefore required even though the player-facing application version is 2.1.0.

The v7-to-v8 migration is forward-only, uses the existing World/TurnRun guard, and fails closed if queued missile commands would change meaning. Rebinding those reviewed items requires the migration-scoped explicit confirmation value. No reset path is introduced.
