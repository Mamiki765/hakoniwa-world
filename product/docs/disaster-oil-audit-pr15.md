# 怪獣を除く災害・油田稼働 事前監査

この表はコード変更前に、Hakoniwa Islands 2+ の `_references/hakoniwa-2plus/extracted`、
`docs/reference-analysis/hakoniwa-2plus-turn-processing.md`、ADR-0003、現行実装、owner decisionを突合した結果である。
`_references/` はread-onlyで参照し、抽出した挙動を新作のversioned rulesetと独立実装へ写像する。

## 抽選・効果監査表

| Event | Legacy判定箇所 | 抽選単位 | Legacy率 | 新ruleset率 | 中心 | 範囲・対象 | 内部確率 | 通常cell結果 | Capital結果 | Audit / player log | Stream label | Retry / idempotency | 既存実装 |
|---|---|---|---:|---:|---|---|---|---|---|---|---|---|---|
| 地震 | `map.c:742-747,870-903` | Worldごと | 80/1000 | 80/2000 | x/yを各`[-5,64]`から一様抽選 | radius 10。人口10,000以上の都市、工場、ハリボテ | 対象ごと1/4 | 荒地化、施設・人口消失、owner維持 | 都市条件を満たす場合10%人口減。identity/owner/terrain維持、各event後最低100 | `disaster.triggered`、`disaster.cell_damaged`、`capital.disaster_damaged`。発生はWorld公開、被害は該当Nationのみ | `global_disasters:earthquake:{trigger,center,effect}:v1` | World transaction rollback。同target turn retryは同seed・同draw。完了turn再実行不可 | `global_disasters` stubのみ |
| 津波 | `map.c:749-753,905-945`; 範囲外海 `map.c:107-120` | Worldごと | 300/1000 | 300/2000 | x/yを各`[-5,64]`から一様抽選 | radius 10。海・荒地・森・山・怪獣・海底基地・人口0平地・記念碑以外。Capitalは人口>0の通常集落相当 | 隣接海・海底基地数cごと`max(0,c-1)/12`。World外隣接は海として数える | 荒地化、施設・人口消失、owner維持 | 対象時10%人口減。identity/owner/terrain維持、最低100 | 同上 | `global_disasters:tsunami:{trigger,center,effect}:v1` | 同上 | stubのみ |
| 台風 | `map.c:755-759,947-982` | Worldごと | 400/1000 | 400/2000 | x/yを各`[-5,64]`から一様抽選 | radius 10。農場・ハリボテ。Capital/通常都市は対象外 | 隣接森・記念碑数cごと`max(0,6-c)/12` | 所有平地化、施設消失、人口0、owner維持 | 対象外 | 同上 | `global_disasters:typhoon:{trigger,center,effect}:v1` | 同上 | stubのみ |
| 流星群 | `map.c:761-766,984-1028` | Worldごと。発生後は着弾attemptごと | 200/1000 | 200/2000 | x/yを各`[-5,64]`から一様抽選 | center radius 10の331座標から各attempt一様抽選。World外は変更なし | 最低1 attempt。各attempt後1/2で継続（半減しない） | 深海は不変。浅瀬は深海化。油田/海底基地/陸地は中立深海化し施設・人口消失 | 着弾ごと90%人口減。identity/owner/terrain維持、最低100 | 同上。実際のWorld外attemptはplayer logへ出さない | `global_disasters:meteor_shower:{trigger,center,effect}:v1` | 同上 | stubのみ |
| 巨大隕石 | `map.c:768-775,1109-1165` | Worldごと | 100/1000 | 100/2000 | x/yを各`[-2,61]`から一様抽選 | center、ring 1、ring 2。World外はskip | 追加確率なし | centerは陸地を中立深海、ring 1は陸地を中立浅瀬、海系は中立深海。ring 2は通常施設/集落等を荒地化、海・海底基地・荒地・山は不変 | center 90%、ring 1 30%、ring 2で通常なら荒地化するcellは10%。各着弾を逐次適用し最低100 | 同上 | `global_disasters:huge_meteor:{trigger,center,effect}:v1` | 同上 | stubのみ |
| 噴火 | `map.c:777-782,1030-1073` | Worldごと | 200/1000 | 200/2000 | x/yを各`[0,59]`から一様抽選 | centerとADR-0003方向0..5の隣接6cell。World外はskip | 追加確率なし | centerは山。隣接深海/油田/海底基地は浅瀬、浅瀬は荒地、他の非山陸地は荒地。owner維持 | center 30%。隣接は通常結果severity（荒地10%、浅瀬30%）。identity/owner/terrain維持、最低100 | 同上 | `global_disasters:eruption:{trigger,center,effect}:v1` | 同上 | stubのみ |
| 地ならし即時地震 | `command.c:205-236`; `map.c:870-903` | successful `land_level` commandごと | 5/1000 | 5/2000 | 成功command target | global地震と同じradius 10・対象条件 | 対象ごと1/4 | global地震と同じ | global地震と同じ | `command.land_level_earthquake`、被害event。失敗commandではevent/drawなし | `development_commands:land_level:earthquake:{trigger,effect}:v1` | commandを含むWorld transaction rollback。同target turn retryは同seed | command本体のみ、side effectなし |
| 火災 | `map.c:295-311,398-408,643-656,840-856` | 対象cellごと | 10/1000 | 10/2000 | なし | 人口10,000以上の都市、工場、ハリボテ。隣接森または記念碑があれば完全防止 | trigger以外なし | 荒地化、施設・人口消失、owner維持 | 都市条件を満たす場合10%人口減。identity/owner/terrain維持、最低100 | `fire.prevented`、`fire.damaged`。該当Nationだけに投影しraw draw非公開 | `process_cells:fire:v1` | randomized cell順で逐次。World transaction rollback・同seed replay | 未実装 |
| 飢餓暴動 | `map.c:389-419,643-656,858-868` | 飢餓Nationの対象cellごと | 1/4 | 1/4 | なし | 農場、工場、ミサイル基地、将来互換の海底基地・真防衛施設・ハリボテ | 対象ごと1/4（半減しない） | 荒地化、施設・人口消失、owner維持 | Capitalは対象外 | 既存`facility.riot`とplayer logを維持。対象追加とfireとのlegacy順だけ補完 | `process_cells:facility_riot` | randomized cell順で逐次。World transaction rollback・同seed replay | 農場・工場・ミサイル基地、audit/player log、1/4実装済み |
| 海底油田収入 | `map.c:264-290` | 所有油田cellごと | 毎turn 1,000億円 | 毎turn 1,000億円 | なし | `seabed_oil_field` | なし | money capacity経由で収入。cellは維持 | 対象外 | `oil.income`。requested/applied/overflowを該当Nationへ投影 | 乱数なし | 同cellの枯渇判定より先。World transaction rollback・完了turn再実行不可 | PR #14で生成のみ実装済み |
| 海底油田枯渇 | `map.c:278-288` | 収入処理後の油田cellごと | 40/1000 | 40/1000 | なし | `seabed_oil_field` | triggerのみ（半減しない） | 施設削除、owner null、中立深海 | 対象外 | `oil.depleted`を該当旧ownerへ投影 | `process_cells:oil_depletion:v1` | 収入後に判定。World transaction rollback・同seed replay | 未実装 |
| 飢餓人口減 | `map.c:313-320`、POP-01/B-16 | 人口cellごと | 100..3,000人 | 100..3,000人 | なし | 有人口集落とCapital | inclusive draw（半減しない） | 0まで減少し、0なら通常集落facilityを外して所有平地 | 同じdraw、各event後最低100、Capital identity維持 | 既存`famine.applied`/`population.decreased` | `process_cells:famine_population_loss` | randomized cell順で逐次。World transaction rollback・同seed replay | 実装済み。Capital最低1/0挙動を100へ更新必要 |

## 現行catalogとの境界

`decoy`（ハリボテ）、`monument`、`defense`、`seabed_base`はlegacyの対象集合として一意だが、
PR #14時点のactive facility catalogにbuild pathがない。このPRではbuild command、visibility、asset、UIを決めない。
災害rulesetのsemantic target keyとして保持し、現存する`farm`、`factory`、`missile_base`、
`seabed_oil_field`、settlement/Capitalへ即時適用する。将来、対応facility definitionが別PRで公開された時も
災害handlerを書き換えず同じkey集合へ参加できる境界とする。

## 確定した非スコープ

怪獣、ミサイル、地形破壊弾、制裁、国境侵食、cron、通知、施設build command、
新asset、広範なUI変更、World reset、OCI、production DB操作は行わない。
