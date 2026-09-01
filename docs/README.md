# Repository documentation guide

この文書はrepository documentationの入口であり、gameplay、Ruleset、schema、migration、運用手順を再記述する仕様書ではない。
知りたいことに応じて読む文書を絞り、current codeとimmutable Rulesetへ到達したら探索を止めるために使う。
各Markdownの分類は[`documentation-inventory.md`](documentation-inventory.md)を参照する。

## Agentの基本読書順

通常のimplementation / review開始時は、次の順で読む。

1. [`AGENTS.md`](../AGENTS.md)
2. [`product/docs/handoffs/development-history-and-current-handoff.md`](../product/docs/handoffs/development-history-and-current-handoff.md)
3. この`docs/README.md`
4. [`docs/open-questions.md`](open-questions.md)
5. task-specificなcurrent code / Ruleset / ADR / architecture / operations文書
6. migration、regression、設計経緯、legacy比較がscopeに入る場合だけhistorical / audit / future文書

全docsを最初に読み込まない。handoffで現在地を確認し、このguideでtask固有の入口を選ぶ。
`Open` gateへ到達した場合は実装を止め、`Deferred`は別のOwner承認なしに実装しない。

## Authority hierarchy

矛盾時は、概ね次の順で確認する。

1. 現在のreview済みapplication code
2. current immutable Rulesetとpublished identity
3. schemaと現在supportedなforward migration contract
4. Acceptedかつ現在も有効なADR / decision
5. current architecture / operations document
6. Ownerの最新明示決定とintegrated handoff（scopeと現在地の確認）
7. `MIXED / PARTIALLY CURRENT`文書（current部分を別のauthorityで確認したうえで由来調査にだけ使う）
8. historical implementation / release / audit document
9. roadmap / future idea
10. AIの記憶・推測

文書の更新日時だけではcurrentnessを決めない。対象version、status、current codeからの参照、後続release、ADR、supported production boundaryを照合する。
published Rulesetの意味を文書やOwner scopeから推測で上書きしない。

## Task別ナビゲーション

表の「最初」は通常の開始点、「必要なら次」は追加調査の入口、「通常不要」は明示した条件がなければ読まなくてよい資料である。

