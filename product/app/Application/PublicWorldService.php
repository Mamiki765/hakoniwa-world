<?php

namespace App\Application;

use App\Domain\Map\NationLandAreaCalculator;
use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\Nation;
use App\Models\World;
use App\Support\MoneyFormatter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class PublicWorldService
{
    public function __construct(
        private readonly MoneyFormatter $money,
        private readonly NationLandAreaCalculator $landArea,
    ) {}

    /** @return array<string, mixed> */
    public function summary(World $world): array
    {
        return [
            'id' => $world->id,
            'key' => $world->key,
            'name' => $world->name,
            'current_turn' => $world->current_turn,
            'nation_count' => $world->nations()->count(),
            'contact_url' => $this->contactUrl(),
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
        return $this->rankedNations($world)->values()->map(
            fn (Nation $nation, int $index): array => [
                'rank' => $index + 1,
                ...$this->publicNationFields($nation, $world),
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
        $monsterKillStats = DB::table('nation_monster_kill_stats')
            ->join('monster_definitions', 'monster_definitions.id', '=', 'nation_monster_kill_stats.monster_definition_id')
            ->where('nation_monster_kill_stats.world_id', $nation->world_id)
            ->where('nation_monster_kill_stats.nation_id', $nation->id)
            ->orderBy('monster_definitions.key')
            ->limit(8)
            ->get([
                'monster_definitions.key',
                'monster_definitions.name',
                'nation_monster_kill_stats.kill_count',
                'nation_monster_kill_stats.first_killed_turn',
                'nation_monster_kill_stats.last_killed_turn',
            ])
            ->map(static fn (object $stat): array => [
                'key' => (string) $stat->key,
                'name' => (string) $stat->name,
                'kill_count' => (int) $stat->kill_count,
                'first_killed_turn' => (int) $stat->first_killed_turn,
                'last_killed_turn' => (int) $stat->last_killed_turn,
            ])
            ->values();

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
        $areas = $this->landArea->forWorld($world);
        $nations = Nation::query()
            ->where('world_id', $world->id)
            ->with('capital')
            ->withCount(['territoryCells as territory_cell_count'])
            ->withSum('territoryCells as total_population', 'population')
            ->orderByDesc('total_population')
            ->orderByDesc('territory_cell_count')
            ->orderBy('id')
            ->get();
        foreach ($nations as $nation) {
            $nation->setAttribute('owned_land_cells', $areas[$nation->id] ?? 0);
        }

        return $nations;
    }

    private function nationWithPublicAggregates(Nation $nation): Nation
    {
        $nation = Nation::query()
            ->whereKey($nation->id)
            ->with('capital')
            ->withCount(['territoryCells as territory_cell_count'])
            ->withSum('territoryCells as total_population', 'population')
            ->firstOrFail();
        $nation->setAttribute('owned_land_cells', $this->landArea->forNation($nation));

        return $nation;
    }

    /** @return array<string, mixed> */
    private function publicNationFields(Nation $nation, World $world): array
    {
        $estimate = $this->money->publicEstimate((int) $nation->money);
        $financeOnlyTurns = (int) $nation->idle_counter;
        $activityStatus = $financeOnlyTurns > 0 ? 'finance_only' : 'active';

        return [
            'id' => $nation->id,
            'world_id' => $nation->world_id,
            'nation_number' => $nation->nation_number,
            'name' => $nation->name,
            'owner_name' => $nation->owner_name,
            'comment' => $nation->profile_comment,
            'state' => $nation->state,
            'total_population' => (int) ($nation->getAttribute('total_population') ?? 0),
            'territory_cell_count' => (int) ($nation->getAttribute('territory_cell_count') ?? 0),
            'owned_land_cells' => (int) ($nation->getAttribute('owned_land_cells') ?? 0),
            'money_display' => $estimate['display'],
            'money_bucket' => $estimate['bucket'],
            'registered_turn' => (int) $nation->registered_turn,
            'survival_turns' => max(0, (int) $world->current_turn - (int) $nation->registered_turn),
            'finance_only_turns' => $financeOnlyTurns,
            'activity_status' => $activityStatus,
            'last_updated_turn' => $world->current_turn,
        ];
    }
}
