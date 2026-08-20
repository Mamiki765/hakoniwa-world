<?php

namespace Tests\Feature;

use App\Application\NationAbandonmentService;
use App\Application\NationCreationService;
use App\Domain\Nation\NationAbandonmentConflictException;
use App\Domain\Nation\NationCreationConflictException;
use App\Models\MapCell;
use App\Models\Nation;
use App\Models\TurnRun;
use App\Models\User;
use App\Models\World;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

final class NationMembershipTurnGuardTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    #[DataProvider('unresolvedTurnStatuses')]
    public function test_registration_rejects_each_unresolved_next_turn_before_any_membership_or_game_state_write(
        string $status,
    ): void {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $this->turnRun($world, $status);
        $before = $this->registrationState($world, $user);

        try {
            app(NationCreationService::class)->create($user, $world, "登録拒否{$status}島", '登録拒否島主');
            $this->fail("Expected {$status} TurnRun to block Nation registration.");
        } catch (NationCreationConflictException $exception) {
            $this->assertSame('nation_creation_turn_unresolved', $exception->errorCode);
        }

        $this->assertSame($before, $this->registrationState($world, $user));
    }

    #[DataProvider('unresolvedTurnStatuses')]
    public function test_abandonment_rejects_each_unresolved_next_turn_before_any_lifecycle_write(string $status): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, "破棄拒否{$status}島", '破棄拒否島主');
        $this->turnRun($world, $status);
        $before = $this->abandonmentState($nation);

        try {
            app(NationAbandonmentService::class)->abandon($user, $nation, $nation->name);
            $this->fail("Expected {$status} TurnRun to block Nation abandonment.");
        } catch (NationAbandonmentConflictException $exception) {
            $this->assertSame('nation_abandonment_turn_unresolved', $exception->errorCode);
        }

        $this->assertSame($before, $this->abandonmentState($nation));
    }

    public function test_completed_next_turn_record_allows_existing_registration_and_abandonment_behavior(): void
    {
        $world = $this->lightweightWorld();
        $this->turnRun($world, TurnRun::STATUS_COMPLETED);
        $user = User::factory()->create();

        $nation = app(NationCreationService::class)->create($user, $world, '解決済登録島', '解決済島主');
        $this->assertSame('active', $nation->state);
        $result = app(NationAbandonmentService::class)->abandon($user, $nation, $nation->name);

        $this->assertSame('abandoned', $result['state']);
        $this->assertDatabaseMissing('nation_memberships', ['nation_id' => $nation->id]);
    }

    /** @return array<string, array{string}> */
    public static function unresolvedTurnStatuses(): array
    {
        return [
            'pending' => [TurnRun::STATUS_PENDING],
            'running' => [TurnRun::STATUS_RUNNING],
            'failed' => [TurnRun::STATUS_FAILED],
            'blocked' => [TurnRun::STATUS_BLOCKED],
        ];
    }

    /** @return array<string, mixed> */
    private function registrationState(World $world, User $user): array
    {
        return [
            'nations' => Nation::query()->where('world_id', $world->id)->count(),
            'memberships' => DB::table('nation_memberships')->where('user_id', $user->id)->count(),
            'requests' => DB::table('nation_creation_requests')->where('user_id', $user->id)->count(),
            'secretaries' => DB::table('secretaries')->where('user_id', $user->id)->count(),
            'items' => DB::table('secretary_item_instances')
                ->whereIn('secretary_id', DB::table('secretaries')->where('user_id', $user->id)->select('id'))
                ->count(),
            'owned_cells' => MapCell::query()->whereIn(
                'map_space_id',
                DB::table('map_spaces')->where('world_id', $world->id)->select('id'),
            )
                ->whereNotNull('owner_nation_id')->count(),
            'events' => DB::table('audit_events')->where('actor_user_id', $user->id)->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function abandonmentState(Nation $nation): array
    {
        $cells = MapCell::query()
            ->where('owner_nation_id', $nation->id)
            ->orderBy('id')
            ->get(['id', 'terrain_definition_id', 'facility_definition_id', 'owner_nation_id', 'population', 'version'])
            ->map(static fn (MapCell $cell): array => $cell->getAttributes())
            ->all();

        $freshNation = $nation->fresh();

        return [
            'nation' => array_intersect_key(
                $freshNation?->getAttributes() ?? [],
                array_flip(['state', 'money', 'idle_counter', 'updated_at']),
            ),
            'membership' => $this->rows('nation_memberships', $nation->id),
            'capital' => $this->row('nation_capitals', $nation->id),
            'resources' => $this->rows('nation_resources', $nation->id),
            'policies' => $this->rows('nation_resource_sale_policies', $nation->id),
            'queue' => $this->row('nation_command_queues', $nation->id),
            'cells' => $cells,
            'monsters' => DB::table('monster_instances')->where('world_id', $nation->world_id)->orderBy('id')->get()
                ->map(static fn (object $row): array => (array) $row)->all(),
            'events' => $this->rows('audit_events', $nation->id),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $table, int $nationId): array
    {
        return DB::table($table)->where('nation_id', $nationId)->orderBy('id')->get()
            ->map(static fn (object $row): array => (array) $row)->all();
    }

    /** @return array<string, mixed>|null */
    private function row(string $table, int $nationId): ?array
    {
        $row = DB::table($table)->where('nation_id', $nationId)->first();

        return $row === null ? null : (array) $row;
    }

    private function turnRun(World $world, string $status): TurnRun
    {
        return TurnRun::query()->create([
            'world_id' => $world->id,
            'target_turn' => $world->current_turn + 1,
            'ruleset_version_id' => $world->ruleset_version_id,
            'random_seed' => str_repeat('f', 64),
            'source' => 'manual',
            'is_dry_run' => false,
            'status' => $status,
            'attempt_count' => 1,
            'pipeline' => [],
            'phase_results' => [],
            'failure_context' => [],
        ]);
    }
}
