# Roadmap Ruleset history

Roadmap Rulesets are immutable development-stage artifacts. They are not formal production
Rulesets and must not be mixed into the `hakoniwa-2s-plus-v1` through v16 production history.
The current runtime does not use them. Their PHP and upgrade-catalog path have been retired
from the current tree; the recorded Git commit/path is the executable authority.

| Artifact | Development stage represented | Git source reference | Historical publication evidence | Current handling |
|---|---|---|---|---|
| `roadmap-pr2-v1` | First typed shared-world payload: bounds, resources, facilities, commands, production, and initial-island contract | `abf4fced953c367631539c9df6a8c33df5416f58:product/config/hakoniwa/rulesets/roadmap-pr2-v1.php` | `2026_07_26_010000_add_roadmap_pr2_systems.php` | Non-formal; never normal current runtime |
| `roadmap-pr6-v1` | Resource/initial-balance and command expansion, plan quantity, shallow-cell minimum | `abf4fced953c367631539c9df6a8c33df5416f58:product/config/hakoniwa/rulesets/roadmap-pr6-v1.php` | `2026_07_27_010000_publish_roadmap_pr6_ruleset.php` | Non-formal; never normal current runtime |
| `roadmap-pr7-v1` | Base money/food capacities and inventory sale rates | `abf4fced953c367631539c9df6a8c33df5416f58:product/config/hakoniwa/rulesets/roadmap-pr7-v1.php` | `2026_07_29_000000_publish_roadmap_pr7_ruleset.php` | Non-formal; never normal current runtime |
| `roadmap-pr11-v1` | Queue limit, facility/command/production definitions, sale rates, and economic turn loop | `8de6b4fd38e9f553ddb0f8496a1a76879df1dbbf:product/config/hakoniwa/rulesets/roadmap-pr11-v1.php` | `2026_07_30_000000_publish_roadmap_pr11_ruleset.php` | Non-formal; never normal current runtime |
| `roadmap-pr14-v1` | Terrain, seabed-oil/reclaim command, facility, and turn-processing contracts | `d94a8385cd179f2bba06ee088db776a0ce1490fa:product/config/hakoniwa/rulesets/roadmap-pr14-v1.php` | `2026_08_02_000000_publish_roadmap_pr14_ruleset.php` | Non-formal; never normal current runtime |
| `roadmap-pr15-v1` | Capital growth/damage and non-monster-disaster turn processing | `ad41ca8f3bd1bbeeffafb5bba5fc78f10429eb45:product/config/hakoniwa/rulesets/roadmap-pr15-v1.php` | `2026_08_02_010000_publish_roadmap_pr15_ruleset.php` | Non-formal; never normal current runtime |
| `roadmap-pr18-v1` | Nation land subsidence and historical-World safeguard stage | `21e40ad1bff5af5ec732ce24836aff40ccb16f8b:product/config/hakoniwa/rulesets/roadmap-pr18-v1.php` | `2026_08_04_000000_publish_roadmap_pr18_ruleset.php` | Non-formal; never normal current runtime |
| `roadmap-pr19-v1` | Resource definitions, per-resource capacities, and overflow policy | `0f7c62d5e5390d64d36a88d00663eaa5a9390848:product/config/hakoniwa/rulesets/roadmap-pr19-v1.php` | `2026_08_04_020000_publish_roadmap_pr19_ruleset.php` | Non-formal; never normal current runtime |
| `roadmap-pr21-v1` | Shared-world monster definitions and monster-system settings | `b6a3a6086adae27d5786fedeb1ca80564d81a2c0:product/config/hakoniwa/rulesets/roadmap-pr21-v1.php` | `2026_08_05_000000_create_monster_system_and_publish_roadmap_pr21_ruleset.php` | Non-formal; never normal current runtime |
| `roadmap-pr22-v1` | Command/missile/facility/event contract and final roadmap predecessor of formal v1 | `54cd8a655490f77b33843922be530d24e0bd8329:product/config/hakoniwa/rulesets/roadmap-pr22-v1.php` | `2026_08_05_010000_add_pr22_command_event_state_and_publish_ruleset.php` | Non-formal; never normal current runtime |

The historical checksums and fuller pre-v11 lineage remain available in
`../ruleset-history.md` and the listed Git references. They are evidence, not a supported
runtime restoration mechanism.
