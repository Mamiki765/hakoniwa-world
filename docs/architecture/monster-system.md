# Shared-world monster system

PR21は怪獣をterrain/facilityから独立した1-cell actorとして実装する。exact catalog、source対応、spawn式、terrain interaction、reward例、asset provenanceは`product/docs/monster-audit-pr21.md`を正本とし、この文書は責務と実行境界を示す。

## Domain ownership

| component | responsibility |
|---|---|
| `monster_definitions` | immutable ruleset catalog、HP範囲、skill、移動上限、価値、asset key、公開説明 |
| `monster_instances` | World内identity、現在HP、出現時最大HP、alive/killed/removed lifecycle |
| `monster_occupancies` | actorと現在surface cellの1対1対応。Capital occupancy禁止 |
| `monster_kill_records` | Nation attributed final blow、死亡時host、reward/overflow、基地経験のimmutable fact |
| `MonsterTurnService` | World単位batch load、memory cell index、randomized process-cell action、trample、防衛接触 |
| `MonsterSpawnService` | active Nationごとのsnapshot、独立draw、settlement置換、instance/occupancy作成 |
| `MonsterDamageService` | hardening、atomic HP damage、final blow、capacity-bounded reward、record、idempotency |
| `MonsterRemovalService` | terrain eventと防衛接触による報酬なしのexplicit removal |

actorはspawn元Nationを保持しない。hostは常に現在cellの`owner_nation_id`から死亡時またはprojection時に解決し、公開表示では`nation_number`を使う。将来multi-cell footprintはoccupancy layerを拡張できるが、PR21 API/schemaは1 actor = 1 cellだけを保証する。

## Turn order and determinism

`calculate_terrain_context`がsurface cell orderを専用shuffle streamで固定する。`process_cells`は全cellとrelationを一括取得し、怪獣occupancy/definitionもWorld単位でbatch loadする。各cellでは怪獣actorを通常の人口・facility処理より先に実行し、actorが存在したcellの通常処理を終える。

movement、spawn trigger/candidate/type/HPは用途別labelled random streamを使い、raw seed/drawはpublic API/event projectionへ出さない。移動先が後続cellなら同じturnに再行動できる逐次因果を保ち、definition上限はturn-local `MonsterTurnBatch`で制限する。`moves_taken`はDBにもAPIにも置かない。

自然出現は`global_disasters`の既存terrain eventとland subsidence後に行うため、新規monsterは出現turnに移動しない。全phaseはTurnRunnerの単一transaction内で確定し、途中失敗はHP、位置、terrain、reward、record、event、chunk versionをまとめてrollbackする。

## Integrity and concurrency

- WorldのrulesetとdefinitionのrulesetをDB triggerで一致させる。
- HPをdefinition範囲とinstance state checkで制約する。
- occupancyはalive、同一World、surface、非CapitalをDB triggerで検証する。
- cellとmonsterはlock順を固定し、occupancyのcell/monster一意制約で二重配置を拒否する。
- kill recordはkilled instance、同一Worldのkiller/host/baseだけを許し、instanceごと一件、update/delete不可とする。
- published `roadmap-pr21-v1`を追加するだけでhistorical ruleset/Worldをrepointしない。

## Projection

`MapChunkService`はcellとmonster graphをeager loadし、`MapCellPresenter`がviewer-safe overlayを作る。overlayはcurrent HP、hardened、effective asset、現在host Nation number/nameを返す。terrain/facility projectionは従来どおり残り、Vueは独立layerとして描く。asset不足時はnull URLとfallback labelを返し、broken imageを作らない。

公開Nation detailはimmutable kill recordから総final blow countとdefinition別first kill turnだけを集計する。種類別count、award、seed、draw、source metadataは公開しない。player island eventはraw auditを返さず、killer/host roleに応じたmessageへ変換する。

## Observability

`process_cells`はloaded/actions/moves/trampled/defense/max movesとcombat integration用zero-safe fieldsを、`global_disasters`はeligible Nation/draw/spawn/no-candidate/terrain removalを`phase_results`へ残す。TurnRunnerは各phaseへ`duration_ms`を付ける。32×32/60×60 integration testはquery countとduration budgetを継続監視する。
