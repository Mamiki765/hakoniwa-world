<?php

namespace App\Application;

use App\Domain\Monster\MonsterDisplayOrderResolver;
use App\Domain\World\HakoniwaCalendar;
use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\MonsterDefinition;
use App\Models\Nation;
use App\Models\NationMonsterKillStat;
use App\Models\World;
use App\Support\MoneyFormatter;
use DomainException;
use Illuminate\Support\Collection;

final class PublicWorldService
{
    public function __construct(
        private readonly MoneyFormatter $money,
        private readonly NationBasicStatusProjection $basicStatus,
        private readonly TurnScheduleStatus $turnSchedule,
        private readonly PublicRankingAchievementProjection $achievements,
        private readonly HakoniwaCalendar $calendar,
        private readonly MonsterDisplayOrderResolver $monsterDisplayOrders,
    ) {}

    /** @return array<string, mixed> */
    public function summary(World $world): array
    {
        $turnSchedule = $this->turnSchedule->forWorld($world);

        return [
            'id' => $world->id,
            'key' => $world->key,
            'name' => $world->name,
            'current_turn' => $world->current_turn,
            'hakoniwa_calendar' => $this->calendar->forTurn((int) $world->current_turn),
            'nation_count' => $world->nations()->whereIn('state', ['active', 'dormant', 'recovery'])->count(),
            'contact_url' => $this->contactUrl(),
            'turn_status' => $turnSchedule['status'],
            'last_successful_turn_at' => $turnSchedule['last_successful_turn_at'],
            'next_scheduled_turn_at' => $turnSchedule['next_scheduled_turn_at'],
            'turn_schedule_timezone' => $turnSchedule['timezone'],
            'total_population' => (int) MapCell::query()
                ->whereIn('map_space_id', MapSpace::query()->select('id')->where('world_id', $world->id))
                ->sum('population'),
        ];
    }

    private function contactUrl(): ?string
    {
        $url = config('hakoniwa.community.contact_url');
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return in_array(parse_url($url, PHP_URL_SCHEME), ['https', 'http'], true) ? $url : null;
    }

    /** @return Collection<int, non-empty-array<string, mixed>> */
    public function rankings(World $world): Collection
    {
        $nations = $this->rankedNations($world)->values();
        $achievements = $this->achievements->forWorld($world, $nations);

        return $nations->map(
            fn (Nation $nation, int $index): array => [
                'rank' => $index + 1,
                ...$this->publicNationFields($nation, $world),
                'achievements' => $achievements[$nation->id],
            ],
        );
    }

    /** @return array<string, mixed> */
    public function nation(Nation $nation): array
    {
        $world = $nation->world()->firstOrFail();
        $nation = $this->nationWithPublicAggregates($nation);
        $mapSpace = MapSpace::query()
            ->where('world_id', $nation->world_id)
            ->where('key', config('hakoniwa.world.map_space_key'))
            ->firstOrFail();
        $capital = $nation->capital()->first();
        $monsterKillRows = NationMonsterKillStat::query()
            ->where('world_id', $nation->world_id)
            ->where('nation_id', $nation->id)
            ->where('kill_count', '>', 0)
            ->with('definition')
            ->get();
        $definitions = $monsterKillRows->map(function (NationMonsterKillStat $stat) use ($world): MonsterDefinition {
            $definition = $stat->definition;
            if (! $definition instanceof MonsterDefinition || $definition->ruleset_version_id !== $world->ruleset_version_id) {
                throw new DomainException('Public monster statistic references a missing or cross-ruleset definition.');
            }

            return $definition;
        });
        $orders = $this->monsterDisplayOrders->uniqueOrders($definitions);
        $monsterKillStats = $monsterKillRows
            ->sort(function (NationMonsterKillStat $left, NationMonsterKillStat $right) use ($orders): int {
                $byOrder = $orders[$left->monster_definition_id] <=> $orders[$right->monster_definition_id];

                return $byOrder !== 0
                    ? $byOrder
                    : $left->definition->key <=> $right->definition->key;
            })
            ->map(static fn (NationMonsterKillStat $stat): array => [
                'key' => $stat->definition->key,
                'name' => $stat->definition->name,
                'kill_count' => $stat->kill_count,
                'first_killed_turn' => $stat->first_killed_turn,
                'last_killed_turn' => $stat->last_killed_turn,
            ])->values();

        return [
            ...$this->publicNationFields($nation, $world),
            'world' => [
                'id' => $world->id,
                'name' => $world->name,
                'current_turn' => $world->current_turn,
            ],
            'capital' => $capital === null ? null : ['x' => $capital->x, 'y' => $capital->y],
            'monster_final_blow_count' => $monsterKillStats->sum('kill_count'),
            'monster_kill_stats' => $monsterKillStats,
            'map_space' => [
                'id' => $mapSpace->id,
                'world_id' => $mapSpace->world_id,
                'key' => $mapSpace->key,
                'name' => $mapSpace->name,
                'bounds_revision' => $mapSpace->boundsRevision(),
                'bounds' => [
                    'min_x' => $mapSpace->min_x,
                    'max_x' => $mapSpace->max_x,
                    'min_y' => $mapSpace->min_y,
                    'max_y' => $mapSpace->max_y,
                ],
            ],
        ];
    }

