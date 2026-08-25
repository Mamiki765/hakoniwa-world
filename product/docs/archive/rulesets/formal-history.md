# Formal Ruleset history: v1-v15

This file indexes immutable production Rulesets before the current v16. It does not duplicate
the PHP payload and cannot be used to reconstruct one. Each source reference names a commit
and path that can be inspected directly. Migration filenames for v1-v10 and the original v11
publication and later rebaseline/forward-chain filenames are historical Git/ledger
provenance; their PHP is retired from the current tree.

Every version below is a published formal production record. Its complete PHP is available
from the recorded Git commit/path, not the current tree or this Markdown summary. Normal
application config, tests, and the operator validator load only current v16.

## v1

- Ruleset key / version: `hakoniwa-2s-plus-v1` / `1`
- Application version: `1.0.0` first-production line
- Publication state: published formal production snapshot; immutable historical record
- Checksum: `0c03226dd5c99c0293392ed1bc5528a03093084e622ff21e3784a8810c3b8ba0`
- Source: `24b78b730ecad8b25086141a828b7cf8539f4095:product/config/hakoniwa/rulesets/hakoniwa-2s-plus-v1.php`
- Migration: historical `2026_08_05_020000_prepare_first_production_release.php`
- Behavior change: first formal identity over the final roadmap command, turn, missile,
  monster, territory, and publication contracts.
- Data change: first formal production values for costs, rates, capacities, HP, rewards, and
  initial state; there is no earlier formal payload for a delta comparison.
- Flavor change: reviewed player-facing command and monster names/descriptions over the
  roadmap foundation.
- Current handling: historical database/Git only; executable PHP retired from current tree.

## v2

- Ruleset key / version: `hakoniwa-2s-plus-v2` / `2`
- Application version: `1.1.0`
- Publication state: published formal production snapshot; immutable historical record
- Checksum: `8c865b7e53593ad90a97357d50fa39e3ebdaf4e97bc925118b1012e01ea38234`
- Source: `575f3845addede20a0e54a8389d01aba3fc2e651:product/config/hakoniwa/rulesets/hakoniwa-2s-plus-v2.php`
- Migration: historical `2026_08_09_000000_publish_hakoniwa_2s_plus_v2.php`; separate live
  monster-reference repair is indexed by `docs/operations/hotfix-1.1.1-live-ruleset-references.md`.
- Behavior change: explicit missile target-state and impact contract from ADR-0009.
- Data change: no separately documented numeric delta found.
- Flavor change: no separately documented flavor delta found.
- Current handling: historical database/Git only; executable PHP retired from current tree.

## v3

- Ruleset key / version: `hakoniwa-2s-plus-v3` / `3`
- Application version: `1.4.0`
- Publication state: published formal production snapshot; immutable historical record
- Checksum: `3d03cb6912ba7082376e9b262fb95d03ca30917d8eecbbc521bf63b27a53ce36`
- Source: `eef3049a3252ed75986b5948e0adaa7e77789536:product/config/hakoniwa/rulesets/hakoniwa-2s-plus-v3.php`
- Migration: historical `2026_08_10_000000_publish_hakoniwa_2s_plus_v3.php`
- Behavior change: exact manual territory expansion, sequential territory influence,
  transfer eligibility, target/source state, and capital-core protection contracts.
- Data change: direction/attempt and radius inputs attached to the territory contracts.
- Flavor change: no separately documented flavor delta found.
- Current handling: historical database/Git only; executable PHP retired from current tree.

## v4

- Ruleset key / version: `hakoniwa-2s-plus-v4` / `4`
- Application version: `1.5.0-beta`
- Publication state: published formal production snapshot; immutable historical record
- Checksum: `b899c7cf92c47be3d464ec1d52c93ff2e5177605fe4453d32c6b529fcb37bd42`
- Source: `31b1e857db3065ebbb67ce0a4c5a3177ce40bdd4:product/config/hakoniwa/rulesets/hakoniwa-2s-plus-v4.php`
- Migration: historical `2026_08_13_000000_publish_hakoniwa_2s_plus_v4.php`
- Behavior change: automatic World expansion, seabed-base facility/launch behavior, and
  related military/disaster rules.
- Data change: expansion, launch-level/capacity, experience, and resistance values.
- Flavor change: player-facing facility/command authoring added with the new contracts.
- Current handling: historical database/Git only; executable PHP retired from current tree.

## v5

- Ruleset key / version: `hakoniwa-2s-plus-v5` / `5`
- Application version: `1.5.0-beta.3`
- Publication state: published formal production snapshot; immutable historical record
- Checksum: `4d1a004332b79b298460c0316c6ec00972a27517e01079f2378fc5de78591ab6`
- Source: `d88592a865fa65ba9e9cb143a23c1e1658424e2b:product/config/hakoniwa/rulesets/hakoniwa-2s-plus-v5.php`
- Migration: historical `2026_08_14_000000_publish_hakoniwa_2s_plus_v5.php`
- Behavior change: removes sea-edge-derived settlement population bands and uses one
  location-independent settlement path.
