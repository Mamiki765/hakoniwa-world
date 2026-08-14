# ver 1.5.0-beta.3 sea-edge gameplay廃止

## Current contract

`hakoniwa-2s-plus-v5`では、海・海底基地・World外座標から導出していた海際度を
gameplayから廃止する。通常settlementは位置にかかわらず、通常成長100〜1,000人、
通常上限10,000人とする。誘致中は通常上限未満で100〜3,000人、通常上限到達後は
100〜300人、最終上限20,000人とする。

この変更は海際度に依存していた人口差だけをH2へ寄せる。次の現行contractは変更しない。

- 集落発生は所有者のいる人口0・施設なしのplain、20/100、隣接する農場または人口のある
  settlementを必要とし、初期人口100人である。
- village / town / cityの境界は1〜2,999 / 3,000〜9,999 / 10,000以上である。
- famineは100〜3,000人を減らし、riot、fire、forest、attraction commandの実行境界を維持する。
- Capitalはidentity、minimum 100人、ordinary growth cap 25,000人を維持する。
- refugee、missile、monster、disasterによる既存population damageとevent contractを変更しない。
- 農場・工場を含むfacility buildは、廃止前から現行2S＋では海際度に依存しておらず変更しない。

World edgeは海とみなされず、`(0,0)`、signed negative edge、追加chunk、中央、四隅の
同条件settlementへ同じpopulation contractを適用する。World expansionの生成、方向、
registration retry、災害scaleは変更しない。

## Runtime audit and removal

変更前の`calculate_terrain_context`は、deterministicなNation/cell順を作った後、surface
MapSpaceを取得し、全MapCellとterrain relationを追加取得していた。さらに全cellについて
radius 4の61座標を走査し、`sea`またはbounds外を数え、`TurnState::seaEdgeByCellId`へ
全cell分を保存していた。値のconsumerはsettlementの通常上限、通常/誘致growth rangeと
`population.increased`の`sea_edge` metadataだけだった。

v5 runtimeは追加のMapSpace/MapCell/terrain取得、radius走査、全cell分のsea-edge map、
event metadataを削除する。phase keyはhistorical TurnRun pipelineとの互換を保ち、現在も
development Nation orderとsurface cell orderを確定する責務があるため維持する。

ただし、公開済みv1〜v4のpayloadは不変であり、release前に既に失敗したTurnRunの
same-ruleset / same-seed手動retry契約も壊せない。そのため`sea_edge_bands`を持つ旧rulesetを
明示的に実行する場合だけ、旧radius計算・turn-local map・event metadataをcompatibility pathで
再現する。v5 migrationは未解決non-dry TurnRunをfail closedするため、通常のv5移行後turnは
このpathへ入らない。

cell数を`N`、radius 4の固定走査数を61とすると、削除対象は時間`O(61N)=O(N)`、memory
`O(N)`、3 queries（MapSpace 1、MapCell 1、eager-loaded terrain 1）である。
`process_cells`用の全surface cell ID取得とshuffleは必要なので残り、時間/memoryとも`O(N)`、
query 2本（Nation ID、surface cell ID）である。World拡張後もsea-edge由来の追加61N走査と
全cell mapはv5で再発生しない。旧ruleset retryだけは旧costを維持する。GET/API pathへ
代替計算は追加しない。

## Source comparison

H2 raw sourceには海際度のfield、turn計算、人口分岐がない。通常時の村・町は100〜1,000人、
通常上限10,000人、誘致時は上限前100〜3,000人、以後100〜300人、最終20,000人である。
H2＋は`seaLevel`をturn冒頭に計算し、12/24のbandを人口上限・人口成長・農場/工場建設へ
使用する。ただし集落発生用に計算した20/10/5は実判定に使われず、実効値は固定20%である。
現行2S＋へ残っていたのは人口bandとそのturn-time計算だけで、facility build、disaster、
missile、monster、API/frontendは海際度へ依存していなかった。

参照sourceはread-onlyで確認し、`_references/`は変更しない。

## Immutable ruleset and migration

v1〜v4 payloadは変更せず、v5を新規immutable snapshotとして公開する。forward-only migrationは
World mutation advisory lockとWorld row lockを取得し、次turnのnon-dry `pending`、`running`、
`failed`、`blocked` TurnRunがあればfail closedする。command/monster definitionのstable key集合を
照合し、World、queue definition、monster instance、monster kill aggregateのlive参照だけを
v5へ対応付ける。queue position、quantity、target、parameters、status、request key、履歴、
historical TurnRunのruleset snapshotとseedは変更しない。再実行は同じ結果になる。

既存人口の補正、backfill、reset、production data repairは行わない。v5適用後の次turnから、
現在人口を初期値として一様なgrowth contractを適用する。
