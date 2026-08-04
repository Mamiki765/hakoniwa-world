# ADR-0007 怪獣actorとoccupancy境界

- Status: Accepted
- Date: 2026-08-05
- Scope: 地上mapの怪獣identity、position、occupancy、runtime rule、terrain event、Capital invariant、将来のfootprint拡張

## Context

参考実装では怪獣がcell種別とencoded parameterへ格納され、terrain、position、HP、kindが同じ表現へ結合されている。新作でこの表現を採用すると、terrain-changing disaster、ownership、facility、怪獣damage、将来のmulti-cell monsterを同じcell stateへ押し込み、別々のlifecycleと監査を保てない。

一方、最初の怪獣実装にmulti-cell actorの完全な一般化を要求すると、movement、spawn、HP、reward、disaster interactionまで不要に一般化する危険がある。PR21ではsource auditとowner承認を分離して記録し、1-cell runtimeだけを決定する。

## Decision

怪獣をterrainまたはfacilityではなく独立したactorとして扱い、地上cellとは別のoccupancy layerへ配置する。

- 最初の怪獣PRは1 actorが1 cellを占有する。
- actor identity、position、monster definition、runtime stateはterrain/facility identityから分離する。
- 1-cell実装を永続的な1-cell schema invariantにはせず、将来のmulti-cell footprintを追加できる境界を維持する。
- Capital cellはmonster occupancyを許可しない。spawn、movement、forced relocationの候補から除外する。
- terrain、facility、ownershipはmonster occupancyの有無だけで別種へencodeしない。
- kind 0〜7を不変ruleset catalogへ公開し、instanceは現在HPと出現時最大HPを持つ。位置は`monster_occupancies`、撃破factはimmutableな`monster_kill_records`へ分離する。
- movementはrandomized cell orderの一回passで処理する。移動先cellがまだ未処理なら同turn内に再行動できるが、上限はdefinition別のturn-local counterで制限し永続化しない。
- 硬化はtarget turn parityで判定し、該当turnの通常damageとmovementを止める。`monster4.gif`はkindではなく硬化表示variantである。
- terrain-changing eventは`product/docs/monster-audit-pr21.md`の表を正本とし、維持対象では怪獣cellをskip、上書き対象ではoccupancyを報酬なしで先に除去する。
- 防衛施設接触は怪獣のexplicit removalと、一回の巨大隕石相当blastとして扱う。killer、経験、報酬、kill recordを作らない。
- Nation attributed final blowだけがkiller賞金、現在cell ownerへの怪獣肉、基地経験、immutable kill recordを作る。

これらはowner承認済みPR21 scopeで実装する。公開済みhistorical rulesetとWorldは変更しない。

## Explicit non-decisions

次は本ADRで決めず、`docs/open-questions.md`のgateを正本とする。

- AWARD-01: 怪獣賞を含むNation award、threshold、repeatability、revocation、backfill。
- monster damageを呼ぶmissile type、accuracy、着弾、副被害、dormant例外の詳細。
- multi-cell footprintのshape、atomic movement、overlap、targeting、persistence schema。

## Provenance

観察した旧作挙動は`docs/reference-analysis/hakoniwa-2plus-turn-processing.md`と`docs/reference-analysis/hakoniwa-2plus-facilities.md`に残す。source-derived factとowner decisionの対応は`product/docs/monster-audit-pr21.md`を正本とする。新作が旧作cell encodingを採用しない理由は`docs/decisions/ADR-0002-reference-integration-policy.md`に従う。

## Consequences

- terrain-changing effectとmonster effectを別々に監査・検証できる。
- terrain/facilityを維持したoccupancy表現をplayer-safe projectionへ渡せる。
- 最初のPRを1-cell actorへ限定しつつ、将来のfootprintをschemaの全面置換なしで追加できる。
- MONSTER-02〜04はPR21で決定済み。awardとmissile詳細は既存gateを越えて実装しない。
