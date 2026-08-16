# ver 2.1.0 defense and missile-log audit

## Evidence order and boundary

This audit evaluates `raw source > published runtime/ruleset > analysis documents`. `_references` was decoded read-only in its original encoding. No production, OCI, or production database was accessed. Published v1–v7 files were not edited.

## Raw-source findings

### 箱庭諸島2

`_references/hakoniwa-2/hako-turn.txt` defines normal, PP, ST, and land-destruction missiles at lines 892–895. At lines 990–1039, an impact on a defense facility follows a special center branch and is not intercepted by that same facility. Other impacts use the 19-cell center-plus-two-rings neighborhood; since the center was already excluded, a real defense in radius one or two intercepts the missile. The check precedes terrain, facility, land-destruction, and monster effects. It does not inspect the firing island.

Consequences:

- radius 1 and 2 are intercepted and radius 3 is not; when the impact cell is a real defense facility, the surrounding check is skipped even if another defense is nearby;
- normal, PP, ST, and land-destruction missiles all pass through the same defense check;
- self-fired missiles and missiles arriving at monster cells are intercepted;
- one or more covering facilities produce one interception for the current missile and are not consumed.

Therefore a PP missile fired at a monster on the firing island is still stopped when a defense facility covers that impact cell.

### 箱庭諸島2＋

`_references/hakoniwa-2plus/extracted/map.c` lines 425–428 enumerate normal, PP, land-destruction, and ballistic missiles. Lines 479–489 call `countAround(..., 1, 19, DBaseTrue)` only when the impact cell is not `DBaseTrue`. `map.c` lines 105–123 show that indices 1–18 are the two rings with center index 0 excluded. The check has no firing-owner filter and precedes land-destruction handling (from line 491) and monster handling (from line 544). `DBaseFalse` is excluded, so the decoy has no interception capability.

The current SPP maps to the exact-impact ballistic/IS lineage. Raw 2＋ does not protect a direct defense-cell hit from it; current 2S＋ intentionally differs through the v6 SPP direct-resistance owner decision.

## Current main and history

The audited `origin/main` baseline is `80c00173a364638729932b5e0ec000887340024e`.

- `MissileImpactResolver` awarded final-defense XP and then handled dormant protection, terrain/monster effects, v6 direct SPP resistance, and v7 Secretary interception. It had no surrounding-defense query.
- v6 adds only `military.defense_spp_resistance`; v7 adds Secretary. No v1–v7 payload defines radius, covered missile keys, firing-owner scope, monster scope, decoy selection, or overlap resolution.
- Resolver history back to its introduction (`c797475`) contains no radius-defense implementation. `git log -S countAround` identifies analysis-document history, not a removed runtime implementation.
- The comparison table added in `b7de7c2` correctly recorded the raw radius-two behavior but incorrectly implied that current tests/rules implemented it.

This is not a runtime regression that can be restored under an existing immutable contract. It requires the narrowly defined v8 payload and forward-only migration described by ADR-0012.

## Final contract and projection

The runtime evaluates surrounding defense after dormant protection and before direct SPP resistance, monsters, land destruction, ordinary impact, or Secretary. A real-defense target bypasses this surrounding check. Overlap is boolean for gameplay but the per-impact audit retains the number and stable IDs of covering real-defense cells. Player projection never exposes those IDs or defense coordinates.

The target owner receives at most these two interception summaries per turn:

```text
防衛施設が3発のミサイルを迎撃しました。
秘書のペリドットが1発のミサイルを迎撃しました。
```

An unnamed snapshot renders `秘書が1発のミサイルを迎撃しました。`. Remaining no-effect impacts stay aggregated per launch as `PPミサイルのうち3発は効果がありませんでした。`. Defense and Secretary effects are excluded from that count. Public missile summary, attacker-private launch detail, and target-owner visibility stay at their prior boundaries.
