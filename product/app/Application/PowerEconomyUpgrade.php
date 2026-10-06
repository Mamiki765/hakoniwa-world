<?php

namespace App\Application;

use App\Domain\World\WorldMutationLock;
use App\Models\ResourceDefinition;
use App\Models\RulesetVersion;
use App\Models\TurnRun;
use App\Models\World;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Forward-only Ruleset releases; never rewrites saved Turn or Ship provenance. */
final class PowerEconomyUpgrade
{
    public function __construct(
        private readonly CurrentCatalogInstaller $catalogs,
        private readonly RulesetPublisher $publisher,
        private readonly WorldMutationLock $lock,
    ) {}

    public function apply(): void
    {
        // Historical migrations must keep their exact payload after current advances.
        $settings = require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v28.php');
        $priorSettings = require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v27.php');
        $this->publish($settings, $priorSettings, true);
    }

    public function removePizzeriaMaintenance(): void
    {
        $settings = require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v29.php');
        $priorSettings = require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v28.php');
        $this->publish($settings, $priorSettings, false);
    }

    public function enableOilAndFleetSkills(): void
    {
        $settings = require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v30.php');
        $priorSettings = require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v29.php');
        $this->publish($settings, $priorSettings, false, true);
    }

    public function enableSeaAreaWeather(): void
    {
        $settings = require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v31.php');
        $priorSettings = require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v30.php');
        $this->publish($settings, $priorSettings, false);
    }

    public function correctNaturalFireTargets(): void
    {
        $settings = require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v32.php');
        $priorSettings = require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v31.php');
        $this->publish($settings, $priorSettings, false);
    }

    public function enableUserAchievements(): void
    {
        $settings = require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v33.php');
        $priorSettings = require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v32.php');
        $this->publish($settings, $priorSettings, false);
    }

