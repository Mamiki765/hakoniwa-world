# ver 2.3.0 formal ruleset v11 and conversion contract

> Historical release record. v11 is not the current application contract. Current authoring
> is v16; see [`architecture/current-ruleset-baseline.md`](architecture/current-ruleset-baseline.md).

## Publication identity

ver 2.3.0の正式なproduction rulesetは`hakoniwa-2s-plus-v11`、version `11`である。authoring sourceは`config/hakoniwa/rulesets/hakoniwa-2s-plus-v11.php`、payload checksumは次で固定する。

```text
5c65c49ed3fd623375f004815ec6bba0b2f67524f61f0638c6fe528fe9599db8
```

v11はimmutableなv10 authoring fileを読み、key/versionを進め、C1-C4で検証済みのSecretary Item、monster foundation、Aoi Inora、Mecha Inora Zero、monster dispatch selectorだけを決定的に適用するdelta authoringである。runtimeはtest fixtureを参照しない。test fixtureは逆にformal v11を読み、test専用identityだけを差し替えるため、fixtureとproduction payloadの意味差を作れない。

v1-v10のsource、payload、checksum、published row、既存migration、historical definitionは変更しない。publisherは同じv11がすでに存在する場合にsettingsと全definitionの完全一致を検証して返し、差異、重複、不足を上書きしない。

## Atomic forward conversion

`2026_08_21_010000_publish_hakoniwa_2s_plus_v11.php`は`RulesetV11MigrationService`を呼ぶforward-only migrationである。`down()`はデータを逆変換せず、承認済みbackupからのrestoreを要求する。

変換は1つのdatabase transaction内で次の順序を守る。

1. 共通World advisory lockを取得し、single `shared-world` scopeとWorld rowをstable ID順でlockする。
2. 次のnon-dry TurnRunが`pending`、`running`、`failed`、`blocked`ならpublication前に拒否する。
3. exact v10 source row、checksum `6a0f5354f8894081bacdb8eaaba328d3e4ee80a2c4136819b16cfa924f485dc1`、global catalogs、production/command/historical eight monster stable keysを検証する。
4. queue、alive monster、current kill-statをstable ID順でlockし、unknown、mixed v11 reference、矛盾したprovenance、曖昧なdispatch、HP/occupancy/cross-World異常をmutation前に拒否する。
5. authoritative `RulesetPublisher`でsource v10 equalityを再確認し、formal v11を1回だけpublishする。
6. safely attributable request provenanceだけをexact v10へbackfillする。fingerprint、request key、parameter、target snapshot、quantity、queue position、status、timestampは再計算・正規化しない。null fingerprintはnullのまま保持する。
7. `queued` itemのexecution definitionだけをstable command keyでv11へrebindする。`completed`、`failed`、`cancelled`のdefinitionとpayloadはv10 historyに残す。historical v10 `monster_dispatch`はproved quantity 1 target-only shapeだけをselector 1として実行可能にし、stored quantity/payload/fingerprintを変えない。
8. `alive` MonsterInstanceだけをhistorical eight stable keyでv11へrebindする。`killed`と`removed`はv10 definitionに残す。HP、state、occupancy、spawn turn、versionは変えない。
9. current `NationMonsterKillStat`をstable monster keyでv11へrebindする。count、first/last turn、versionは変えず、Aoi/Zero statを生成しない。
10. immediate integrity triggerが一行ごとの中間stateを拒否する場合、`monster_instance_world_ruleset_guard`と`nation_monster_kill_stat_guard`だけをtransaction内で一時disableする。元のtrigger/function definitionを取得し、World activation前後の同じtransaction内でenableしてbyte-equivalent definitionとenabled stateを検証する。queue constraintは既存DEFERRABLE constraintだけをdeferする。
11. 最後にWorldをexact v10からexact v11へactivateし、全postconditionとfingerprint byte identityを検証してcommitする。

Worldのcurrent turn、random seed/history、TurnRun、Nation balance、MapCell、Secretary、skill/experience、equipment version、Item instance、grant key、level、equipped slot、Warehouseは変更しない。Ringはgrantしない。migration中にItem effect、equipment audit、monster spawn/reward/stat/event、turn executionは発生しない。

## Idempotency and rollback proof

exactな2回目は同じpublicationを検証し、Worldと全live referenceがcomplete v11 stateであることを確認して`alreadyCompleted`を返す。row、ID、fingerprint、history、auditを追加・更新しない。v10/v11が混ざったpartial stateはcompletedとして扱わない。

test-only Closure seamはprovenance、queue、monster、kill-stat、World activation後にfailureを注入できる。World activation後のfailure testは、新規v11 publication、provenance、queue/monster/stat/World rebind、trigger stateをすべてrollbackし、fingerprintとterminal historyが同一であることを証明する。このseamはcommand、HTTP、config、environment variableとして公開しない。

## Runtime activation

v11開始後のTurnRunだけがC1-C4のItem effects、Aoi stage、Zero action、dispatch selectorを実行する。Aoiは通常怪獣と同じ保護対象、防衛施設接触、occupancy、候補抽選を使い、追加で海・浅瀬・海底基地・海底油田へ侵入できる。合法な着地点は施設と人口を失った中立の海となるため、その海を足場に次のturnからさらに合法な陸地へ侵攻できる。既存starter Old Bowはinstanceとslotを維持し、装備中なら最初のv11 TurnRunから効果が始まる。外していれば発動しない。historical failed v10 TurnRunはsame-target/same-ruleset/same-seed retryでもv10 settingsを使い、Item effects、Aoi stage、Zero action、random populationの変更を受けない。
