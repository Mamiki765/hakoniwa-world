# ver 2.6.1 Ruleset runtime retirement inventory

## Boundary and proof

The current application authors and validates only immutable
`hakoniwa-2s-plus-v16` (`16`, checksum
`331d2d0e9456fa87a37ea0765313ecd9828b5d4912fa2b6637620806df80487d`).
Historical executable PHP is preserved by Git; the Markdown archive is a human index, not a
payload reconstruction or execution source. Historical production rows remain in the
database and continue to support presentation, provenance, idempotency, audit, and fail-closed
historical-World guards.

The deletion proof used for the inventory is:

- production had already completed exact v15-to-v16, its official Turn, and v16 operation;
- the final-v16 schema effects and historical migration ledger are in
  `database/schema/pgsql-schema.sql` for fresh installation;
- fresh install publishes only current v16 and completes World/Nation creation plus an
  official Turn without historical PHP or migration replay;
- applying the remaining migration set to an already-current v16 representative has no
  pending migration and leaves the protected business digest unchanged; and
- repository reference audit finds zero executable references after deletion. Markdown
  commit/path provenance and schema-ledger strings are intentionally not runtime references.

## Historical Ruleset PHP

| File | Former responsibility | Current replacement | Deletion proof | Remaining runtime references |
|---|---|---|---|---:|
| `config/hakoniwa/rulesets/hakoniwa-2s-plus-v1.php` | Formal v1 authoring | Git source + historical DB snapshot | Unsupported executable history | 0 |
| `config/hakoniwa/rulesets/hakoniwa-2s-plus-v2.php` | Formal v2 authoring | Git source + historical DB snapshot | Unsupported executable history | 0 |
| `config/hakoniwa/rulesets/hakoniwa-2s-plus-v3.php` | Formal v3 authoring | Git source + historical DB snapshot | Unsupported executable history | 0 |
| `config/hakoniwa/rulesets/hakoniwa-2s-plus-v4.php` | Formal v4 authoring | Git source + historical DB snapshot | Unsupported executable history | 0 |
| `config/hakoniwa/rulesets/hakoniwa-2s-plus-v5.php` | Formal v5 authoring | Git source + historical DB snapshot | Unsupported executable history | 0 |
| `config/hakoniwa/rulesets/hakoniwa-2s-plus-v6.php` | Formal v6 authoring | Git source + historical DB snapshot | Unsupported executable history | 0 |
| `config/hakoniwa/rulesets/hakoniwa-2s-plus-v7.php` | Formal v7 authoring | Git source + historical DB snapshot | Unsupported executable history | 0 |
| `config/hakoniwa/rulesets/hakoniwa-2s-plus-v8.php` | Formal v8 authoring | Git source + historical DB snapshot | Unsupported executable history | 0 |
| `config/hakoniwa/rulesets/hakoniwa-2s-plus-v9.php` | Formal v9 authoring | Git source + historical DB snapshot | Unsupported executable history | 0 |
| `config/hakoniwa/rulesets/hakoniwa-2s-plus-v10.php` | Formal v10 authoring | Git source + DB-snapshot provenance compatibility | Queue idempotency uses persisted DB identity, not PHP | 0 |
| `config/hakoniwa/rulesets/hakoniwa-2s-plus-v11.php` | Formal v11 authoring | Git source + historical DB snapshot | Current contract moved to v16 owner | 0 |
| `config/hakoniwa/rulesets/hakoniwa-2s-plus-v12.php` | Formal v12 authoring | Git source + historical DB snapshot | Unsupported executable history | 0 |
| `config/hakoniwa/rulesets/hakoniwa-2s-plus-v13.php` | Formal v13 authoring | Git source + historical DB snapshot | Unsupported executable history | 0 |
| `config/hakoniwa/rulesets/hakoniwa-2s-plus-v14.php` | Formal v14 authoring | Git source + historical DB snapshot | Unsupported executable history | 0 |
| `config/hakoniwa/rulesets/hakoniwa-2s-plus-v15.php` | Formal v15 authoring and former v16 source | Git source + historical DB snapshot | Supported production source is already v16 | 0 |
| `config/hakoniwa/rulesets/roadmap-pr2-v1.php` | Roadmap snapshot | Recorded Git source | Never a current production source | 0 |
| `config/hakoniwa/rulesets/roadmap-pr6-v1.php` | Roadmap snapshot | Recorded Git source | Never a current production source | 0 |
| `config/hakoniwa/rulesets/roadmap-pr7-v1.php` | Roadmap snapshot | Recorded Git source | Never a current production source | 0 |
| `config/hakoniwa/rulesets/roadmap-pr11-v1.php` | Roadmap snapshot | Recorded Git source | Never a current production source | 0 |
| `config/hakoniwa/rulesets/roadmap-pr14-v1.php` | Roadmap snapshot | Recorded Git source | Never a current production source | 0 |
| `config/hakoniwa/rulesets/roadmap-pr15-v1.php` | Roadmap snapshot | Recorded Git source | Never a current production source | 0 |
| `config/hakoniwa/rulesets/roadmap-pr18-v1.php` | Roadmap snapshot | Recorded Git source | Never a current production source | 0 |
| `config/hakoniwa/rulesets/roadmap-pr19-v1.php` | Roadmap snapshot | Recorded Git source | Never a current production source | 0 |
| `config/hakoniwa/rulesets/roadmap-pr21-v1.php` | Roadmap snapshot | Recorded Git source | Never a current production source | 0 |
| `config/hakoniwa/rulesets/roadmap-pr22-v1.php` | Roadmap snapshot | Recorded Git source | Never a current production source | 0 |

