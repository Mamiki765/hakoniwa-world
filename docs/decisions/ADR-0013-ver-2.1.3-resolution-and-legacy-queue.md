# ADR-0013: ver 2.1.3 monster resolutionとlegacy queue residue

## Status

Accepted for player-facing ver 2.1.3. Gameplay ordering is published as immutable `hakoniwa-2s-plus-v9`; legacy queue repair is runtime integrity handling and is not a ruleset setting.

## Evidence priority and source comparison

挙動の評価順はraw source、published runtime/ruleset、analysis documentとする。既存analysisだけから新仕様を導かない。raw sourceはread-onlyで監査し、第三者codeを移植していない。

| Behavior | Hakoniwa 2 | Hakoniwa 2+ | old 2S+ (v1-v8) |
|---|---|---|---|
| cell iteration | 島ごとのrandomized point passを1回実行する (`hako-turn.txt:349-356,373-375,1411-1438`) | `makeOrderXY()`後に`Map::process()`を1回ずつ実行する (`turn.c:17-18,57-60`; `map.c:264`) | `TurnOrderService`が作ったdeterministic shuffled `surfaceCellIds`を1回走査する (`CompleteTurnEngine.php:283,302`) |
| missile order | missile command内で着弾まで解決し、cell passより前 (`hako-turn.txt:367-375,892-1264`) | commandはintentを作り、missile base cellが同じshuffled pass内で発射 (`turn.c:49-60`; `map.c:411-641`) | intentはdevelopment phase、base発射は同じordinary cell pass (`CompleteTurnEngine.php:313-325`) |
| early monster | 専用先行passなし。特殊値は同じcell branch内の移動回数・硬化を制御 (`hako-turn.txt:1513-1589`) | 専用先行passなし (`map.c:666-716`; `monster.c:55-86`) | 専用先行passなし |
| normal monster | population/plain/forest/oil等と同じrandomized point pass。monster cell branchで行動 (`hako-turn.txt:1441-1513,1513-1601`) | oil/town/forest/riot/base等と同じshuffled `Map::process` pass (`map.c:264-716`) | ordinary eventと同じsurface-cell pass。monster occupancyを見つけると先に処理してそのcellの残りをskip (`CompleteTurnEngine.php:302-310`) |
| oil | 同じpassのoil cell branch (`hako-turn.txt:1494-1511`) | 同じpass (`map.c:275-290`) | 同じpass (`CompleteTurnEngine.php:330-336`) |
| fire | 同じpassで各pointのland branch後 (`hako-turn.txt:1603-1621`) | town/factory/decoy/base等の各branch内 (`map.c:295-311,398-408,643-656`) | 同じpass (`CompleteTurnEngine.php:339-377`) |
| population / famine | town branchで増減 (`hako-turn.txt:1421-1469`) | town branchで増減 (`map.c:295-379`) | settlement branchでfamineまたはgrowth (`CompleteTurnEngine.php:338-354`) |
| riot | 独立riot branchを確認できない | 同じpassの対象facility branch (`map.c:389-417`) | 同じpass (`CompleteTurnEngine.php:362-383`) |
| forest growth | 同じpass (`hako-turn.txt:1479-1484`) | 同じpass (`map.c:382-387`) | 同じpass (`CompleteTurnEngine.php:385-391`) |
| settlement appearance | 同じpass (`hako-turn.txt:1470-1478`) | town/plain処理と同じpass (`map.c:295-379`) | 同じpass (`CompleteTurnEngine.php:392-394`) |

したがってold 2S+は「全怪獣を先に処理」していない。monster cell、missile-base cell、oil cell、settlement cell等が同じshuffled surface orderでinterleaveしていた。

## Decision A: ver 2.1.3 resolution order

v9以降のcanonical conceptual orderは次である。

```text
future early monsters (not implemented)
  -> ordinary shuffled surface-cell events, including missile bases
  -> future Secretary pre-monster bow (not implemented)
  -> normal monsters
```

短いgameplay表現は`(先行怪獣) -> ミサイル -> (秘書の弓) -> 通常怪獣`とする。ただしruntimeの正確な中央stageは「ミサイルだけ」ではなく、missile base、oil、fire、famine、population、riot、forest growth、settlement appearance等を含むordinary shuffled surface-cell event passである。