| Task | 最初に読む | 必要なら次に読む | 通常は読まなくてよい |
|---|---|---|---|
| Ruleset authoring / classification | [`product/config/hakoniwa.php`](../product/config/hakoniwa.php)、current [`v18 entrypoint`](../product/config/hakoniwa/rulesets/hakoniwa-2s-plus-v18.php)、[`ruleset-authoring.md`](../product/docs/architecture/ruleset-authoring.md) | [`current-ruleset-baseline.md`](../product/docs/architecture/current-ruleset-baseline.md)、changed domain fragment、`CurrentRulesetAuthoringInspector` | [`configuration-management.md`](architecture/configuration-management.md)はv1〜v9/MVP-eraのcurrent-state記述が混在する。[`product/docs/archive/rulesets/`](../product/docs/archive/rulesets/index.md)、[`ruleset-configuration-layers.md`](../product/docs/architecture/ruleset-configuration-layers.md)、旧Rulesetのrelease資料も通常不要 |
| Migration / install / supported upgrade | [`current-ruleset-baseline.md`](../product/docs/architecture/current-ruleset-baseline.md)、current migration [`2026_08_30_050000_rebaseline_3_0_0_underground_release.php`](../product/database/migrations/2026_08_30_050000_rebaseline_3_0_0_underground_release.php)、`Ver280UnderseaCityRulesetUpgrade` | [`pgsql-schema.sql`](../product/database/schema/pgsql-schema.sql)、`FreshInstallRebaselineTest`、release preflight / backup runbook | [`install-upgrade-rebaseline.md`](../product/docs/architecture/install-upgrade-rebaseline.md)とversion別migration runbookは歴史・互換性理由が必要なときだけ |
| Turn pipeline / RNG / retry | `TurnPipeline::CANONICAL_PHASE_KEYS`、`TurnRunner`、`CompleteTurnEngine`、current Rulesetのturn fragments | `TurnRandomStreamFactory`、TurnRun schema、[`turn-cron.md`](operations/turn-cron.md) | [`turn-randomness.md`](architecture/turn-randomness.md)は固定algorithmの由来が必要な場合だけ読む。pre-missile/stub scopeを現状として使わない。[`turn-pipeline.md`](architecture/turn-pipeline.md)と[`turn-runner-scaffold.md`](architecture/turn-runner-scaffold.md)もcurrent phase authorityではない |
| Command queue / command execution | `CommandQueueService`、`CommandQueueController`、`CompleteTurnEngine`、current Rulesetの`command_definitions` | request fingerprint、queue DB constraints、task対象commandのcanonical service | [`roadmap-pr2-systems.md`](architecture/roadmap-pr2-systems.md)、[`command-api.md`](architecture/command-api.md)、PR14/PR22 auditは由来・regression調査時だけ |
| World / Map / coordinate / expansion | [`ADR-0003`](decisions/ADR-0003-hex-coordinate-system.md)、current Ruleset world fragment、current schema、`WorldExpansionService`、`RegistrationWorldExpansionPlanner` | `NationCreationService`、`MapChunkService`、current API / projection code | [`world-and-map-space.md`](architecture/world-and-map-space.md)、[`registration-and-world-expansion.md`](architecture/registration-and-world-expansion.md)、[`chunk-storage.md`](architecture/chunk-storage.md)はpre-expansion/pre-Turn記述が混在するため由来確認時だけ。[`roadmap-pr4-staggered-xy.md`](roadmap-pr4-staggered-xy.md)とtest planは移行史 |
| Capital / territory / population damage | current Rulesetのcapital / population / territory fragments、current damage / ownership / lifecycle code、[`ADR-0014`](decisions/ADR-0014-ver-2.4.0-nation-dormancy.md) | reached gateに対応する[`open-questions.md`](open-questions.md)のDecisionとcurrent migration / schema | [`capital-and-territory.md`](architecture/capital-and-territory.md)はv5値、proposal、MVP実装記録が混在するため、現行挙動の開始点にしない |
| Nation lifecycle / dormancy / KARMA | [`ADR-0014`](decisions/ADR-0014-ver-2.4.0-nation-dormancy.md)、[`ADR-0015`](decisions/ADR-0015-ver-2.4.0-karma-recovery.md)、current Rulesetの`lifecycle-and-karma` fragment、`NationLifecycleService` | task固有のpolicy / Turn integration / migrationと[`open-questions.md`](open-questions.md)のB-12/B-13/T-02 | [`ADR-0004`](decisions/ADR-0004-nation-dormancy-lifecycle.md)はsuperseded。[`nation-lifecycle.md`](architecture/nation-lifecycle.md)はcurrent identityがv13のままなので補助資料に限定 |
| Monsters / missiles / disasters | current Rulesetの`monsters-and-military` / `terrain-and-disasters` fragments、canonical runtime service、[`ADR-0007`](decisions/ADR-0007-monster-actor-and-occupancy.md) | current monster schema / migration、missile / disaster service、projection codeと対象ADR | [`monster-system.md`](architecture/monster-system.md)はPR21/v5記述が混在するため責務の由来確認時だけ。`ver-2.3.0-c4-*`、[`monster-audit-pr21.md`](../product/docs/monster-audit-pr21.md)、PR auditも歴史的理由・legacy比較時だけ |
| Secretary / skills / equipment / Item | [`current-item-catalog.md`](../product/docs/items/current-item-catalog.md)、current Ruleset Secretary fragments、`SecretaryItemCatalog`、`SecretaryItemGameplayContract` | [`modifier-stacking.md`](../product/docs/architecture/modifier-stacking.md)、[`ADR-0011`](decisions/ADR-0011-secretary-v1-contract.md)、[`ADR-0012`](decisions/ADR-0012-ver-2.1.0-defense-and-secretary-rename.md)、[`ADR-0016`](decisions/ADR-0016-ver-2.5.0-secretary-profile.md) | `ver-2.3.0-c1-*` / `c2-*`、v17 release資料は現在値だけを知る作業では読まない |
| Underground RPG 3.x | [`open-questions.md`](open-questions.md)のE-01/UG-01〜04、[`3.0.0-alpha-underground.md`](roadmap/3.0.0-alpha-underground.md)、[`underground-combat-laboratory.md`](architecture/underground-combat-laboratory.md)、`product/config/underground/balance/foundation-v0.json` / `foundation-v1.json`、`product/config/underground-equipment.php` | `App\Domain\Underground`、`App\Application\Underground`、Underground profile/intro/growth/skill/equipment state、`tests/Underground`。覚醒の将来target意味だけが必要なら[`underground-future-team-battle-contract.md`](architecture/underground-future-team-battle-contract.md) | [`post-release-backlog.md`](future-systems/post-release-backlog.md)はidea provenanceだけ。laboratory観測値を永久hard gateへせず、productionではplaytestを公開しない。team battle、random equipment/drop、affix、unique、enhancement、enchant、market、facility、surface bridgeは別のOwner承認とgateより先に実装しない |
| Underground facility development | [`underground-facility-development.md`](../product/docs/architecture/underground-facility-development.md)、`UndergroundCommandCatalog`、`UndergroundFacilityService`、`CommandQueueService`、`DomesticCommandExecutor` | `nation_underground_facilities` migration、economy projections、missile source attribution、owner-only Underground surface-map projection、focused Surface / Underground tests | Dungeon combatのTurn-independent runtimeへ施設建設を入れない。published Surface Rulesetへ地下commandを混在させない。3D World、facility scale、generic building frameworkを作らない |
| Trading Post / resources / economy | current Rulesetのeconomy / resources / trading-post fragments、`TradingPostRules`、`TradingPostTurnService`、`NationEconomyCalculator` | capacity / sale / settlementのcanonical service、[`modifier-stacking.md`](../product/docs/architecture/modifier-stacking.md) | PR19 resource audit、ver 2.6.0/2.7.0実装資料は由来・regression調査時だけ |
| Authentication / identity | [`ADR-0005`](decisions/ADR-0005-authentication-identities.md)、[`ADR-0006`](decisions/ADR-0006-oauth-packages.md)、current `OAuthController` / `AuthIdentityService` / auth config | [`oauth-setup.md`](operations/oauth-setup.md)、current authorization / concurrency contract | [`authentication-and-identities.md`](architecture/authentication-and-identities.md)は未決定節と決定済み節が混在するためcurrent package判断には使わない |
| Frontend / map loading / UI | current API resource / controller、Vue map state / projection / renderer、[`ADR-0003`](decisions/ADR-0003-hex-coordinate-system.md) | `AssetManifestResolver`、current asset config、対象component / accessibility contract | [`ui-and-map-loading.md`](architecture/ui-and-map-loading.md)と[`tile-asset-mapping.md`](assets/tile-asset-mapping.md)はPR-era footprint、候補mapping、未決定事項が混在するため由来確認時だけ。[`public-lobby-and-island-dashboard.md`](architecture/public-lobby-and-island-dashboard.md)も通常不要 |
| Production deploy / backup / Turn incident | [`turn-cron.md`](operations/turn-cron.md)、[`database-backup-and-restore.md`](../product/docs/operations/database-backup-and-restore.md)、[`docker-compose.md`](operations/docker-compose.md)、current release preflight code | current deployed identity、Owner承認、TurnRun / backup / healthのread-only evidence | version別release / migration / hotfix runbookは同じhistorical operationの調査以外では実行しない |
| Player manual | [`product/docs/manual/index.md`](../product/docs/manual/index.md)とtask対象section、served pathを持つ`ManualController` | current player-visible code / Rulesetと内部architectureを差分確認 | 内部ordering、RNG、transaction資料はplayer decisionへ影響しない限りmanual作業で読まない |
| Reference-source investigation | [`source-inventory.md`](reference-analysis/source-inventory.md)、taskに対応する`reference-analysis`、必要なraw sourceだけ | current code / Ruleset / ADRとの差分、license / provenance | legacy比較がscopeにない通常実装では`reference-analysis/`も`_references/`も読まない |
| Future feature planning | [`open-questions.md`](open-questions.md)のgate、taskに対応する[`future-systems/`](future-systems/post-release-backlog.md)または[`roadmap/2.x.md`](roadmap/2.x.md)。Undergroundは完了済み[`3.0.0-alpha roadmap`](roadmap/3.0.0-alpha-underground.md)をscope記録として使う | current extension boundary、Ownerの新しい明示決定 | roadmap全体を一括承認と解釈しない。Undergroundも後続UG gateを守る。historical TODOを未完了scopeとして復活させない |