- Data change: ordinary/attraction growth ranges and population caps for the new path.
- Flavor change: no separately documented flavor delta found.
- Current handling: historical database/Git only; executable PHP retired from current tree.

## v6

- Ruleset key / version: `hakoniwa-2s-plus-v6` / `6`
- Application version: `1.7.0`
- Publication state: published formal production snapshot; immutable historical record
- Checksum: `5f3567fb352379727878f83cd1f66c36885cb4485c9153baaf315bab4140dcb2`
- Source: `104aca6240b2494655c34ac97ba332c9df2f6932:product/config/hakoniwa/rulesets/hakoniwa-2s-plus-v6.php`
- Migration: historical `2026_08_16_000000_publish_hakoniwa_2s_plus_v6.php`
- Behavior change: logging result becomes plain terrain; defense owner-overbuild
  self-destruct, monument flight/target, and direct SPP defense-resistance contracts.
- Data change: no separate balance-only delta is identified by the retained release summary;
  use the source diff for exact values.
- Flavor change: no separately documented flavor delta found.
- Current handling: historical database/Git only; executable PHP retired from current tree.

## v7

- Ruleset key / version: `hakoniwa-2s-plus-v7` / `7`
- Application version: `2.0.0`
- Publication state: published formal production snapshot; immutable historical record
- Checksum: `6b9def1bb8921d233bd2080e5f89584cccf8a3a09184dcfac475ddb599f2a670`
- Source: `c466a089b3e004dbecb66338bb7bd073c5725517:product/config/hakoniwa/rulesets/hakoniwa-2s-plus-v7.php`
- Migration: historical `2026_08_16_030000_publish_hakoniwa_2s_plus_v7.php`
- Behavior change: Secretary v1 skills, turn-start snapshot, experience-source, and effect
  interpretation contracts.
- Data change: skill level requirements, per-level amounts, and experience points.
- Flavor change: Secretary skill names.
- Current handling: historical database/Git only; executable PHP retired from current tree.

## v8

- Ruleset key / version: `hakoniwa-2s-plus-v8` / `8`
- Application version: `2.1.0`
- Publication state: published formal production snapshot; immutable historical record
- Checksum: `fdceaec1e45bad64ceb177f880e513adeb5c3816c96858b00d8a988ad347990d`
- Source: `618fe0d945d193dcc58d70ccb2ac02f878f239f8:product/config/hakoniwa/rulesets/hakoniwa-2s-plus-v8.php`
- Migration: historical `2026_08_16_040000_publish_hakoniwa_2s_plus_v8.php`
- Behavior change: real defense facilities intercept eligible missiles within the explicit
  direct-hit/overlap/ordering policy from ADR-0012.
- Data change: defense radius two is the numeric input to that policy.
- Flavor change: no Ruleset flavor delta documented; Secretary rename was application
  behavior, not Ruleset flavor.
- Current handling: historical database/Git only; executable PHP retired from current tree.

## v9

- Ruleset key / version: `hakoniwa-2s-plus-v9` / `9`
- Application version: `2.1.3`
- Publication state: published formal production snapshot; immutable historical record
- Checksum: `78b55d34ce3148eb1e4b6dd97939468cee9df508d28f4084947a09cdd10fd883`
- Source: `de75b514588d1fa025af92c32a7437a5213960e4:product/config/hakoniwa/rulesets/hakoniwa-2s-plus-v9.php`
- Migration: historical `2026_08_17_000000_publish_hakoniwa_2s_plus_v9.php`
- Behavior change: normal monsters resolve after ordinary surface-cell events while reusing
  the existing cell order and consuming no extra shuffle.
- Data change: no separately documented numeric delta found.
- Flavor change: no separately documented flavor delta found.
- Current handling: historical database/Git only; executable PHP retired from current tree.

## v10

- Ruleset key / version: `hakoniwa-2s-plus-v10` / `10`
- Application version: `2.2.1`
- Publication state: published formal production snapshot; immutable historical record
- Checksum: `6a0f5354f8894081bacdb8eaaba328d3e4ee80a2c4136819b16cfa924f485dc1`
- Source: `8c18f16fea01436420a1662e8c37b9e971d31cdc:product/config/hakoniwa/rulesets/hakoniwa-2s-plus-v10.php`
- Migration: historical `2026_08_19_010000_publish_hakoniwa_2s_plus_v10.php`
- Behavior change: food-capacity overflow resolves after population nutrition consumption,
  preserving the stockpile sale/discard policy order.
- Data change: no separately documented balance delta found.
- Flavor change: no separately documented flavor delta found.
- Current handling: historical database/Git only; executable PHP retired from current tree.

## v11

