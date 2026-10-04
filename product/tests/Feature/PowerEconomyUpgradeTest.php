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
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesTestWorlds;
use Tests\Concerns\UsesIndividualTestWorld;
use Tests\TestCase;

final class PowerEconomyUpgradeTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;
    use UsesIndividualTestWorld;

    /** @return array<string, array{int}> */
    public static function supportedPredecessors(): array
    {
        return ['power introduction' => [27], 'cash maintenance removal' => [28], 'oil and fleet skills' => [29]];
    }

    #[DataProvider('supportedPredecessors')]
    public function test_upgrade_preserves_assets_progression_queue_and_retry_provenance_and_refuses_an_unresolved_turn(int $priorVersion): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, '移行国', '保存島');
        $sourceId = $world->ruleset_version_id;
        $currentId = RulesetVersion::query()->where('version', $priorVersion + 1)->sole()->id;
        $priorSettings = (static fn (): array => require config_path("hakoniwa/rulesets/hakoniwa-2s-plus-v{$priorVersion}.php"))();
        $prior = RulesetVersion::query()->firstOrCreate(['key' => $priorSettings['key']], ['version' => $priorVersion, 'settings' => $priorSettings, 'is_active' => true]);
        $upgrade = static function () use ($priorVersion): void {
            if ($priorVersion === 27) {
                app(PowerEconomyUpgrade::class)->apply();
            } elseif ($priorVersion === 28) {
                app(PowerEconomyUpgrade::class)->removePizzeriaMaintenance();
            } else {
                app(PowerEconomyUpgrade::class)->enableOilAndFleetSkills();
            }
        };
        // Reconstruct the supported predecessor without executing retired authoring.
        foreach ([CommandDefinition::class => 'command_definitions', MonsterDefinition::class => 'monster_definitions', ProductionDefinition::class => 'production_definitions'] as $model => $domain) {
            if (! $model::query()->where('ruleset_version_id', $prior->id)->exists()) {
                foreach ($model::query()->where('ruleset_version_id', $sourceId)->whereIn('key', array_column($priorSettings[$domain], 'key'))->get() as $row) {
                    $row->replicate()->fill(['ruleset_version_id' => $prior->id])->save();
                }
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
        $secretary->skills()->where('skill_key', 'oil_development')->delete();
        $secretary->skills()->where('skill_key', 'agricultural_policy')->update(['level' => 7, 'experience' => 11]);
        if ($priorVersion === 27) {
            $secretary->skills()->where('skill_key', 'energy_saving')->delete();
            NationResource::query()->where('nation_id', $nation->id)->whereHas('definition', fn ($query) => $query->where('key', 'power'))->delete();
        } else {
            $secretary->skills()->where('skill_key', 'energy_saving')->update(['level' => 2, 'experience' => 5]);
            $nation->resourceBalances()->whereHas('definition', fn ($query) => $query->where('key', 'power'))->sole()->update(['amount' => 17]);
        }
        $assets = $nation->fresh()->getAttributes();
        $balances = $nation->resourceBalances()->orderBy('id')->get()->map->getAttributes()->all();
        $savedPrior = $prior->fresh()->getRawOriginal('settings');
        $run = TurnRun::query()->create(['world_id' => $world->id, 'target_turn' => 2, 'ruleset_version_id' => $prior->id,
            'random_seed' => str_repeat('cd', 32), 'source' => 'manual', 'is_dry_run' => false, 'status' => TurnRun::STATUS_FAILED,
            'attempt_count' => 1, 'pipeline' => [], 'phase_results' => [], 'failure_context' => []]);
        try {
            $upgrade();
            $this->fail('An unresolved production Turn must keep its retry identity and block publication.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('Resolve the existing Turn', $error->getMessage());
        }
        $this->assertSame($prior->id, $world->fresh()->ruleset_version_id);
        $this->assertSame($balances, $nation->resourceBalances()->orderBy('id')->get()->map->getAttributes()->all());
        $run->update(['status' => TurnRun::STATUS_COMPLETED]);
        $runBefore = $run->fresh()->getAttributes();
        $upgrade();
        if ($priorVersion === 29) {
            $this->assertSame(['level' => 0, 'experience' => 0], $secretary->skills()->where('skill_key', 'oil_development')->sole()->only(['level', 'experience']));
            $boundary = DB::table('oil_discovery_backfills')->where('world_id', $world->id)->sole();
            $this->assertNull($boundary->applied_at);
        }
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
        $this->assertSame($priorVersion === 27 ? 0 : 17, (int) $power->amount);
        if ($priorVersion === 28) {
            $this->assertSame($balances, $nation->resourceBalances()->orderBy('id')->get()->map->getAttributes()->all());
            $this->assertSame(5, $secretary->skills()->where('skill_key', 'energy_saving')->sole()->experience);
        }
        $power->update(['amount' => 17]);
        $secretary->skills()->where('skill_key', 'energy_saving')->update(['level' => 2, 'experience' => 5]);
        $upgrade();
        $this->assertSame(17, (int) $power->fresh()->amount);
        $this->assertSame(7, $secretary->skills()->where('skill_key', 'agricultural_policy')->sole()->level);
        $this->assertSame(5, $secretary->skills()->where('skill_key', 'energy_saving')->sole()->experience);
    }
}
