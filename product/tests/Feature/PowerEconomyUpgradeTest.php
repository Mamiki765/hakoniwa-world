<?php

namespace Tests\Feature;

use App\Application\CommandQueueService;
use App\Application\NationCreationService;
use App\Application\PowerEconomyUpgrade;
use App\Models\CommandDefinition;
use App\Models\MonsterDefinition;
use App\Models\MonsterInstance;
use App\Models\NationMonsterKillStat;
use App\Models\NationResource;
use App\Models\ProductionDefinition;
use App\Models\RulesetVersion;
use App\Models\TurnRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\CreatesTestWorlds;
use Tests\Concerns\UsesIndividualTestWorld;
use Tests\TestCase;

final class PowerEconomyUpgradeTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;
    use UsesIndividualTestWorld;

    public function test_upgrade_preserves_assets_progression_queue_and_retry_provenance_and_refuses_an_unresolved_turn(): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, '移行国', '保存島');
        $currentId = $world->ruleset_version_id;
        $priorSettings = require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v27.php');
        $prior = RulesetVersion::query()->create(['key' => $priorSettings['key'], 'version' => 27, 'settings' => $priorSettings, 'is_active' => true]);
        // Reconstruct the supported predecessor without executing retired authoring.
        foreach ([CommandDefinition::class => 'command_definitions', MonsterDefinition::class => 'monster_definitions', ProductionDefinition::class => 'production_definitions'] as $model => $domain) {
            foreach ($model::query()->where('ruleset_version_id', $currentId)->whereIn('key', array_column($priorSettings[$domain], 'key'))->get() as $row) {
                $row->replicate()->fill(['ruleset_version_id' => $prior->id])->save();
            }
        }
        $cell = $nation->capital()->sole()->cell()->sole();
        $queue = app(CommandQueueService::class)->add($user, $nation, $this->surfaceMapSpace($world), 'finance', $cell->x, $cell->y, (string) Str::uuid(), 1);
        $world->update(['ruleset_version_id' => $prior->id]);
        $oldCommand = CommandDefinition::query()->where('ruleset_version_id', $prior->id)->where('key', 'finance')->sole();
        $queue['item']->update(['command_definition_id' => $oldCommand->id, 'request_ruleset_version_id' => $prior->id]);
        $oldMonster = MonsterDefinition::query()->where('ruleset_version_id', $prior->id)->orderBy('id')->firstOrFail();
        $monster = MonsterInstance::query()->create(['world_id' => $world->id, 'monster_definition_id' => $oldMonster->id,
            'current_hp' => 3, 'spawned_max_hp' => 4, 'state' => 'alive', 'spawned_target_turn' => 1, 'version' => 1]);
        $stat = NationMonsterKillStat::query()->create(['world_id' => $world->id, 'nation_id' => $nation->id, 'monster_definition_id' => $oldMonster->id,
            'kill_count' => 1, 'first_killed_turn' => 1, 'last_killed_turn' => 1, 'version' => 1]);
        $secretary = $user->secretary()->sole();
        $secretary->skills()->where('skill_key', 'agricultural_policy')->update(['level' => 7, 'experience' => 11]);
        $secretary->skills()->where('skill_key', 'energy_saving')->delete();
        NationResource::query()->where('nation_id', $nation->id)->whereHas('definition', fn ($query) => $query->where('key', 'power'))->delete();
        $assets = $nation->fresh()->getAttributes();
        $balances = $nation->resourceBalances()->orderBy('id')->get()->map->getAttributes()->all();
        $savedPrior = $prior->fresh()->getRawOriginal('settings');
        $run = TurnRun::query()->create(['world_id' => $world->id, 'target_turn' => 2, 'ruleset_version_id' => $prior->id,
            'random_seed' => str_repeat('cd', 32), 'source' => 'manual', 'is_dry_run' => false, 'status' => TurnRun::STATUS_FAILED,
            'attempt_count' => 1, 'pipeline' => [], 'phase_results' => [], 'failure_context' => []]);
        try {
            app(PowerEconomyUpgrade::class)->apply();
            $this->fail('An unresolved production Turn must keep its retry identity and block publication.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('Resolve the existing Turn', $error->getMessage());
        }
        $this->assertSame($prior->id, $world->fresh()->ruleset_version_id);
        $this->assertSame($balances, $nation->resourceBalances()->orderBy('id')->get()->map->getAttributes()->all());
        $run->update(['status' => TurnRun::STATUS_COMPLETED]);
        $runBefore = $run->fresh()->getAttributes();
        app(PowerEconomyUpgrade::class)->apply();
        $this->assertSame($currentId, $world->fresh()->ruleset_version_id);
        $this->assertSame($assets, $nation->fresh()->getAttributes());
        $this->assertSame($savedPrior, $prior->fresh()->getRawOriginal('settings'));
        $this->assertSame($runBefore, $run->fresh()->getAttributes());
        $this->assertSame($prior->id, $queue['item']->fresh()->request_ruleset_version_id);
        $this->assertSame($currentId, $queue['item']->fresh()->definition->ruleset_version_id);
        $this->assertSame('queued', $queue['item']->fresh()->status);
        $this->assertSame(3, $monster->fresh()->current_hp);
        $this->assertSame($oldMonster->key, $monster->fresh()->definition->key);
        $this->assertSame($currentId, $monster->fresh()->definition->ruleset_version_id);
        $this->assertSame(1, $stat->fresh()->kill_count);
        $this->assertSame($currentId, $stat->fresh()->definition->ruleset_version_id);
        $stat->refresh()->update(['kill_count' => 2, 'last_killed_turn' => 2, 'version' => 2]);
        $this->assertSame(2, $stat->fresh()->kill_count);
        $this->assertSame('O', DB::selectOne("SELECT tgenabled FROM pg_trigger WHERE tgname = 'nation_monster_kill_stat_guard'")->tgenabled);
        $power = $nation->resourceBalances()->whereHas('definition', fn ($query) => $query->where('key', 'power'))->sole();
        $this->assertSame(0, (int) $power->amount);
        $power->update(['amount' => 17]);
        $secretary->skills()->where('skill_key', 'energy_saving')->update(['level' => 2, 'experience' => 5]);
        app(PowerEconomyUpgrade::class)->apply();
        $this->assertSame(17, (int) $power->fresh()->amount);
        $this->assertSame(7, $secretary->skills()->where('skill_key', 'agricultural_policy')->sole()->level);
        $this->assertSame(5, $secretary->skills()->where('skill_key', 'energy_saving')->sole()->experience);
    }
}