- Ruleset key / version: `hakoniwa-2s-plus-v11` / `11`
- Application version: `2.3.0` (also retained by the 2.3.1 cleanup release)
- Publication state: published formal production snapshot; immutable historical record
- Checksum: `5c65c49ed3fd623375f004815ec6bba0b2f67524f61f0638c6fe528fe9599db8`
- Source: `459b7a274ae64fd5e72647a188e5ee1308d14f05:product/config/hakoniwa/rulesets/hakoniwa-2s-plus-v11.php`
- Migration: historical `2026_08_21_010000_publish_hakoniwa_2s_plus_v11.php`; later ledger/Git
  provenance begins with `2026_08_22_000000_rebaseline_ver_2_4_install_and_upgrade.php`.
- Behavior change: Secretary Old Bow/Ring effects, Aoi Inora/Mecha Inora Zero behavior,
  explicit reward/display-order contracts, and the two-option monster-dispatch selector.
- Data change: item effect amounts, monster HP/rewards/experience, dispatch costs, and display
  order values.
- Flavor change: new monster/item names, descriptions, and manual strings.
- Current handling: historical database/Git only; executable PHP retired from current tree.

## v12

- Ruleset key / version: `hakoniwa-2s-plus-v12` / `12`
- Application version: `2.4.0-beta`
- Publication state: published formal production snapshot; immutable historical record
- Checksum: `cf55370616b56822fe6807f29cdaec6cb0fd3d9bcc12849d3e61df015bdf656e`
- Source: `75f22bc939fe1f51394c1cd1f771abf12fe33cad:product/config/hakoniwa/rulesets/hakoniwa-2s-plus-v12.php`
- Migration: `2026_08_23_000000_add_nation_dormancy_and_publish_v12.php`
- Behavior change: Nation dormancy/lifecycle states, official-Turn transitions, dormant
  protection, finance, territory eligibility, and emergency-farm recovery path.
- Data change: idle/dormancy/abandonment thresholds, manual duration, protection radius, and
  emergency finance amount.
- Flavor change: dormant snow presentation theme.
- Current handling: historical database/Git only; executable PHP retired from current tree.

## v13

- Ruleset key / version: `hakoniwa-2s-plus-v13` / `13`
- Application version: `2.4.0`
- Publication state: published formal production snapshot; immutable historical record
- Checksum: `27c5d58d80e55bf2807cecd147b99b80e57ea0e1afd836eea150982445723b1f`
- Source: `a8c5d582bda541fb277b1ab8dcb97e6589e4ac10:product/config/hakoniwa/rulesets/hakoniwa-2s-plus-v13.php`
- Migration: `2026_08_23_010000_add_nation_karma_and_publish_v13.php`
- Behavior change: recovery lifecycle state, KARMA impact categories/decay, sanctions,
  interception order, and recovery-entry transitions.
- Data change: KARMA bounds, points, decay/recovery amounts, sanction overflow, and alliance
  reward values.
- Flavor change: no separately documented Ruleset flavor delta found.
- Current handling: historical database/Git only; executable PHP retired from current tree.

## v14

- Ruleset key / version: `hakoniwa-2s-plus-v14` / `14`
- Application version: `2.5.0` at the source commit
- Publication state: published formal production snapshot; immutable historical record
- Checksum: `af9afe5bf055f4d2ecc4349de058f6dfc6281194dd3d52238167ced07c9d8274`
- Source: `a4dc5bd1e0ef434a6fae95cf9bdb51d9ff5b7d99:product/config/hakoniwa/rulesets/hakoniwa-2s-plus-v14.php`
- Migration: `2026_08_24_000000_add_secretary_profiles_and_publish_v14.php`
- Behavior change: Secretary level is interpreted as the source for money/food capacity
  multiplication with canonical rounding.
- Data change: money and food capacity increase by one percent per Secretary level, uncapped.
- Flavor change: no separate Ruleset flavor delta; profile text/image presentation is owned
  by application/schema contracts.
- Current handling: historical database/Git only; executable PHP retired from current tree.

## v15

- Ruleset key / version: `hakoniwa-2s-plus-v15` / `15`
- Application version: `2.5.0-beta` at the final v15 source commit
- Publication state: published formal production snapshot; immutable historical record
- Checksum: `d361856e81bb6fe8752a5f1c448d8cbbdb87b6471d5142b36a06b756923fda70`
- Source: `c757918344fb41db4af0ef9ac1da84f361104d4b:product/config/hakoniwa/rulesets/hakoniwa-2s-plus-v15.php`
- Migration: `2026_08_24_010000_add_monster_experience_and_publish_v15.php`
- Behavior change: monster actual-damage experience mapping and Secretary forest-management
  experience/effect interpretation.
- Data change: per-monster `experience_per_damage`, final-blow amount, and one-percent-per-level
  logging/forest-growth inputs.
- Flavor change: forest-management skill name.
- Current handling: historical database/Git only; former v15-to-v16 executable source is
  retired from the current tree.
