# PR21 怪獣system監査と実装契約

- 監査日: 2026-08-05
- 対象ruleset: `roadmap-pr21-v1`
- reference source: `_references/hakoniwa-2plus/source/hakow094.tar`内の`monster.c`、`monster.h`、`map.c`、`turn.c`、`hakow.js`
- 画像監査source: `_references/hakoniwa-2plus/assets/hakogif`
- 新作正本: `product/config/hakoniwa/rulesets/roadmap-pr21-v1.php`

`_references`は読取り専用であり、変更・移動・runtime配信・commitをしない。GIFバイナリはGit、`product/public`、container imageへ収録しない。以下ではsourceから観察したfactと、本作が採用したowner decisionを区別する。

## kind、stable key、画像の監査表

通常画像の対応は`hakow.js:68-103`の`monsterImage`、硬化variantは`hakow.js:599-610`の`monsterImage2`を根拠とする。画像はすべて32×32の原GIFであり、SHA-256はローカル監査sourceに対する値である。

| source kind | stable monster key | 表示名 | source filename | asset key | SHA-256 |
|---:|---|---|---|---|---|
| 0 | `mecha_inora` | メカいのら | `monster7.gif` | `hakoniwa_original.monster.mecha_inora` | `fe26ae4341b76a92804bce0386d1be4bd338f37d497081258764704ea1fd5c2a` |
| 1 | `inora` | いのら | `monster0.gif` | `hakoniwa_original.monster.inora` | `8b4423320ba35471f6070829fae70a81521be0399e67f81fd10b48a364da2423` |
| 2 | `sanjira` | サンジラ | `monster5.gif` | `hakoniwa_original.monster.sanjira` | `f56b9ff4d64be3389351d3e691eb9262cd95f3c043a71170f1560b3a5357d578` |
| 3 | `red_inora` | レッドいのら | `monster1.gif` | `hakoniwa_original.monster.red_inora` | `de090036e41727501b562c3062ea3331a9efb236c3ad490b54a93dcad268706e` |
| 4 | `dark_inora` | ダークいのら | `monster2.gif` | `hakoniwa_original.monster.dark_inora` | `8d51c6a863aa9ff567aba8e5bd92c1533ec1c09342a8efffa3ce1bc8526b5d01` |
| 5 | `inora_ghost` | いのらゴースト | `monster8.gif` | `hakoniwa_original.monster.inora_ghost` | `b837630753ef727b17452fd8b641c8d77c358fd3cbd9a44b1ac1fe7abc140405` |
| 6 | `whale` | クジラ | `monster6.gif` | `hakoniwa_original.monster.kujira` | `543762b49e6ea428c7d6b6812f778defc3b6e740f1fd497d4a672d5c3d719d9d` |
| 7 | `king_inora` | キングいのら | `monster3.gif` | `hakoniwa_original.monster.king_inora` | `29b7901fa627f44cbb0732ce894cc9184e1511b5ca9fccf7ba7c348b96540fe8` |

`monster4.gif`（SHA-256 `e4348db831cef15a65e74ff40fb153941683c5c0a83a1f1df68e458e8b814e9d`）はkindを表す通常画像ではない。kind 2と6の硬化状態だけが`hakoniwa_original.monster.hardened`として使用する。画像の加工、再描画、形式変換、AI生成、別素材による代用はしない。

## catalog

`monster.c:3-23`のbase HP、HP差分、skill、経験、価値をsource-derived factとし、PR21で次の独立definitionへ採用した。HPは出現時に`base_hp + uniform(0..hp_variation)`で一度決まり、instanceが`spawned_max_hp`と`current_hp`を保持する。

| kind / key | HP | skill / turn-local移動上限 | 基地経験 | wreckage value（億円） | 自然出現tier |
|---|---:|---|---:|---:|---:|
| 0 `mecha_inora` | 2 | none / 1 | 5 | 0 | 出現しない |
| 1 `inora` | 1〜2 | none / 1 | 5 | 400 | 1 |
| 2 `sanjira` | 1〜2 | odd target turnで硬化 / 1 | 7 | 500 | 1 |
| 3 `red_inora` | 3〜4 | none / 1 | 12 | 1,000 | 2 |
| 4 `dark_inora` | 2〜3 | move_2 / 2 | 15 | 800 | 2 |
| 5 `inora_ghost` | 1 | move_9999 / 9,999 | 10 | 300 | 2 |
| 6 `whale` | 4〜5 | even target turnで硬化 / 1 | 20 | 1,500 | 3 |
| 7 `king_inora` | 5〜6 | none / 1 | 30 | 2,000 | 3 |

硬化判定のturnはbackendの`targetTurn`、UIの現在表示では`world.current_turn`を正本とする。硬化中はmovementと通常HP damageを止めるが、terrain overwriteと防衛施設self-destructによるexplicit removalは止めない。

