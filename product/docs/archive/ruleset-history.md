# Ruleset history: Pre-MVP through v11

このarchiveは、人間がRulesetの由来と現在の責務を追うための索引である。gameplayの正本は各immutable authoring fileとpublished database snapshotであり、この文書はpayloadを置き換えない。

Formal `hakoniwa-2s-plus-v1`は歴史の始点ではない。現在のrepositoryには、Git history上のPre-MVP prototype、10個のroadmap snapshot、その後のformal v1-v11、test-only fixture、migration固有のchecksum/fingerprint guardがある。

以下の`.php` source名はすべて`product/config/hakoniwa/rulesets/`配下を指す。
表中のhistorical migration名はpublication provenanceであり、ver 2.4.0 release treeには
残っていない。適用済みledgerはschema dumpと既存databaseに保持される。

## Source classes

| Class | Meaning | Current handling |
|---|---|---|
| Pre-MVP prototype | `mvp-v1`; current config fileになる前の初期shared-world settings | Git historyと最初のroadmap migrationがidentityを保持。current runtime authoring listには入れない |
| roadmap Ruleset | PR単位でpublishされたMVP開発中の完全payload | operator validationとsource audit用のimmutable authoring。normal configやfresh installではpublishしない |
| formal Ruleset | first production release以後のv1-v11 | immutable production history。v11だけがconfigured current gameplay |
| test-only fixture | formal payloadを読み、test identityだけを差し替えるinactive fixture | publishしない。formal sourceとの差分を増やさない |
| retired migration snapshot/guard | source/target checksum、definition set、fingerprint expectations | PR Cのexact-source rebaselineで責務を完了。migration codeはver 2.4.0でretire |

## Pre-MVP and roadmap lineage

| Identity | Publication | Checksum | Main change from predecessor | Migration | Runtime role now |
|---|---|---|---|---|---|
| Git `6f37a410...:product/config/hakoniwa.php` / `mvp-v1` | prototype status; current publication record unknown | unknown | axial-era Pre-MVP settings before typed roadmap authoring | `2026_07_26_010000_add_roadmap_pr2_systems.php` recognizes the source identity | no current authoring read; migration/history only |
| `roadmap-pr2-v1.php` | published roadmap snapshot | `091494cae4988c2517417f91bb9810e277ee665525c98ff67eeb305b23592fe3` | first complete typed roadmap payload: bounds, resources, facilities, commands, production and initial-island contract | `2026_07_26_010000_add_roadmap_pr2_systems.php` | historical migration/rebuild definition |
| `roadmap-pr6-v1.php` | published roadmap snapshot | `e037bec2bb55672fa0497c8238d31f5217f1f17ff48ad153a61993f20ac0fc39` | resource/initial balance and command catalog expansion; plan quantity and shallow-cell minimum | `2026_07_27_010000_publish_roadmap_pr6_ruleset.php` | historical |
| `roadmap-pr7-v1.php` | published roadmap snapshot | `fa9819d1deed15db3c394eb94f0fba5fc1645add2b1e39af2e74873b95a9c7df` | base money/food capacities and inventory sale rates | `2026_07_29_000000_publish_roadmap_pr7_ruleset.php` | historical |
| `roadmap-pr11-v1.php` | published roadmap snapshot | `6a5cad238c6a051fd59c8c45785cbdc880e0354abe30af3ad32946413e27acb6` | queue limit, facility/command/production definitions, sale rates and turn-processing economic loop | `2026_07_30_000000_publish_roadmap_pr11_ruleset.php` | historical |
| `roadmap-pr14-v1.php` | published roadmap snapshot | `c12fe26af6858ed79650c1cb4617fdce03a1c4fc53d4f641f546fd442b87e78e` | seabed-oil/reclaim-related facility, command and turn-processing contracts | `2026_08_02_000000_publish_roadmap_pr14_ruleset.php` | historical |
| `roadmap-pr15-v1.php` | published roadmap snapshot | `5c3a5a339cb379a612a65ffb7918854fb772b169e5a2fd3e6fb42d506dba06d8` | Capital minimum/growth/damage and disaster-era turn processing | `2026_08_02_010000_publish_roadmap_pr15_ruleset.php` | historical |
| `roadmap-pr18-v1.php` | published roadmap snapshot | `ccca701614928f9eb5a8eaea4d27d1b56e9a6254ad281dca11a0a089c9bdabde` | turn-processing contract used by Nation land subsidence/historical-World safeguards | `2026_08_04_000000_publish_roadmap_pr18_ruleset.php` | historical |
| `roadmap-pr19-v1.php` | published roadmap snapshot | `f5adac988282b5c35029210db59135312056238845f5cb0b891ec7d9a6d922d7` | resource definitions, per-resource capacities and overflow policy | `2026_08_04_020000_publish_roadmap_pr19_ruleset.php` | historical |
| `roadmap-pr21-v1.php` | published roadmap snapshot | `2097df8cf87469fef8b8ec47f5cffc80569a479a9a50a5b119f513d42b458687` | shared-world monster definitions and monster-system settings | `2026_08_05_000000_create_monster_system_and_publish_roadmap_pr21_ruleset.php` | historical |
| `roadmap-pr22-v1.php` | published roadmap snapshot | `3c88b8c34b382f9c3fbce96f3d9609c19d1e04599f253b7aae42173b4e351bd0` | command/missile contract, facility definitions, Capital relocation and military settings | `2026_08_05_010000_add_pr22_command_event_state_and_publish_ruleset.php` | final roadmap predecessor of formal v1 |

These files are not abandoned drafts. They remain historical authored snapshots exposed
only through `RulesetUpgradeAuthoringCatalog`; they are no longer migration inputs or normal
test bootstrap data.