    /** @return Collection<int, Nation> */
    private function rankedNations(World $world): Collection
    {
        $nations = Nation::query()
            ->where('world_id', $world->id)
            ->whereIn('state', ['active', 'dormant', 'recovery'])
            ->with('capital')
            ->orderBy('id')
            ->get();
        $statuses = $this->basicStatus->forWorld($world, $nations);
        foreach ($nations as $nation) {
            $this->applyBasicStatus($nation, $statuses[$nation->id]);
        }

        return $nations->sort(static function (Nation $left, Nation $right): int {
            $population = (int) $right->getAttribute('total_population')
                <=> (int) $left->getAttribute('total_population');
            if ($population !== 0) {
                return $population;
            }
            $territory = (int) $right->getAttribute('territory_cell_count')
                <=> (int) $left->getAttribute('territory_cell_count');

            return $territory !== 0 ? $territory : $left->id <=> $right->id;
        })->values();
    }

    private function nationWithPublicAggregates(Nation $nation): Nation
    {
        $nation = Nation::query()
            ->whereKey($nation->id)
            ->with('capital')
            ->firstOrFail();
        $this->applyBasicStatus($nation, $this->basicStatus->forNation($nation));

        return $nation;
    }

    /** @param array<string, int> $status */
    private function applyBasicStatus(Nation $nation, array $status): void
    {
        foreach ($status as $field => $value) {
            $nation->setAttribute($field, $value);
        }
    }

    /** @return array<string, mixed> */
    private function publicNationFields(Nation $nation, World $world): array
    {
        $estimate = $this->money->publicEstimate((int) $nation->money);
        $financeOnlyTurns = (int) $nation->idle_counter;
        $activityStatus = match ($nation->state) {
            'dormant' => 'dormant',
            'recovery' => 'recovery',
            default => $financeOnlyTurns > 0 ? 'finance_only' : 'active',
        };
        $recoveryRemainingTurns = $nation->state === 'recovery' && $nation->resume_at_turn !== null
            ? max(0, (int) $nation->resume_at_turn - (int) $world->current_turn - 1)
            : null;
        $stateLabel = match ($nation->state) {
            'dormant' => '休眠',
            'recovery' => '休戦中：残り'.$recoveryRemainingTurns.'ターン',
            default => '',
        };

        return [
            'id' => $nation->id,
            'world_id' => $nation->world_id,
            'nation_number' => $nation->nation_number,
            'name' => $nation->name,
            'owner_name' => $nation->owner_name,
            'comment' => $nation->profile_comment,
            'state' => $nation->state,
            'state_label' => $stateLabel,
            'recovery_remaining_turns' => $recoveryRemainingTurns,
            'karma' => (int) $nation->karma,
            'karma_badge' => $nation->karma > 0 ? 'KARMA:'.(int) $nation->karma : null,
            'total_population' => (int) ($nation->getAttribute('total_population') ?? 0),
            'territory_cell_count' => (int) ($nation->getAttribute('territory_cell_count') ?? 0),
            'owned_land_cells' => (int) ($nation->getAttribute('owned_land_cells') ?? 0),
            'money_display' => $estimate['display'],
            'money_bucket' => $estimate['bucket'],
            'food_total_tons' => (int) ($nation->getAttribute('food_total_tons') ?? 0),
            'farm_capacity_people' => (int) ($nation->getAttribute('farm_capacity_people') ?? 0),
            'factory_capacity_people' => (int) ($nation->getAttribute('factory_capacity_people') ?? 0),
            'mine_capacity_people' => (int) ($nation->getAttribute('mine_capacity_people') ?? 0),
            'registered_turn' => (int) $nation->registered_turn,
            'survival_turns' => max(0, (int) $world->current_turn - (int) $nation->registered_turn),
            'finance_only_turns' => $financeOnlyTurns,
            'activity_status' => $activityStatus,
            'last_updated_turn' => $world->current_turn,
        ];
    }
}