## Nation単位の自然出現

owner decisionとして、`global_disasters`末尾で各`active` Nationにつき一回だけ独立drawする。`dormant_frozen`と`dormant_contestable`は対象外である。判定前にWorld全体の所有陸地数、人口、有人口集落候補、occupancyをsnapshotし、一国の出現結果を他国の候補や確率へ波及させない。

```text
numerator = min(10000, owned_land_cells * 2)
probability = numerator / 10000
```

人口は所有cellの合計、候補は人口が正でfacilityが`village`、`town`、`city`のcellとする。Capitalと既存occupancyは候補外。trigger、candidate、type、HPはNation IDをnamespaceへ含む別streamで決定する。

| Nation人口 | exact uniform pool |
|---:|---|
| 100,000未満 | 自然出現なし |
| 100,000〜249,999 | `inora`, `sanjira` |
| 250,000〜399,999 | `inora`, `sanjira`, `red_inora`, `dark_inora`, `inora_ghost` |
| 400,000以上 | `inora`, `sanjira`, `red_inora`, `dark_inora`, `inora_ghost`, `whale`, `king_inora` |

出現時は選ばれた集落の人口とfacilityを除去し、ownerを維持した荒地へ変え、そのcellへ怪獣を配置する。trigger後に候補がなければno-opとして`monster.spawn_failed_no_settlement`を残す。

## movementとcell processing

sourceの一回cell passを採用する。randomized surface cell orderでoccupancyを見つけたときに怪獣actorを先に処理する。各actionで最大3方向を独立drawし、最初の有効候補へ移動する。移動先が同じpassで未処理なら再行動でき、処理済みcellなら再行動しない。成功移動数はturn-local batchだけに保持し、instance/APIへ`moves_taken`を保存・公開しない。

海、浅瀬、海底施設、山、採掘場、Capital、記念碑、World外、別怪獣cellは通行不可。通常の陸地・facility・集落へ入るとfacilityと人口を除去してowner維持の荒地にする。元cellは既に荒地であり、旧terrainを復元しない。

### 怪獣派遣のspawn-turn balance decision

raw Hakoniwa 2＋sourceの一般的なcell-pass観察とは別に、2S＋ではowner balance decisionとして、`monster_dispatch`で出現したメカいのらを出現target turnのmovement batchから除外する。したがって出現時の対象集落破壊だけを同turnに確定し、そのturnには移動、追加の踏み潰し、防衛施設接触・self-destructを行わない。次target turnから通常の`mecha_inora`として行動できる。この境界は`MonsterSpawnSource::MonsterDispatchCommand -> canActOnSpawnTurn() = false`を正本runtime mappingとし、自然出現を含むraw source-derived挙動として扱わない。command側の処理順は[command-audit-pr22.md](command-audit-pr22.md)を参照する。

## terrain event相互作用

| event | occupancy | HP/reward/stat | event順序 |
|---|---|---|---|
| earthquake / tsunami / typhoon | 維持し、怪獣cellへの通常damageをskip | 変更なし | 怪獣を観測してcell effectをskip |
| fire / riot | 維持し、怪獣actor処理後のcell effectをskip | 変更なし | monster actorがcell turnを占有 |
| meteor shower / huge meteor / eruption | explicit removal | HP damage・報酬・kill statなし | occupancyを除去してからterrain変更 |
| land subsidence | explicit removal | 同上 | occupancyを除去してから沈下 |
| terrain-destruction missile / administrative overwrite | explicit removal境界 | 同上 | terrain変更serviceが必ず除去を先行 |
| defense facility contact | `defense_self_destruct`でexplicit removal | killer・報酬・経験・kill statなし | 怪獣除去後、center固定の巨大隕石相当blastを一度だけ実行 |

防衛施設self-destructはrandom triggerを使わず、chain reactionを起こさない。通常HP damageや硬化判定を通さず、`monster.defense_self_destructed`と`disaster.triggered`を別々に監査する。

playerの`land_clear`、`land_level`、`excavate`は怪獣occupancyがあるtargetを`monster_occupied`で実行時拒否し、費用・terrain・occupancy・statを変更しない。operator用のadministrative overwriteだけが、明示的な報酬なしremovalを先行してからterrainを変更できる。

## damage、reward、authoritative kill stats

`MonsterDamageService`はmonster、positive damage、damage type、killer Nation nullable、firing base nullable、現在host cell、turn contextを明示的に受ける。硬化時は`monster.damage_blocked`、生存damageは現在HPを更新する。final blow時だけoccupancyを除去し、Nation attributedならmonster instance lock下で`nation_monster_kill_stats`を一度だけupsertする。retryは`already_resolved`で二重付与せず、異なるinstanceの同種撃破はunique scopeへのatomic incrementで直列化する。全体を一transactionに置き、失敗時はHP、occupancy、資産、経験、stat、eventをrollbackする。

