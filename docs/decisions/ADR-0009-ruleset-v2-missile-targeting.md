# ADR-0009: ruleset v2の明示ミサイルtarget

## Status

Accepted for application 1.1.0.

## Decision

`hakoniwa-2s-plus-v1`はbyte-for-byte不変のproduction監査記録として保持する。application 1.1.0は新しいimmutable snapshot `hakoniwa-2s-plus-v2`を公開し、v1との差分を次の1点だけに限定する。

- `military.dormant_impact.explicit_target_state`: `active`から`any_existing_coordinate`

v2ではsurface mapに存在する任意の`x`/`y`を明示aim pointにできる。neutral、unowned、sea、自国所有、activeな他国所有、dormant、sunkenの所有cellをtarget選択できる。map外座標、基地、資金、quantity、弾種固有制約は従来どおり検証する。

target選択とimpact効果は別契約とする。`dormant_frozen`、`dormant_contestable`、`sunken_archived`所有cellへ着弾した場合は、v1と同じくcell、facility、population、owner、monster occupancyを一切変更しない。報復、制裁、占領、領土侵食、怪獣だけを攻撃する例外は1.1.0へ含めない。

## Production migration

production Worldはresetせず、forward-only migrationでv1からv2へ移行する。migrationはWorld turn advisory lockを取得し、次Turnのnon-dry TurnRunが存在すればfail closedとする。Worldの`ruleset_version_id`をv2へ変更する前に、全queue itemのcommand definitionを同じcommand keyのv2 definitionへ対応付ける。

queue itemのposition、quantity、parameters、request key、status、target座標、queue versionその他の状態は変更しない。既にv2へ正常移行済みなら再実行は同じ状態を確認して終了する。予期しないruleset、catalog差分、queue/ruleset不整合では暗黙修復せず停止する。destructive rollbackは提供しない。

## Consequences

application version `1.1.0`とruleset version `hakoniwa-2s-plus-v2`は別管理である。将来dormant territoryへ実効果を与えるcombatを実装する場合は、B-12の残るowner decisionと新しいversioned rulesetが必要になる。
