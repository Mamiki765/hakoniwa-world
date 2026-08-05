# PR22 command, queue, missile, and turn-log audit

## Scope and evidence

PR22の実装前監査は、read-onlyの`_references/hakoniwa-2plus/source/hakow094.tar`内の`hakow.js:36-66`、`command.h:29-54`、`command.c:5-35,74-103,127-697`、`map.c:264-819`と、現在のPR14、PR19、PR21実装を照合した。旧作sourceは挙動の証拠としてだけ使用し、C/C++コード、文言、画像は`product/`へコピーしていない。canonical座標はADR-0003のstaggered `x`/`y`である。

## Legacy command catalog and costs

費用単位は1億円。負の費用は売却・輸送による収入を表し、新作の通常command費用としては使用しない。

| ID | legacy key | source表示名 | source費用 | PR22 |
|---:|---|---|---:|---|
| 1 | `Prepare` | 整地 | 5 | `land_clear` |
| 62 | `Sell` | 食料輸出 | -100 | command化せず`resource_sales`へ統合 |
| 2 | `Prepare2` | 地均し | 100 | `land_level` |
| 3 | `Reclaim` | 埋めたて | 150 | `reclaim` |
| 4 | `Destroy` | 掘削 | 200 | `excavate` |
| 5 | `SellTree` | 伐採 | 0 | `logging` |
| 6 | `Widen` | 領土拡張 | 100 | `territory_expand`。共有Worldでは中立地だけ |
| 21 | `Plant` | 植林 | 50 | `plant_forest` |
| 22 | `Farm` | 農場整備 | 20 | `build_farm` |
| 23 | `Factory` | 工場建設 | 100 | `build_factory` |
| 24 | `Mountain` | 採掘場整備 | 300 | `build_mine` |
| 25 | `Base` | ミサイル基地建設 | 300 | `build_missile_base` |
| 26 | `DBase` | 防衛施設建設 | 800 | `build_defense_facility` |
| 27 | `SBase` | 海底基地建設 | 8,000 | `build_seabed_base` |
| 28 | `Monument` | 記念碑建造 | 9,999 | `build_monument` |
| 29 | `Haribote` | ハリボテ設置 | 1 | `build_decoy` |
| 41 | `MissileNM` | ミサイル発射 | 20/発 | `missile` |
| 42 | `MissilePP` | PPミサイル発射 | 50/発 | `pp_missile` |
| 43 | `MissileLD` | 陸地破壊弾発射 | 100/発 | `land_destruction_missile` |
| 44 | `MissileIS` | 弾道ミサイル発射 | 150/発 | 採用せず、owner決定の`SPP` 500/発へ置換 |
| 45 | `Monster` | 怪獣派遣 | 3,000 | `monster_dispatch` |
| 61 | `DoNothing` | 資金繰り | 0 | `finance`と空queue時のautomatic finance |
| 63 | `Money` | 資金援助 | 100×quantity | `money_aid` |
| 64 | `Food` | 食料援助 | 食料100保存単位×quantity | `food_aid`は1,000トン×quantity |
| 65 | `Propaganda` | 誘致活動 | 1,000 | `attraction` |
| 67 | `Move` | 拠点変更 | 1,000 | `relocate_capital`。ruleset固定額1,000 |
| 66 | `Giveup` | 島の放棄 | 0 | PR22対象外。lifecycle gateを維持 |

`hakow.js`、`command.h`、`command.c`の全件検索では「自動整地セット」または同名の独立command IDは確認できなかった。このarchiveの正本は上表であり、自動整地、農場セット等をserver commandとして追加しない。別配布版のUIマクロを採用する場合は、そのsourceと展開されるqueue itemsを別監査する。

## Future-plan queue contract

- 登録時はcommand key、map bounds、cell存在、quantity、parameter schemaだけを検証する。現在資金、terrain、facility、owner、monster occupancyでは拒否しない。
- UI previewは現在状態と、選択位置より前にある同一座標commandの明示的なterrain/facility/owner結果だけを投影する。完全な乱数・資金・災害simulationは行わない。
- 実行時にlocked current stateを再検証する。失敗はeffectなし、費用なし、queueから消化、retryなしで、同じtarget turnの次itemへ続く。transaction全体がrollbackした場合だけqueued状態へ戻る。
- failure reasonは`CommandFailureReason` enum、player文は`PlayerIslandEventService`で生成する。`command.failed`にはcommand、Nation、x/y、target turn、reason、observed terrain/facility/owner/monster、original parameters、quantityを保存する。
- Nation対象commandはcell選択を要求せず、queueの非null座標契約には実行国の首都座標を内部参照として保存する。

## PR22 command differences