## Catalog, bootstrap, and upgrade services

| File or binding | Former responsibility | Current replacement | Deletion proof | Remaining runtime references |
|---|---|---|---|---:|
| `app/Domain/Ruleset/RulesetAuthoringCollection.php` | Load/deduplicate multiple authored payload files | Single configured current v16 payload | No multi-version authoring consumer remains | 0 |
| `app/Domain/Ruleset/RulesetUpgradeAuthoringCatalog.php` | Resolve historical PHP by key | Git for old source; current config for v16 | Validator/tests are current-only or use DB snapshots | 0 |
| `AppServiceProvider` historical-catalog singleton binding | Install catalog into application/test bootstrap | No binding; current config is canonical | Reference audit found no listener/concern beyond this binding | 0 |
| Test database migration bootstrap | Historical publication migrations incidentally seeded persistent test catalogs | `tests/TestCase.php` installs current catalogs and publishes current v16 after a completed or no-op schema migration | Test databases no longer replay historical publication migrations | 0 historical PHP |
| `app/Application/Ver240InstallUpgradeRebaseline.php` | v11 baseline install/source assertion | Final-v16 schema dump + current catalog installer/publisher | Fresh and already-current proofs | 0 |
| `app/Application/Ver240DormancyRulesetUpgrade.php` | Exact v11-to-v12 conversion | Git release source only | Production already crossed; schema/data current at v16 | 0 |
| `app/Application/Ver240KarmaRecoveryRulesetUpgrade.php` | Exact v12-to-v13 conversion | Git release source only | Production already crossed; `-30..100` baseline | 0 |
| `app/Application/Ver250SecretaryProfileRulesetUpgrade.php` | Exact v13-to-v14 conversion | Git release source only | Profile schema is in final dump | 0 |
| `app/Application/Ver250MonsterExperienceRulesetUpgrade.php` | Exact v14-to-v15 conversion | Git release source only | Monster EXP schema is in final dump | 0 |
| `app/Application/Ver260OilResourceRulesetUpgrade.php` | Exact v15-to-v16 conversion | ver 2.6.0 Git release when needed | Supported 2.6.1 source is already v16 | 0 |

No dedicated `MigrationsStarted` listener or test concern installed the historical catalog
at the Stage 2 baseline; the provider singleton above was the only application bootstrap
registration. The replacement listener is test-only and reads only the configured current v16
after the schema baseline has loaded.

## Retired migration files

| File | Former responsibility | Current replacement | Deletion proof | Remaining runtime references |
|---|---|---|---|---:|
| `database/migrations/2026_08_22_000000_rebaseline_ver_2_4_install_and_upgrade.php` | v11 install/upgrade rebaseline | Final-v16 dump/current publisher; ledger retained | Fresh baseline + already-v16 source | 0 |
| `database/migrations/2026_08_23_000000_add_nation_dormancy_and_publish_v12.php` | Dormancy schema and v12 publication | Final-v16 dump; Git migration source | Schema effect and ledger retained | 0 |
| `database/migrations/2026_08_23_010000_add_nation_karma_and_publish_v13.php` | KARMA schema and v13 publication | Final-v16 dump; Git migration source | Current KARMA constraint and ledger retained | 0 |
| `database/migrations/2026_08_24_000000_add_secretary_profiles_and_publish_v14.php` | Profile schema and v14 publication | Final-v16 dump; Git migration source | Profile schema and ledger retained | 0 |
| `database/migrations/2026_08_24_010000_add_monster_experience_and_publish_v15.php` | Monster EXP schema and v15 publication | Final-v16 dump; Git migration source | EXP schema and ledger retained | 0 |
| `database/migrations/2026_08_25_000000_add_oil_resource_and_publish_v16.php` | Auction/escrow/KARMA schema and v16 conversion | Final-v16 dump; ver 2.6.0 Git source | Production already v16; ledger retained | 0 |

## Test ownership retirement

| File | Former responsibility | Current replacement | Deletion proof | Remaining runtime references |
|---|---|---|---|---:|
| `tests/Unit/RulesetV11ContractTest.php` | Historical authoring plus mixed current contracts | `tests/Unit/CurrentRulesetContractTest.php` | Current contract green before deletion | 0 |
| `tests/Feature/Ver240InstallUpgradeRebaselineTest.php` | Historical upgrade chain plus current-v16/Turn contracts | `tests/Feature/FreshInstallRebaselineTest.php` | Current fresh/already-current/Turn contracts green | 0 |
| `tests/Support/V11SecretaryItemRulesetFixture.php` | Executable v11-derived test authoring | `CurrentRulesetFixture` and synthetic persisted DB snapshots | Current tests need no historical PHP | 0 |
