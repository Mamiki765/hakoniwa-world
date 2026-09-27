# PR18 Nation単位地盤沈下 監査表

この文書は、read-onlyの`_references/yamanity/repository`、既存のreference analysis、
ADR-0003、現行TurnRunner・災害・Capital・ownership実装とPR18 owner decisionを突合した実装契約である。
箱庭諸島2＋sourceには地盤沈下を確認できなかったため、旧資料由来部分と新作固有仕様を分離して記録する。

## 由来と採用契約

| 観点 | 旧資料で確認した挙動 | PR18 owner decision / 新作の正本 |
|---|---|---|
| 抽選単位 | やまにてぃは島ごと、面積10,000超で3% | active Nationごと・turnごとに独立。所有陸地100超、すなわち101hex以上で2/100。休眠・沈没Nationは既存lifecycle契約どおり対象外 |
| 面積 | `IS_LAND` cell数×100。山・採掘場もland | owner一致かつterrainがsea/shallow以外。表示と判定を`NationLandAreaCalculator`で共用 |
| stream | global RNGでretry再現なし | `global_disasters:land_subsidence:nation:{id}:trigger:v1`。Nation追加で既存drawをずらさない |
| 同時性 | 島ごと逐次 | World全体の単一事前snapshotで全Nationの候補を確定し、union後に適用 |
| shallow | 全shallowをsea化 | 対象Nation所有陸地に隣接する中立/自国shallowだけを中立sea化。他国shallowは保護 |
| land | plain elevation候補ごと20%でshallow | snapshotでsea/shallow/World外に接する自国陸地を全て中立shallow化。新しい浅瀬から連鎖しない |
| mountain | candidateがshallow/plainだけなので山・採掘場は無傷 | 面積へ含めるがterrain、facility、owner、quantity、scale、versionを変更しない |
| Capital | 参照実装に新作Capital invariantなし | identity/owner/terrain/coordinateを維持し30%逐次人口damage、最低100。山地Capitalは無傷 |
| 外国・中立 | shared World/Nation境界なし | 他国領土・他国shallow・無関係な中立cellは無傷。共有中立shallowはunionし1回だけ変更 |
| transaction | やまにてぃのturn全体transaction | 現行World transaction、advisory lock、同seed retry、changed cell/chunk契約を維持 |

## `roadmap-pr18-v1` settings

- `enabled = true`
- `base_safe_land_cells = 100`
- `probability = 2 / 100`
- `affected_shallow_result = sea`
- `affected_coastal_land_result = shallow`
- `mountain_immune = true`
- `capital_damage_percentage = 30`
- `out_of_bounds_is_water = true`
- `stream_version = 1`

既存published ruleset payloadは変更しない。PR18 migrationはimmutable snapshotを公開するだけで、
historical Worldやqueue itemを新rulesetへ付け替えない。PR17の`CurrentRulesetGuard`により、旧Worldはresetまでread-onlyとなる。

## audit / player projection

`land_subsidence.triggered`へNation ID/number、target turn、開始時所有陸地、effective safe limit、
sea/shallow変更数、保護山数、Capital damage、affected chunk数を記録する。cell damageは既存の
`disaster.cell_damaged`、Capital damageは`capital.disaster_damaged`を使用する。player projectionは
発生・件数・自国被害・Capital人口結果だけを表示し、seed、raw draw、snapshot、stream metadataを返さない。

## PR18の非スコープ

item/modifier table、unused effect record、item UI、monster、missile、戦闘、国境処理、World reset、
historical runtime fallback、data-preserving World migration、OCI、production DB/World操作は含めない。
