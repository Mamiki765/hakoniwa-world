<?php

namespace App\Application;

use App\Domain\Map\NationLandAreaCalculator;
use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\MonsterKillRecord;
use App\Models\Nation;
use App\Models\World;
use App\Support\MoneyFormatter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class PublicWorldService
{
    /** @var array<string, string> */
    private const DISASTER_LABELS = [
        'earthquake' => '地震',
        'tsunami' => '津波',
        'typhoon' => '台風',
        'meteor_shower' => '流星群',
        'huge_meteor' => '巨大隕石',
        'eruption' => '噴火',
        'land_subsidence' => '地盤沈下',
    ];

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
            'total_population' => (int) MapCell::query()
                ->whereIn('map_space_id', MapSpace::query()->select('id')->where('world_id', $world->id))
                ->sum('population'),
        ];
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
        $monsterFinalBlowCount = MonsterKillRecord::query()
            ->where('world_id', $nation->world_id)
            ->where('killer_nation_id', $nation->id)
            ->count();
        $monsterKillMarks = DB::table('monster_kill_records')
            ->join('monster_definitions', 'monster_definitions.id', '=', 'monster_kill_records.monster_definition_id')
            ->where('monster_kill_records.world_id', $nation->world_id)
            ->where('monster_kill_records.killer_nation_id', $nation->id)
            ->groupBy('monster_definitions.id', 'monster_definitions.key', 'monster_definitions.name')
            ->orderBy('monster_definitions.key')
            ->get([
                'monster_definitions.key',
                'monster_definitions.name',
                DB::raw('MIN(monster_kill_records.target_turn) AS first_kill_turn'),
            ])
            ->map(static fn (object $mark): array => [
                'key' => (string) $mark->key,
                'name' => (string) $mark->name,
                'first_kill_turn' => (int) $mark->first_kill_turn,
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
            'monster_final_blow_count' => $monsterFinalBlowCount,
            'monster_kill_marks' => $monsterKillMarks,
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

    /** @return Collection<int, array{id: int, type: string, message: string, metadata: array<string, int|string>, occurred_at: string}> */
    public function recentEvents(World $world, int $limit = 12): Collection
    {
        $nationEvents = DB::table('audit_events')
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
                'nations.nation_number',
                'nations.name as nation_name',
            ])
            ->map(static fn (object $event): array => [
                'id' => (int) $event->id,
                'type' => 'nation_created',
                'message' => "{$event->nation_name}が成立しました。",
                'metadata' => [
                    'nation_id' => (int) $event->nation_id,
                    'nation_number' => (int) $event->nation_number,
                    'nation_name' => (string) $event->nation_name,
                ],
                'occurred_at' => (string) $event->occurred_at,
            ]);

        $disasterEvents = DB::table('audit_events')
            ->where('subject_type', $world->getMorphClass())
            ->where('subject_id', $world->getKey())
            ->where('event_type', 'disaster.triggered')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'occurred_at', 'metadata'])
            ->map(fn (object $event): ?array => $this->publicDisasterEvent($event))
            ->filter(static fn (?array $event): bool => $event !== null)
            ->values();

        $landSubsidenceEvents = DB::table('audit_events')
            ->join('nations', function ($join): void {
                $join->on('nations.id', '=', 'audit_events.subject_id')
                    ->where('audit_events.subject_type', '=', Nation::class);
            })
            ->where('nations.world_id', $world->id)
            ->where('audit_events.event_type', 'land_subsidence.triggered')
            ->orderByDesc('audit_events.occurred_at')
            ->orderByDesc('audit_events.id')
            ->limit($limit)
            ->get(['audit_events.id', 'audit_events.occurred_at', 'audit_events.metadata'])
            ->map(fn (object $event): ?array => $this->publicDisasterEvent($event))
            ->filter(static fn (?array $event): bool => $event !== null)
            ->values();

        return $nationEvents
            ->concat($disasterEvents)
            ->concat($landSubsidenceEvents)
            ->sort(static function (array $left, array $right): int {
                $byTime = strcmp($right['occurred_at'], $left['occurred_at']);

                return $byTime !== 0 ? $byTime : $right['id'] <=> $left['id'];
            })
            ->take($limit)
            ->values();
    }

    /** @return array{id: int, type: string, message: string, metadata: array<string, int|string>, occurred_at: string}|null */
    private function publicDisasterEvent(object $event): ?array
    {
        $metadata = json_decode((string) $event->metadata, true);
        if (! is_array($metadata)) {
            return null;
        }

        $key = $metadata['disaster_key'] ?? null;
        if (! is_string($key) || ! isset(self::DISASTER_LABELS[$key])) {
            return null;
        }

        if ($key === 'land_subsidence') {
            $nationNumber = (int) ($metadata['nation_number'] ?? 0);

            return [
                'id' => (int) $event->id,
                'type' => 'land_subsidence_triggered',
                'message' => sprintf('N%dで地盤沈下が発生しました。', $nationNumber),
                'metadata' => [
                    'target_turn' => (int) ($metadata['target_turn'] ?? 0),
                    'disaster_key' => $key,
                    'nation_number' => $nationNumber,
                    'changed_to_sea_count' => (int) ($metadata['changed_to_sea_count'] ?? 0),
                    'changed_to_shallow_count' => (int) ($metadata['changed_to_shallow_count'] ?? 0),
                ],
                'occurred_at' => (string) $event->occurred_at,
            ];
        }

        return [
            'id' => (int) $event->id,
            'type' => 'disaster_triggered',
            'message' => sprintf(
                '%sが発生しました（中心 %d,%d）。',
                self::DISASTER_LABELS[$key],
                (int) ($metadata['center_x'] ?? 0),
                (int) ($metadata['center_y'] ?? 0),
            ),
            'metadata' => [
                'target_turn' => (int) ($metadata['target_turn'] ?? 0),
                'disaster_key' => $key,
                'center_x' => (int) ($metadata['center_x'] ?? 0),
                'center_y' => (int) ($metadata['center_y'] ?? 0),
            ],
            'occurred_at' => (string) $event->occurred_at,
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
            'last_updated_turn' => $world->current_turn,
        ];
    }
}