- `logging`: 森を荒地へ変更し、legacy tree unit×5億円をcapacity内で受け取る。公開は曖昧、privateは座標と金額を表示する。
- `territory_expand`: 他国領取得を許可せず、自国領に隣接する中立陸地だけを取得する。
- secret construction: 植林、ミサイル基地、海底基地、ハリボテはpublic projectionと正確なprivate projectionを分離する。ミサイル基地のpublic文とevent typeは植林と同一、海底基地のpublic座標は`(?,?)`とし、正確な座標は建設Nationのprivateログだけに残す。ハリボテのpublic文、event type、facility metadataは防衛施設と同一で、monster self-destructへは接続しない。
- `monument_definitions`: key、name、asset、description、effect、enabled、sort order、metadataをrulesetから分離した編集可能catalogとして保持する。管理者認証・管理画面は別roadmapであり、PR22は任意PHPや式を実行するeffect editorを追加しない。
- aid: activeな同一Worldの別Nationだけを対象にし、sender資産とreceiver capacityを実行時に再検証する。senderとreceiverの双方へ相手Nation、requested/transferred量、receiver capacity、overflowを含む構造化イベントを記録する。
- Capital relocation: cityをCapital、旧Capitalをcityへ置換し、両人口とCapital identityを維持する。費用はruleset固定1,000億円、validator範囲は1,000..9,999。

### Aid and attraction activity follow-up

- `money_aid` / `food_aid` は `transferred > 0` だけをmeaningful normal activityとする。receiver capacity到達による`transferred = 0`はqueueを消化し、既存どおりautomatic financeへ続くがidle counterをresetしない。player projectionにはcapacityによる0移転を明示する。
- 誘致なしはordinary growth、誘致中かつ海際度別通常上限未満は100–3,000/2,000/1,000人、通常上限到達後は100–300/200/100人で成長し、20,000人でclampする。後半rangeはPR22 rulesetの`post_ordinary_attraction_growth`でversion管理する。

## Missiles and owner decisions B-10/B-12

sea/shallow上の所有施設を通常/PP/SPPまたは陸地破壊弾が破壊した場合は、破壊前ownerをevent attribution用にsnapshotしてからfacility、population、ownerを消去する。ordinary missileは水面terrainを維持する。陸上施設のowner、施設のないowned waterへの無効着弾、dormant owner protectionは変更しない。public mapは破壊後のcellを中立の海・浅瀬として表示し、`missile.impact`とprivate launch detailは破壊前target Nationを保持する。

commandは`LaunchIntent`だけを登録する。`process_cells`の既存randomized cell orderで発射基地ごとにcurrent facility、owner、level/capacity、残弾、資金を再検証し、1発ごとに費用を引く。通常/PP/陸地破壊/SPPの誤差半径は2/1/2/0で候補を均等選択する。通常、PP、SPPは陸地を`scorched`へし、settlement人口被害の半分を発射Nationの難民として扱う。陸地破壊弾は陸地を浅瀬、浅瀬を海へし、怪獣を報酬なしで除去し、難民を作らない。

B-10では発射Nation、弾種、発射数と意味のある着弾をpublicとし、効果のない着弾をlaunch単位で集約する。発射Nationだけに狙点、費用、弾種、全着弾結果をprivate表示する。SPPも発射Nationを匿名化しない。同一launchの複数着弾が発生させた難民は個別のstructured eventを保持し、player projectionだけをtarget turn内で合算する。

B-12のPR22境界ではexplicit target cellのowner Nationがactiveでなければcommandを失敗させる。誤差着弾が`dormant_frozen`、`dormant_contestable`、`sunken_archived`所有cellへ到達した場合はterrain、facility、population、owner、monster occupancyを一切変更せず、効果なしへ集約する。怪獣だけを討伐する例外はない。将来の`dormant_contestable`攻撃・占領は未決なのでB-12自体はOpenである。

## Phase, finance, and event contract

canonical 12 phasesでは`resource_sales`を`nation_economy`の後、`development_commands`の前に置く。これにより同target turnの生産物売却益をcommand資金へ利用できる。個別capacity適用は後段`enforce_capacities`で行い、売れなかった超過だけを破棄する。

`finance`は1件がturn-consuming commandである。成功通常commandがあれば`idle_counter=0`、通常成功がなくfinanceだけ成功、または空queue automatic financeならtarget turnあたり最大1増加する。missile commandはintent登録時点では通常command成功に数えず、`process_cells`で1発以上実発射された場合だけresetする。全intentが0発なら失敗として現在値を維持し、zero-shot確定後のautomatic financeは追加しない。失敗commandはresetにも増加にも数えない。

turn eventの正本列は`world_id`、`turn`、`nation_id`、`x`、`y`、`message`、`visibility`、`event_type`、`severity`、`metadata`、timestampsである。visibilityは`public`、`nation`、`private`、`admin`。島詳細は同じWorldのpublicと自Nationのnation/privateだけを返し、private文頭へ「（秘密）」を付ける。TOP重要ログの分類はPR22で変更しない。

## Preserved PR21 actor order

既存怪獣は`process_cells`の既存randomized cell order内で行動し、そのcellが発射基地なら怪獣処理後に基地処理へ進む。`global_disasters`で災害と地盤沈下を処理し、その末尾で自然怪獣を出現させる。怪獣だけのshuffleや再走査はなく、自然出現怪獣は次target turnまで行動しない。