## Formal v1-v11 lineage

| Version | Immutable source | Checksum | Main delta from prior version | Publication/migration | Current runtime handling |
|---:|---|---|---|---|---|
| 1 | `hakoniwa-2s-plus-v1.php` | `0c03226dd5c99c0293392ed1bc5528a03093084e622ff21e3784a8810c3b8ba0` | first production identity and reviewed player-facing command/monster descriptions over the roadmap foundation | `2026_08_05_020000_prepare_first_production_release.php` | immutable history |
| 2 | `hakoniwa-2s-plus-v2.php` | `8c865b7e53593ad90a97357d50fa39e3ebdaf4e97bc925118b1012e01ea38234` | explicit missile targeting owner decision | `2026_08_09_000000_publish_hakoniwa_2s_plus_v2.php`; separate live-monster reference repair | immutable history |
| 3 | `hakoniwa-2s-plus-v3.php` | `3d03cb6912ba7082376e9b262fb95d03ca30917d8eecbbc521bf63b27a53ce36` | exact territory expansion/influence and transfer contract | `2026_08_10_000000_publish_hakoniwa_2s_plus_v3.php` | immutable history |
| 4 | `hakoniwa-2s-plus-v4.php` | `b899c7cf92c47be3d464ec1d52c93ff2e5177605fe4453d32c6b529fcb37bd42` | launch/seabed-base facility and military contracts | `2026_08_13_000000_publish_hakoniwa_2s_plus_v4.php` | immutable history |
| 5 | `hakoniwa-2s-plus-v5.php` | `4d1a004332b79b298460c0316c6ec00972a27517e01079f2378fc5de78591ab6` | removes the sea-edge population gameplay dependency | `2026_08_14_000000_publish_hakoniwa_2s_plus_v5.php` | immutable authoring/history; no current mutation branch |
| 6 | `hakoniwa-2s-plus-v6.php` | `5f3567fb352379727878f83cd1f66c36885cb4485c9153baaf315bab4140dcb2` | logging becomes plain; defense owner-overbuild self-destruct; monument flight/target; direct SPP defense resistance | `2026_08_16_000000_publish_hakoniwa_2s_plus_v6.php` | immutable history |
| 7 | `hakoniwa-2s-plus-v7.php` | `6b9def1bb8921d233bd2080e5f89584cccf8a3a09184dcfac475ddb599f2a670` | Secretary v1 skill/snapshot contract | `2026_08_16_030000_publish_hakoniwa_2s_plus_v7.php` | immutable history |
| 8 | `hakoniwa-2s-plus-v8.php` | `fdceaec1e45bad64ceb177f880e513adeb5c3816c96858b00d8a988ad347990d` | radius-two real-defense interception contract | `2026_08_16_040000_publish_hakoniwa_2s_plus_v8.php` | immutable history |
| 9 | `hakoniwa-2s-plus-v9.php` | `78b55d34ce3148eb1e4b6dd97939468cee9df508d28f4084947a09cdd10fd883` | `normal_monster_stage = after_ordinary_surface_cell_events`; same cell order, no extra shuffle | `2026_08_17_000000_publish_hakoniwa_2s_plus_v9.php` | immutable authoring/history; v11 is the only mutable World runtime |
| 10 | `hakoniwa-2s-plus-v10.php` | `6a0f5354f8894081bacdb8eaaba328d3e4ee80a2c4136819b16cfa924f485dc1` | post-nutrition food overflow stage contract | `2026_08_19_010000_publish_hakoniwa_2s_plus_v10.php` | immutable authoring/history; former exact v11 source migration is retired |
| 11 | `hakoniwa-2s-plus-v11.php` | `5c65c49ed3fd623375f004815ec6bba0b2f67524f61f0638c6fe528fe9599db8` | Secretary Old Bow/Ring effects, explicit monster behavior/reward/display, Aoi/Zero, two-option dispatch selector | historical `2026_08_21_010000_publish_hakoniwa_2s_plus_v11.php`; current rebaseline publishes/asserts it | configured current gameplay and immutable history |

## Runtime and retry interpretation

- `config/hakoniwa.php` registers only current v11. Historical source lookup is an explicit operator action through `RulesetUpgradeAuthoringCatalog`.
- current ordinary World mutation is guarded to the configured current identity, which is v11.
- current gameplay reads the v11 published row stored on the World and snapshots that exact Ruleset on each TurnRun.
- historical Worlds remain readable for presentation and audit, while every gameplay mutation fails closed through the current Ruleset guard.
- a failed/blocked TurnRun may be retried only for the same target, Ruleset and seed. Version migration preflight must resolve such a run first; 2.3.1 does not invent cross-version retry.
- killed/removed monsters, terminal commands, events and published rows remain linked to historical definitions. They are not rebound merely because v11 is current.

PR C established the exact 2.3.1/v11 source preflight and forward-only rebaseline. PR D then
removed migration-only runtime and unreachable historical gameplay branches without changing
the v11 payload or current data links.

## Test and migration-only sources

`tests/Support/V11SecretaryItemRulesetFixture.php` reads formal v11 and changes only the
inactive test identity. It is not a never-published gameplay proposal. The retired v11
migration guard is preserved by Git history and the PR C compatibility audit, not current
runtime PHP.

No other never-published Ruleset, obsolete duplicate, or unknown payload was found in the
repository and file-history audit.

## How to verify the current source

```text
php artisan hakoniwa:ruleset:validate --key=hakoniwa-2s-plus-v11
```

The expected v11 checksum is `5c65c49ed3fd623375f004815ec6bba0b2f67524f61f0638c6fe528fe9599db8`. Validation reads authoring only; it does not publish, migrate a World, or modify production data.
