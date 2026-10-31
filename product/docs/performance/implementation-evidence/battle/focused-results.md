# Focused execution record

Source worktree: /workspace/hakoniwa-perf-battle
Base: 2e93ec918774ad64d787aba99fc59a7bc3e54d16
Execution: perf-impl-php-battle-20261031, PHP 8.5.8, isolated PG18.4 supplied by root, product source read-only in container.

Observed successful checks:

- `php -l app/Application/Underground/UndergroundAlphaV1PlayerCatalog.php`: no syntax errors.
- `php vendor/bin/phpunit tests/Underground/Unit/UndergroundRuntimeCatalogPartyAuthoringTest.php`: 7 tests / 39 assertions, exit 0.
- `php vendor/bin/phpunit --filter 'test_same_build_equipment_and_seed_replay_exactly|test_weapon_followup_occurs_once_after_a_multihit_area_action_and_replays_with_the_snapshot|test_party_exploration_pins_borrowed_snapshot_keeps_owner_state_and_settles_one_reward_authority|test_trial_three_solo_party_encounters_keep_composition_on_retry_and_saved_replay|test_bulk_hunting_skip_is_one_idempotent_operation_with_distinct_drop_identity' tests/Underground`: 5 tests / 98 assertions, exit 0.
- `php tests/scripts/prepare_phpstan_schema.php`, then `php vendor/bin/phpstan analyse --configuration=/evidence/phpstan-focused.neon --no-progress --memory-limit=1G app/Application/Underground/UndergroundAlphaV1PlayerCatalog.php app/Providers/AppServiceProvider.php`: level 6/Larastan, no errors, exit 0.
- Pint final log is pint-final.log: 3 files passed, exit 0.
- Host `git diff --check`: exit 0.
- `php /evidence/catalog-benchmark.php before` and `after`: each exit 0; all four output hashes match in catalog-benchmark-comparison.json.

Earlier checks: Pint found indentation/unary-space style and the formatted copy was applied; final Pint passed. PHPStan first used a missing default config at level 0 and failed at 128M; this was superseded by the explicit repository-equivalent level 6/Larastan configuration above. No full suite or CI was run.

This is a concise record of observed tool output, not a claim of production deployment or a raw combined process log.