実装はordinary passからnormal monster callだけを外す。同じ`surfaceCellIds`をordinary passとnormal monster passで再利用し、新しいshuffleもrandom drawも追加しない (`CompleteTurnEngine.php:302-397,405-410`)。ordinary event同士、missile base同士、oil同士、settlement同士の相対cell orderと既存random stream/draw populationは変わらない。normal monsterもcell-based passのままなので、移動先が後続cellならGhost等がmovement limitまで再行動できる。monster instanceのforeachへ変えない。

`MonsterTurnBatch`はordinary pass前に1回だけloadし、missile kill、land destruction、terrain removalが同じattempt-scoped batchからoccupancyをforgetする。normal passは生存occupancyだけを観測する。`monster_dispatch_command`で同target turnにspawnしたメカいのらをbatchから除外するowner decisionも維持する。

future early-monster、Secretary bow、equipment/item、empty service、priority、scheduler、actor registry、rulesetの空configは追加しない。将来featureは上記の明示位置へ個別ruleset契約とともに挿入する。

この変更はpublished v8が固定したsame-pass semanticsを変えるためruntime correctionとしてv8へsilent backportしない。v9はv8に`turn_resolution.normal_monster_stage = after_ordinary_surface_cell_events`だけを加える。v1-v8 runtimeはsettingを持たないためhistorical interleaveを維持する。

v8からv9へ移行する時点でqueuedの通常、PP、SPP、陸地破壊missileも、stable command keyでv9 definitionへrebindし、この新orderingで実行する。これらを旧v8 semanticsのまま保護するためのmigration STOP、operator review、one-shot confirmation環境変数は設けない。これはv9 orderingに対するowner decisionであり、意味が変わるqueued commandを一般に無確認で移行してよいというmigration safety policyではない。未解決next non-dry TurnRun guard、World lockとtransaction、exact stable-key mapping、live queue/monster/kill-stat rebind、historical queue item preservation、v1-v8 immutable payload、rollbackとidempotencyは維持する。

## Decision B: legacy command queue residue

historical compactorは一時配置に`queue_position + 1000`を使った。旧`LegacyCommandQueueOrder`はcommitted `>1000` rowをstaged prefixと推測して先頭へ復元していたが、repeated compaction等の履歴がなければ元順は一意に決まらない。

現行のadd/reposition/reorder/cancel/cancel-from/bulk/compact/executionは、transactionとqueue/item lock内で対象positionをいったん`null`にし、queue limit内の正規positionを書き直す。正常commitとして`1001`以上を生成する経路はない (`CommandQueueService.php:110-218,265-307,363-399,418-450,519-647,696-707,1390-1405`)。

このため、`status=queued AND queue_position>1000`だけをconfirmed legacy corruption residueとして扱う。元順を推測せず、物理DELETEせず、次へ原子的に更新する。

```text
status = cancelled
queue_position = null
cancelled_at = now()
failure_metadata.reason = legacy_staged_position_discarded
failure_metadata.original_queue_position = previous position
```

player queue mutation前は既存transaction/lock境界内でrepairし、turn executionはcommandを選ぶ前にrepairする。残る正常queued itemsだけをcompactし、queue versionとadmin auditを更新する。plain GET projectionはDBを変更せずcorrupt rowを除外し、推測した順を表示しない。2回目は対象がなくidempotentである。

`null`、0以下、queue limit超過だが`1000`以下、31–1000はschema constraintだけからlegacy stagingと断定できないため今回のautomatic discardへ広げない。duplicate non-null positionは既存unique constraintが拒否する。completed/failed/cancelled historical rowはposition値にかかわらず変更しない。

## Consequences

- missileでHP 0になったmonster、land destruction等でremoveされたmonsterはnormal passで行動しない。survivor、通常防衛またはSecretary interceptionで無傷の別monsterはordinary pass後に行動する。
- normal monsterはmissileだけでなくoil、fire、famine、population、riot、forest、settlement appearanceより後になる。例えば町のpopulation growth後にmonsterが進入して踏み潰すことがある。これはv9の意図した2S+仕様である。
- surface cellsの2回目iterationは許容する。batch loadとmutable cell indexを再利用し、per-cell DB queryを追加しない。
- ambiguous legacy commandだけをcancelするためWorld全体は継続でき、正常queueの実行順はcompact後も維持される。