## Directory guide

| Directory | 内容 | 通常読むか | 主な役割 |
|---|---|---|---|
| repository root | agent規則、repository入口、license / third-party notice | `AGENTS.md`は常に。他はtask-specific | current policy / index。root `README.md`には既知のstale記述がある |
| `docs/architecture/` | foundational domain architecture、初期PR設計、Turn / map / auth文書 | task-specific | currentとhistoricalが混在。inventoryで個別判定する |
| `docs/decisions/` | ADR / Owner decision | 関連taskで読む | Acceptedはcurrent authority。ADR-0004はADR-0014にsupersede済み |
| `docs/assets/` | tile mapping設計と原画像inventory | asset taskでcurrent resolver / config確認後に必要部分だけ | mapping設計はmixed、inventoryはaudit。exact current mappingはruntime resolverを使う |
| `docs/operations/` | local / Compose / OAuth / Turn / resetと過去hotfix | operation taskのみ | current runbookとhistorical operationが混在 |
| `docs/reference-analysis/` | legacy C source / yamanityのread-only分析 | legacy比較時だけ | audit / reference analysis。current runtime authorityではない |
| `docs/requirements/` | 初期要件の保存 | 通常不要 | historical implementation context |
| `docs/roadmap/`、`docs/future-systems/` | sequencing、未実装案、extension boundary | future planning時だけ | future / roadmap。実装承認ではない |
| `docs/testing/` | 特定移行時のtest plan | regression / migration史調査時だけ | historical implementation |
| `product/docs/architecture/` | current Ruleset authoring / baselineと過去rebaseline | Ruleset / migration taskでtask-specific | currentとhistoricalが混在 |
| `product/docs/operations/` | backup / restoreとversion別production記録 | current operationは該当runbookだけ | database backupはcurrent。`ver-*`はhistorical operation |
| `product/docs/manual/` | applicationが配信するplayer manual | manual / player-visible task | player-facing。内部architecture authorityではない |
| `product/docs/handoffs/` | integrated development historyと現在地 | startupで読む | current index / handoff。code / Rulesetより上位ではない |
| `product/docs/archive/` | Ruleset historyの人間向け索引 | provenance時だけ | historical。payload restore sourceではない |
| `product/docs/items/` | 実装済みItemのcurrent index | Item taskで読む | current index。値の正本はcode / Ruleset |
| `product/docs/`直下 | PR audit、version / checkpoint contract、announcement | 通常不要 | historical / audit / player-facingが混在 |