    /** @param array<string, mixed> $settings
     * @param  array<string, mixed>  $priorSettings
     */
    private function publish(array $settings, array $priorSettings, bool $initializePower, bool $initializeOilAndFleet = false): void
    {
        $prior = RulesetVersion::query()->where('key', $priorSettings['key'])->first();
        if ($prior !== null && ! RulesetVersion::query()->whereKey($prior->id)
            ->whereRaw('settings = ?::jsonb', [json_encode($priorSettings, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)])->exists()) {
            throw new RuntimeException('The saved predecessor differs from the accepted snapshot.');
        }
        if ($settings['monster_definitions'] !== $priorSettings['monster_definitions']
            || array_slice($settings['command_definitions'], 0, count($priorSettings['command_definitions'])) !== $priorSettings['command_definitions']) {
            throw new RuntimeException('This migration only remaps unchanged monster and existing command definitions.');
        }
        $worlds = $prior === null ? collect() : World::query()->where('ruleset_version_id', $prior->id)->orderBy('id')->get();
        $held = [];
        try {
            foreach ($worlds as $world) {
                $this->lock->acquire($world);
                $held[] = $world;
            }
            DB::transaction(function () use ($settings, $prior, $worlds, $initializePower, $initializeOilAndFleet): void {
                foreach ($worlds as $world) {
                    $this->lock->assertHeld($world);
                    $locked = World::query()->whereKey($world->id)->lockForUpdate()->firstOrFail();
                    if ($locked->ruleset_version_id !== $prior?->id
                        || TurnRun::query()->where('world_id', $world->id)->unresolvedProduction()->exists()) {
                        throw new RuntimeException('Resolve the existing Turn before upgrading its World; no retry identity is changed.');
                    }
                }
                $this->catalogs->install($settings);
                $current = match ($settings['version']) {
                    28 => $this->publisher->publishPowerIntroduction($settings),
                    29 => $this->publisher->publishPizzeriaMaintenanceRemoval($settings),
                    30 => $this->publisher->publishOilAndFleetSkills($settings),
                    31 => $this->publisher->publishSeaAreaWeather($settings),
                    32 => $this->publisher->publishNaturalFireTargets($settings),
                    default => $this->publisher->publish($settings),
                };
                if ($initializePower) {
                    DB::statement('ALTER TABLE secretary_skills DROP CONSTRAINT secretary_skills_key_check');
                    DB::statement("ALTER TABLE secretary_skills ADD CONSTRAINT secretary_skills_key_check CHECK (skill_key IN ('agricultural_policy', 'specialty_development', 'gold_vein_survey', 'forest_management', 'final_defense_line', 'declining_birthrate_policy', 'indomitable', 'ship_operations', 'navy', 'energy_saving'))");
                    DB::table('secretary_skills')->insertUsing(
                        ['secretary_id', 'skill_key', 'level', 'experience', 'created_at', 'updated_at'],
                        DB::table('secretaries as secretary')->selectRaw("secretary.id, 'energy_saving', 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP")
                            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('secretary_skills as skill')
                                ->whereColumn('skill.secretary_id', 'secretary.id')->where('skill.skill_key', 'energy_saving')),
                    );
                }
                if ($initializeOilAndFleet) {
                    DB::statement('ALTER TABLE secretary_skills DROP CONSTRAINT secretary_skills_key_check');
                    DB::statement("ALTER TABLE secretary_skills ADD CONSTRAINT secretary_skills_key_check CHECK (skill_key IN ('agricultural_policy', 'specialty_development', 'gold_vein_survey', 'oil_development', 'forest_management', 'final_defense_line', 'declining_birthrate_policy', 'indomitable', 'ship_operations', 'navy', 'energy_saving'))");
                    DB::table('secretary_skills')->insertUsing(
                        ['secretary_id', 'skill_key', 'level', 'experience', 'created_at', 'updated_at'],
                        DB::table('secretaries as secretary')->selectRaw("secretary.id, 'oil_development', 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP")
                            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('secretary_skills as skill')
                                ->whereColumn('skill.secretary_id', 'secretary.id')->where('skill.skill_key', 'oil_development')),
                    );
                    foreach ($worlds as $world) {
                        DB::table('oil_discovery_backfills')->insertOrIgnore([
                            'world_id' => $world->id,
                            'cutoff_turn' => $world->current_turn,
                            'cutoff_audit_id' => DB::table('audit_events')->where('world_id', $world->id)->max('id') ?? 0,
                        ]);
                    }
                }
                foreach ($worlds as $world) {
                    $world->update(['ruleset_version_id' => $current->id]);
                    DB::update(<<<'SQL'
UPDATE monster_instances AS instance
SET monster_definition_id = current.id
FROM monster_definitions AS old, monster_definitions AS current
WHERE instance.world_id = ? AND instance.monster_definition_id = old.id
  AND old.ruleset_version_id = ? AND current.ruleset_version_id = ? AND current.key = old.key
SQL, [$world->id, $prior?->id, $current->id]);
                    // The ordinary guard forbids changing the identity or count of
                    // a stat. This exact migration only rebinds identical definitions,
                    // under an exclusive table lock, without touching any progression.
                    // PostgreSQL rolls back this ALTER too if publication fails.
                    DB::statement('ALTER TABLE nation_monster_kill_stats DISABLE TRIGGER nation_monster_kill_stat_guard');
                    DB::update(<<<'SQL'
UPDATE nation_monster_kill_stats AS stat
SET monster_definition_id = current.id
FROM monster_definitions AS old, monster_definitions AS current
WHERE stat.world_id = ? AND stat.monster_definition_id = old.id
  AND old.ruleset_version_id = ? AND current.ruleset_version_id = ? AND current.key = old.key
SQL, [$world->id, $prior?->id, $current->id]);
                    DB::statement('ALTER TABLE nation_monster_kill_stats ENABLE TRIGGER nation_monster_kill_stat_guard');
                    DB::update(<<<'SQL'
UPDATE nation_command_queue_items AS item
SET command_definition_id = current.id
FROM nation_command_queues AS queue, nations AS nation,
     command_definitions AS old, command_definitions AS current
WHERE item.nation_command_queue_id = queue.id AND queue.nation_id = nation.id
  AND nation.world_id = ? AND item.status = 'queued' AND item.target_context = 'surface_cell'
  AND item.command_definition_id = old.id AND old.ruleset_version_id = ?
  AND current.ruleset_version_id = ? AND current.key = old.key
SQL, [$world->id, $prior?->id, $current->id]);
                }
                if ($initializePower) {
                    $power = ResourceDefinition::query()->where('key', 'power')->sole();
                    DB::table('nation_resources')->insertUsing(
                        ['nation_id', 'resource_definition_id', 'amount', 'created_at', 'updated_at'],
                        DB::table('nations as nation')->selectRaw('nation.id, ?, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP', [$power->id])
                            ->whereIn('nation.world_id', World::query()->where('ruleset_version_id', $current->id)->select('id'))
                            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('nation_resources as balance')
                                ->whereColumn('balance.nation_id', 'nation.id')->where('balance.resource_definition_id', $power->id)),
                    );
                }
            }, 1);
        } finally {
            foreach (array_reverse($held) as $world) {
                $this->lock->release($world);
            }
        }
    }
}
