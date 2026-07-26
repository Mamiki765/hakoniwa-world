<?php

namespace App\Application;

use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\Nation;
use App\Models\World;
use App\Support\MoneyFormatter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class PublicWorldService
{
    public function __construct(private readonly MoneyFormatter $money) {}

    /** @return array<string, mixed> */
    public function summary(World $world): array
    {
        return [
            'id' => $world->id,
            'key' => $world->key,
            'name' => $world->name,
            'current_turn' => $world->current_turn,
            'nation_count' => $world->nations()->count(),
            'total_population' => (int) MapCell::query()
                ->whereIn('map_space_id', MapSpace::query()->select('id')->where('world_id', $world->id))
                ->sum('population'),
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
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

        return [
            ...$this->publicNationFields($nation, $world),
            'world' => [
                'id' => $world->id,
                'name' => $world->name,
                'current_turn' => $world->current_turn,
            ],
            'capital' => $capital === null ? null : ['x' => $capital->x, 'y' => $capital->y],
            'map_space' => [
                'id' => $mapSpace->id,
                'world_id' => $mapSpace->world_id,
                'key' => $mapSpace->key,
                'name' => $mapSpace->name,
            ],
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    public function recentEvents(World $world, int $limit = 12): Collection
    {
        return DB::table('audit_events')
            ->join('nations', function ($join): void {
                $join->on('nations.id', '=', 'audit_events.subject_id')
                    ->where('audit_events.subject_type', '=', Nation::class);
            })
            ->where('nations.world_id', $world->id)
            ->where('audit_events.event_type', 'nation.created')
            ->orderByDesc('audit_events.occurred_at')
            ->orderByDesc('audit_events.id')
            ->limit($limit)
            ->get([
                'audit_events.id',
                'audit_events.occurred_at',
                'nations.id as nation_id',
                'nations.name as nation_name',
            ])
            ->map(static fn (object $event): array => [
                'id' => (int) $event->id,
                'type' => 'nation_created',
                'message' => "{$event->nation_name}が成立しました。",
                'metadata' => [
                    'nation_id' => (int) $event->nation_id,
                    'nation_name' => (string) $event->nation_name,
                ],
                'occurred_at' => (string) $event->occurred_at,
            ]);
    }

    /** @return Collection<int, Nation> */
    private function rankedNations(World $world): Collection
    {
        return Nation::query()
            ->where('world_id', $world->id)
            ->with('capital')
            ->withCount(['territoryCells as territory_cell_count'])
            ->withSum('territoryCells as total_population', 'population')
            ->orderByDesc('total_population')
            ->orderByDesc('territory_cell_count')
            ->orderBy('id')
            ->get();
    }

    private function nationWithPublicAggregates(Nation $nation): Nation
    {
        return Nation::query()
            ->whereKey($nation->id)
            ->with('capital')
            ->withCount(['territoryCells as territory_cell_count'])
            ->withSum('territoryCells as total_population', 'population')
            ->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function publicNationFields(Nation $nation, World $world): array
    {
        $estimate = $this->money->publicEstimate((int) $nation->money);

        return [
            'id' => $nation->id,
            'world_id' => $nation->world_id,
            'name' => $nation->name,
            'state' => $nation->state,
            'total_population' => (int) ($nation->getAttribute('total_population') ?? 0),
            'territory_cell_count' => (int) ($nation->getAttribute('territory_cell_count') ?? 0),
            'money_display' => $estimate['display'],
            'money_bucket' => $estimate['bucket'],
            'last_updated_turn' => $world->current_turn,
            'comment' => null,
        ];
    }
}