## 探索停止条件

- current Item仕様だけが必要なら、[`current-item-catalog.md`](../product/docs/items/current-item-catalog.md)からcurrent Ruleset / catalog / gameplay contractを確認した時点で止める。`ver-2.3.0-c1-equipment.md`や`c2-item-effects.md`は読まない。
- current monster修正なら、current Ruleset、canonical runtime service、active ADRまでで止める。mixedな[`monster-system.md`](architecture/monster-system.md)、過去C3/C4資料、monster auditは設計理由・legacy由来・regression原因が必要な場合だけ読む。
- current World / map / frontend / asset挙動だけが必要なら、active ADR、current Ruleset、schema、runtime/API/Vue/manifest codeで止める。初期60×60や未実装scopeを併記するmixed architecture文書へ戻らない。
- Turnのphase順が必要なら`TurnPipeline::CANONICAL_PHASE_KEYS`とcurrent runtime / Rulesetで止める。初期`turn-pipeline.md`の推奨表やscaffoldのstub記述へ戻らない。
- current migration境界が必要ならcurrent baseline、現存migration、upgrade service、schema / testsまでで止める。retired migration chainを通常経路として再構成しない。
- current player-visible説明だけが必要ならmanualとcurrent behaviorを照合して止める。内部ordering / RNG / transactionの歴史資料をmanualへ持ち込まない。
- `reference-analysis/`と`_references/`はlegacy比較、出典、未確定仕様の調査がscopeにある場合だけ読む。
- future / roadmapはOwnerの新しい判断を得るための選択肢整理で止め、承認前にimplementation文書やcodeへ進まない。

## Known `MIXED / PARTIALLY CURRENT`

次の文書には現在も有効な不変条件と、旧Ruleset、PR/MVP-era scope、proposal、後続実装で置き換わったcurrent-state記述が同居する。
current authorityとして単独使用せず、先にcurrent code / immutable Ruleset / active ADRを確認し、歴史的理由や契約の由来が必要な場合だけ参照する。

- [`docs/architecture/configuration-management.md`](architecture/configuration-management.md)
- [`docs/architecture/monster-system.md`](architecture/monster-system.md)
- [`docs/architecture/ui-and-map-loading.md`](architecture/ui-and-map-loading.md)
- [`docs/architecture/world-and-map-space.md`](architecture/world-and-map-space.md)
- [`docs/architecture/registration-and-world-expansion.md`](architecture/registration-and-world-expansion.md)
- [`docs/assets/tile-asset-mapping.md`](assets/tile-asset-mapping.md)
- [`docs/architecture/capital-and-territory.md`](architecture/capital-and-territory.md)
- [`docs/architecture/chunk-storage.md`](architecture/chunk-storage.md)
- [`docs/architecture/turn-randomness.md`](architecture/turn-randomness.md)

## Known `UNKNOWN / CONFLICT`

次の6文書は現行code / Ruleset / ADR / handoffと矛盾または安全に解けない時点混在があり、このPRでは元文書を修正していない。
current authorityとして使わず、[`documentation-inventory.md`](documentation-inventory.md)のNotesを確認する。

- [`README.md`](../README.md)
- [`docs/architecture/authentication-and-identities.md`](architecture/authentication-and-identities.md)
- [`docs/architecture/nation-lifecycle.md`](architecture/nation-lifecycle.md)
- [`docs/architecture/turn-runner-scaffold.md`](architecture/turn-runner-scaffold.md)
- [`docs/architecture/world-expansion-foundation.md`](architecture/world-expansion-foundation.md)
- [`product/config/hakoniwa/rulesets/README.md`](../product/config/hakoniwa/rulesets/README.md)

矛盾を見つけた場合は都合よく統合せず、対象path、対立するcode / Ruleset / ADR、影響するtaskを報告する。