```text
killer_money_share = floor(wreckage_value_money / 2) 億円
host_meat_value = wreckage_value_money - killer_money_share
host_monster_meat = host_meat_value * 500 トン
```

現行versioned sale contractは怪獣肉1,000トン=2億円なので、1億円相当は端数のない500トンである。例: value 1,000はkiller 500億円、死亡時cell ownerへ怪獣肉250,000トン。奇数valueの余りはhost側へ入る。moneyとfoodは既存capacity serviceで`requested / applied / overflow`を記録する。hostが中立ならhost shareは失効しkillerへ振り替えない。killerがnullなら両方のreward、基地経験、kill statを作らない。同一Nationがkiller/hostなら同じNationへ両assetを個別capacityで付与する。基地経験はdefinition値を加え、200を上限とする。

`nation_monster_kill_stats`は`world_id`、`nation_id`、`monster_definition_id`をunique scopeとし、`kill_count`、`first_killed_turn`、`last_killed_turn`、`version`を永久gameplay stateとして保持する。初回はcount/version 1かつfirst=last=target turn、以後は同じ行のcount/versionを1増やし、firstを維持してlastを更新する。DB constraint/triggerが非負turn、count、World/Nation/definition整合、cross-World参照、不正な直接更新・World存続中の削除を拒否する。個別撃破を保存するtableは持たない。

このNation単位spawn、reward split、討伐統計を`MONSTER-04`のowner decisionとして固定する。kill markはstat rowの`kill_count > 0`、種類別討伐数は`kill_count`、Nation総トドメ数は対象Nation最大8行の`SUM(kill_count)`を正本とし、`nations`へ重複totalを置かない。value 0のメカいのらもNation attributed final blowならcount対象。PR21ではawardを実装しなかったが、AWARD-01はver 1.3.0の`docs/decisions/ADR-0009-ver-1.3.0-awards-and-classic-top.md`で後続決定された。

## API、map overlay、event secrecy

chunk projectionはmonsterの`id`、`key`、`name`、effective `asset_key`/`asset_url`、現在HP、出現時最大HP/definition HP範囲、skill説明、硬化、現在cell ownerのNation number/name、`N{nation_number}`または`無所属`を返す。spawn元Nationは保持・表示しない。seed、raw draw、candidate、raw skill code、turn-local move counter、source metadataは公開しない。

Vue mapはterrain/facility tileの上に独立HTML/CSS overlayを描き、GIF、`HP n`、現在host labelを常時表示する。`pointer-events: none`でclick/drag/panを奪わず、cellのaria labelとCellDetailsからkeyboardでも情報へ到達できる。chunk再取得はcell stateを置換するため移動・damage・death後の残像や二重overlayを作らない。

最低限のaudit eventは`monster.spawned`、`monster.spawn_failed_no_settlement`、`monster.moved`、`monster.trampled`、`monster.stayed`、`monster.damage_blocked`、`monster.damaged`、`monster.killed`、`monster.reward_distributed`、`monster.kill_stat_incremented`、`monster.defense_self_destructed`、`monster.removed_by_terrain_event`。個別撃破のinstance/definition、killer、nullable host、turn、previous/new count、money/meat rewardとoverflow、nullable firing baseはこのstructured eventへ記録する。player logはkillerへ撃破と賞金、hostへ撃破と怪獣肉をrole-aware messageとして投影し、raw metadataを返さない。

PR21時点では公開Nation detailだけが対象Nationのstatを一query・最大8行で取得し、`monster_final_blow_count`とkey/name/count/first/lastを返し、公開TOPはstatを取得しなかった。ver 1.3.0はこの履歴契約をTOPに限って後続変更し、World単位一括queryでkill markを投影する。現行正本は`docs/architecture/public-lobby-and-island-dashboard.md`とADR-0009である。

## asset配信とdeployment境界

`AssetManifestResolver`が上表のasset keyをbasenameへ解決し、既存terrain/facilityと同じ`HAKONIWA_TILE_ASSET_PATH`および`HAKONIWA_TILE_ASSET_BASE_URL`経路を使う。production配置先は既定で`/srv/hakoniwa-assets/tiles`、read-only mountとする。必要ファイルは`monster0.gif`〜`monster8.gif`の9個。不足、読取不能、不正形式、非正方形の場合はURLを返さず、labelを持つCSS fallbackを表示する。API、page、Vite production buildはasset不足で失敗しない。

権利・再配布方針は`docs/reference-analysis/license-and-provenance.md`とADR-0002を変更しない。本PRがGit管理するのはmapping、runtime resolver、API/UI、fallback、文書、deployment要件、テストだけである。
