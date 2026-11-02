# Shared-world monster system

本書はPR21以降のactor/occupancy・責務・実行境界を記録する。`product/docs/monster-audit-pr21.md`は導入時のcatalog・原作対応・asset provenanceの履歴案内であり、現在の設定値は[現行Rulesetの確認方法](../../product/docs/architecture/current-ruleset-baseline.md)から確認する。後続の怪獣解決順序は[ADR-0013](../decisions/ADR-0013-ver-2.1.3-resolution-and-legacy-queue.md)と[CompleteTurnEngine](../../product/app/Application/CompleteTurnEngine.php)を正本とする。

## Domain ownership

| component | responsibility |
|---|---|
| `monster_definitions` | immutable ruleset catalog、HP範囲、skill、移動上限、価値、asset key、公開説明 |
| `monster_instances` | World内identity、現在HP、出現時最大HP、alive/killed/removed lifecycle |
| `monster_occupancies` | actorと現在surface cellの1対1対応。Capital occupancy禁止 |
| `nation_monster_kill_stats` | World/Nation/definition単位の永久討伐count、初回turn、最終turn、version |
| `nation_monster_cycle_stats` | World/Nation/100 turn区間単位の討伐turn賞用count履歴、明示seed時刻 |
| `nation_monster_cycle_seed_requirements` | 途中区間へver 1.3.0を導入した既存Nationの明示seed完了監査 |
| `MonsterTurnService` | World単位batch load、memory cell index、randomized process-cell action、trample、防衛接触 |
| `MonsterSpawnService` | active Nationごとのsnapshot、独立draw、settlement置換、instance/occupancy作成 |
| `MonsterDamageService` | hardening、atomic HP damage、final blow、capacity-bounded reward、aggregate stat upsert、idempotency |
| `MonsterRemovalService` | terrain eventと防衛接触による報酬なしのexplicit removal |

actorはspawn元Nationを保持しない。hostは常に現在cellの`owner_nation_id`から死亡時またはprojection時に解決し、公開表示では`nation_number`を使う。将来multi-cell footprintはoccupancy layerを拡張できるが、PR21 API/schemaは1 actor = 1 cellだけを保証する。

## Turn order and determinism

`calculate_terrain_context`がsurface cell orderを専用shuffle streamで固定する。ver 1.5.0-beta.3で導入したv5では、このphaseはterrain/sea-edgeを計算せず、全cell IDのorderだけを保持する。immutableな旧rulesetのsame-seed retryだけは旧計算を条件付きで再現する。`process_cells`は全cellとrelationを一括取得し、怪獣occupancy/definitionもWorld単位でbatch loadする。World移行前にspawnした生存個体もbatchへ含める。後続の[5.4.0](../../product/docs/releases/5.4.0-queue-provenance-energy.md)ではspawn時definition・最大HPの来歴を保持し、移動・弓の対象判定・damage・除去等のgameplay値を[CurrentMonsterDefinitionResolver](../../product/app/Application/CurrentMonsterDefinitionResolver.php)で現在Worldの同keyから解決する。船の占有判定と軍艦もこの完全なbatchを使う。導入時のsame-passでは、各cellで怪獣actorを通常の人口・facility処理より先に実行し、actorが存在したcellの通常処理を終えていた。後続のADR-0013では通常地上処理と通常怪獣passを分離し、現行経路ではミサイル解決・秘書の弓の後に通常怪獣passを実行する。この旧same-pass説明を現行の順序へ戻さない。

movement、spawn trigger/candidate/type/HPは用途別labelled random streamを使い、raw seed/drawはpublic API/event projectionへ出さない。移動先が後続cellなら同じturnに再行動できる逐次因果を保ち、definition上限はturn-local `MonsterTurnBatch`で制限する。`moves_taken`はDBにもAPIにも置かない。

自然出現は`global_disasters`の既存terrain eventとland subsidence後に行うため、新規monsterは出現turnに移動しない。全phaseはTurnRunnerの単一transaction内で確定し、途中失敗はHP、位置、terrain、reward、stat、event、chunk versionをまとめてrollbackする。

## Integrity and concurrency

- 新規個体と個体のWorld・definition・出現時HPの変更では、WorldのrulesetとdefinitionのrulesetをDB triggerで一致させる。World移行後も既存個体の出現時定義・出現時HPは保持する。
- HPをdefinition範囲とinstance state checkで制約する。
- occupancyはalive、同一World、surface、非CapitalをDB triggerで検証する。
- cellとmonsterはlock順を固定し、occupancyのcell/monster一意制約で二重配置を拒否する。
- kill statはWorld/Nation/definitionのunique scopeで、初回count/version 1、以後のatomic incrementだけを許す。Nation/definitionのWorld整合とcross-World拒否をDBで保証する。
- 個別撃破tableは持たず、instance lock下のalive→killed成立時だけ永久statと現在の100 turn周期statを同じtransactionでupsertする。移行前の定義を持つ個体の新しい討伐記録は、Worldの現行Rulesetの同じ怪獣keyへ結ぶ。既存stat・死亡個体・使用済みsnapshotは移し替えない。retryとtransaction rollbackは二重加算や部分更新を残さない。
- 途中区間の既存Nationはmigrationでseed要求を固定し、全要求の明示完了までnon-dry TurnRun作成と周期順位の0件defaultを拒否する。要求行は完了を一度だけ許し、World存続中の削除を拒否する。
- published `roadmap-pr21-v1`を追加するだけでhistorical ruleset/Worldをrepointしない。

## Projection

`MapChunkService`はcellとmonster graphをeager loadし、`MapCellPresenter`がviewer-safe overlayを作る。overlayはcurrent HP、hardened、effective asset、現在host Nation number/nameを返す。terrain/facility projectionは従来どおり残り、Vueは独立layerとして描く。asset不足時はnull URLとfallback labelを返し、broken imageを作らない。

公開Nation detailは対象Nationの正数`nation_monster_kill_stats`を一括取得し、総final blow count、definition key/name、種類別count、first/last turnを返す。種類数に上限は設けず、明示`display_order`、historical nullでは監査済み`source_metadata.kind * 100`の順で投影する。ver 1.3.0の公開TOPも全Nationの正数statを一括取得し、同じeffective display orderで種類別countを並べ、その最大値のspeciesをkill markに選ぶ。v1〜v10のruleset authoringは従来の正確な8種を維持するが、公開projectionは将来の加算的catalogを切り捨てない。周期count、seed、draw、raw source metadataは公開しない。賞判定と表示は`docs/decisions/ADR-0009-ver-1.3.0-awards-and-classic-top.md`を正本とする。個別撃破はstructured eventへ残し、player island eventはraw auditを返さずkiller/host roleに応じたmessageへ変換する。

## Observability

`process_cells`はloaded/actions/moves/trampled/defense/max movesとcombat integration用zero-safe fieldsを、`global_disasters`はeligible Nation/draw/spawn/no-candidate/terrain removalを`phase_results`へ残す。TurnRunnerは各phaseへ`duration_ms`を付ける。32×32/60×60 integration testはquery countとduration budgetを継続監視する。
