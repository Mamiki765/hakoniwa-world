# ADR-0007 怪獣actorとoccupancy境界

- Status: Accepted
- Date: 2026-08-05
- Scope: 地上mapの怪獣identity、position、occupancy、Capital invariant、将来のfootprint拡張

## Context

参考実装では怪獣がcell種別とencoded parameterへ格納され、terrain、position、HP、kindが同じ表現へ結合されている。新作でこの表現を採用すると、terrain-changing disaster、ownership、facility、怪獣damage、将来のmulti-cell monsterを同じcell stateへ押し込み、別々のlifecycleと監査を保てない。

一方、最初の怪獣実装にmulti-cell actorの完全な一般化を要求すると、未決のmovement、spawn、HP、reward、disaster interactionまで暗黙に決める危険がある。現在確定できるidentity/occupancy境界と、source auditまたはowner判断が必要なruleを分離する。

## Decision

怪獣をterrainまたはfacilityではなく独立したactorとして扱い、地上cellとは別のoccupancy layerへ配置する。

- 最初の怪獣PRは1 actorが1 cellを占有する。
- actor identity、position、monster definition、runtime stateはterrain/facility identityから分離する。
- 1-cell実装を永続的な1-cell schema invariantにはせず、将来のmulti-cell footprintを追加できる境界を維持する。
- Capital cellはmonster occupancyを許可しない。spawn、movement、forced relocationの候補から除外する。
- terrain、facility、ownershipはmonster occupancyの有無だけで別種へencodeしない。

この決定はschema、migration、ruleset payload、runtime behaviorを実装する承認ではない。

## Explicit non-decisions

次は本ADRで決めず、`docs/open-questions.md`のgateを正本とする。

- MONSTER-02: source-derived movement、acted flags、ghost、hardening parity、spawn、HP、reward。
- MONSTER-03: terrain-changing disasterとmonster occupancyの相互作用、damage/退去/消滅/event順序。
- missile type、accuracy、reward delivery、player/public log payloadの詳細。
- multi-cell footprintのshape、atomic movement、overlap、targeting、persistence schema。

## Provenance

観察した旧作挙動は`docs/reference-analysis/hakoniwa-2plus-turn-processing.md`と`docs/reference-analysis/hakoniwa-2plus-facilities.md`に残す。新作が旧作cell encodingを採用しない理由は`docs/decisions/ADR-0002-reference-integration-policy.md`に従う。

## Consequences

- terrain-changing effectとmonster effectを別々に監査・検証できる。
- terrain/facilityを維持したoccupancy表現をplayer-safe projectionへ渡せる。
- 最初のPRを1-cell actorへ限定しつつ、将来のfootprintをschemaの全面置換なしで追加できる。
- MONSTER-02とMONSTER-03が決まるまで、actor境界をmovementやdisaster semanticsで補完してはならない。
