<?php

namespace App\Application;

use App\Models\MapCell;
use App\Models\Nation;
use App\Models\NationCommandQueueItem;
use App\Models\World;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class PlayerIslandEventService
{
    public const OWNER_TURNS_PER_PAGE = 24;

    public const PUBLIC_WORLD_TURNS_PER_PAGE = 2;

    public const PUBLIC_NATION_TURNS_PER_PAGE = 24;

    public const MAJOR_NEWS_LIMIT = 15;

    // monster.spawn_failed_no_settlement is intentionally audit-only and absent
    // from every player-facing allowlist.

    /** @var list<string> */
    private const OWNER_EVENT_TYPES = [
        'command.failed',
        'command.invalid',
        'command.insufficient_assets',
        'terrain.changed',
        'facility.constructed',
        'facility.expanded',
        'command.buried_treasure',
        'command.seabed_oil_search',
        'command.land_level_earthquake',
        'disaster.cell_damaged',
        'capital.disaster_damaged',
        'fire.damaged',
        'oil.income',
        'oil.depleted',
        'population.decreased',
        'resource.food_shortage',
        'famine.applied',
        'facility.riot',
        'resource.automatic_sale',
        'resource.food_overflow_resolved',
        'capacity.overflow',
        'command.finance',
        'command.forest_planted_private',
        'command.missile_base_built_private',
        'command.seabed_base_built_private',
        'command.decoy_built_private',
        'command.logging_private',
        'command.capital_relocated',
        'command.monument_launched',
        'command.attraction_started',
        'command.money_aid_transferred',
        'command.money_aid_received',
        'command.food_aid_transferred',
        'command.food_aid_received',
        'command.monster_dispatched',
        'missile.launch_failed',
        'missile.launch_detail',
        'missile.defense_intercepted',
        'secretary.missile_intercepted',
        'refugee_received',
        'karma.spp_self_destruct_setup',
        'monster.item_drop_received',
        'monster.item_drop_inventory_full',
        'trading_post.sold_private',
        'turn.summary',
    ];

    /** @var list<string> */
    private const PUBLIC_ISLAND_EVENT_TYPES = [
        'award.granted',
        'command.terrain_changed_public',
        'command.forest_planted_public',
        'command.seabed_base_built_public',
        'command.facility_built_public',
        'command.logging_public',
        'command.capital_relocated_public',
        'command.attraction_started_public',
        'command.money_aid_public',
        'command.food_aid_public',
        'command.territory_expanded',
        'territory.influenced',
        'disaster.triggered',
        'land_subsidence.triggered',
        'disaster.cell_damaged',
        'capital.disaster_damaged',
        'fire.damaged',
        'monster.spawned',
        'monster.moved',
        'monster.trampled',
        'monster.damage_blocked',
        'monster.damaged',
        'monster.killed',
        'monster.defense_self_destructed',
        'monster.nuclear_self_destructed',
        'monster.removed_by_terrain_event',
        'missile.launched',
        'missile.ineffective_aggregated',
        'missile.dormancy_protected',
        'missile.recovery_protected',
        'missile.impact',
        'refugee_generated',
        'nation.dormant',
        'nation.dormancy_resumed',
        'nation.recovery_started',
        'nation.recovery_ended',
        'nation.recovery_monsters_removed',
        'karma.sanction_decided',
        'karma.sanction_launched',
        'trading_post.won_public',
    ];

    /** @var list<string> */
    private const MAJOR_NEWS_EVENT_TYPES = [
        'world.expanded_public',
        'nation.created',
        'nation.disappeared',
        'nation.abandoned',
        'nation.depopulated',
    ];

    /** @var list<string> */
    private const HOST_ISLAND_MONSTER_EVENT_TYPES = [
        'monster.damage_blocked',
        'monster.damaged',
        'monster.killed',
    ];

    /** @var list<string> */
    private const CROSS_NATION_PUBLIC_EVENT_TYPES = [
        'command.money_aid_public',
        'command.food_aid_public',
    ];

    /** @var list<string> */
    private const CONFIDENTIAL_EVENT_TYPES = [
        'command.forest_planted_private',
        'command.missile_base_built_private',
        'command.seabed_base_built_private',
        'command.decoy_built_private',
        'command.logging_private',
        'command.monument_launched',
        'command.monster_dispatched',
        'missile.launch_detail',
        'karma.spp_self_destruct_setup',
        'monster.item_drop_received',
        'monster.item_drop_inventory_full',
        'trading_post.sold_private',
    ];

    /**
     * @return array{
     *     groups: list<array{target_turn: int, events: list<array<string, mixed>>}>,
     *     page: int,
     *     anchor_turn: int,
     *     turn_range: array{start: int, end: int}|null,
     *     turns_per_page: int,
     *     has_newer_page: bool,
     *     has_older_page: bool
     * }
     */
    public function ownerPage(Nation $nation, int $page = 1, ?int $anchorTurn = null): array
    {
        $anchorTurn ??= (int) $nation->world()->value('current_turn');
        $range = $this->turnRange($anchorTurn, $page, self::OWNER_TURNS_PER_PAGE);
        if ($range === null) {
            return $this->emptyPage($page, $anchorTurn, self::OWNER_TURNS_PER_PAGE);
        }

        [$rangeStart, $rangeEnd] = $range;
        $world = $nation->world()->firstOrFail();
        $query = DB::table('audit_events as events')
            ->leftJoin('nations as event_nations', 'event_nations.id', '=', 'events.nation_id')
            ->where('events.world_id', $nation->world_id)
            ->whereBetween('events.turn', [$rangeStart, $rangeEnd])
            ->where(function (Builder $audience) use ($nation): void {
                $audience->where(function (Builder $owned) use ($nation): void {
                    $owned->whereIn('events.event_type', self::OWNER_EVENT_TYPES)
                        ->whereIn('events.visibility', ['nation', 'private'])
                        ->where('events.nation_id', $nation->id);
                })->orWhere(function (Builder $reward) use ($nation): void {
                    $reward->where('events.event_type', 'monster.reward_distributed')
                        // Legacy rows were public, but this branch remains role-gated.
                        ->whereIn('events.visibility', ['public', 'private'])
                        ->where(function (Builder $role) use ($nation): void {
                            $role->whereRaw("events.metadata->>'killer_nation_id' = ?", [(string) $nation->id])
                                ->orWhereRaw("events.metadata->>'host_nation_id' = ?", [(string) $nation->id]);
                        });
                })->orWhere(function (Builder $public) use ($nation): void {
                    $public->whereIn('events.event_type', self::PUBLIC_ISLAND_EVENT_TYPES)
                        ->where('events.visibility', 'public')
                        ->where(function (Builder $historicalHostAttribution): void {
                            $this->constrainHistoricalHostAttribution($historicalHostAttribution);
                        })
                        ->where(function (Builder $destination) use ($nation): void {
                            $this->constrainPublicIslandDestination($destination, $nation->id);
                        });
                });
            })
            ->where(function (Builder $visible): void {
                $visible->where('events.event_type', '!=', 'command.buried_treasure')
                    ->orWhereRaw("events.metadata->>'found' = 'true'");
            })
            ->where(function (Builder $visible): void {
                $visible->where('events.event_type', '!=', 'resource.automatic_sale')
                    ->orWhereRaw("COALESCE(events.metadata->>'sold', '0') <> '0'");
            })
            ->where(function (Builder $deduplicated): void {
                $deduplicated->where('events.event_type', '!=', 'population.decreased')
                    ->orWhereRaw("COALESCE(events.metadata->>'reason', '') <> 'famine'");
            });

        $rows = $query
            ->orderByDesc('events.turn')
            ->orderByDesc('events.id')
            ->get([
                'events.id',
                'events.event_type',
                'events.subject_type',
                'events.subject_id',
                'events.metadata',
                'events.visibility',
                'events.turn',
                'events.x',
                'events.y',
                'event_nations.name as event_nation_name',
            ]);
        $destinationNationNames = $this->publicDestinationNationNames($world, $rows->all());
        $rows = $this->filterResolvablePublicDestinations($rows, $destinationNationNames);
        $coordinates = $this->subjectCoordinates($rows->all());
        $events = $rows->map(function (object $row) use ($coordinates, $destinationNationNames, $nation): array {
            $metadata = $this->metadata($row->metadata);
            $eventType = (string) $row->event_type;
            $targetTurn = (int) $row->turn;
            if ((string) $row->visibility === 'public'
                && in_array($eventType, self::PUBLIC_ISLAND_EVENT_TYPES, true)) {
                $metadata['nation_name'] = $this->publicDestinationNationName(
                    $eventType,
                    $metadata,
                    $row,
                    $destinationNationNames,
                );
                if ($row->x !== null && $row->y !== null) {
                    $metadata['x'] ??= (int) $row->x;
                    $metadata['y'] ??= (int) $row->y;
                }

                return [
                    'id' => (int) $row->id,
                    'type' => $eventType,
                    'message' => $this->publicIslandMessage(
                        $eventType,
                        $this->publicSafeMetadata($eventType, $metadata),
                    ),
                    'importance' => $this->importance($eventType),
                    'target_turn' => $targetTurn,
                    'confidential' => false,
                    'summary' => null,
                    '_metadata' => $metadata,
                    '_visibility' => 'public',
                ];
            }

            $metadata['audience_nation_name'] = $nation->name;
            $coordinate = $this->coordinate($row, $metadata, $coordinates);
            if ($coordinate !== null && (string) $row->event_type !== 'monster.reward_distributed') {
                $metadata['x'] ??= $coordinate['x'];
                $metadata['y'] ??= $coordinate['y'];
            }
            $message = $this->message($eventType, $metadata, $targetTurn, $nation->id);
            if ($coordinate !== null && $eventType !== 'monster.reward_distributed') {
                $coordinateText = sprintf('(%s,%s)', number_format($coordinate['x']), number_format($coordinate['y']));
                if (! str_contains($message, $coordinateText)) {
                    $message = sprintf('%s%sで%s', $nation->name, $coordinateText, $message);
                }
            }

            return [
                'id' => (int) $row->id,
                'type' => $eventType,
                'message' => $message,
                'importance' => $this->importance($eventType),
                'target_turn' => $targetTurn,
                'confidential' => in_array($eventType, self::CONFIDENTIAL_EVENT_TYPES, true),
                'summary' => $eventType === 'turn.summary'
                    ? $this->turnSummaryProjection($metadata)
                    : null,
                '_metadata' => $metadata,
                '_visibility' => (string) $row->visibility,
            ];
        })->all();
        $events = $this->preferOwnerEventDetails($events);
        $events = $this->expandMissileLaunchDetails($events);
        $events = $this->aggregateMissileDefenseEvents($events);
        $events = $this->aggregateRefugeeEvents($events, $nation->id);

        return [
            'groups' => $this->groupByTurn($events),
            'page' => $page,
            'anchor_turn' => $anchorTurn,
            'turn_range' => ['start' => $rangeStart, 'end' => $rangeEnd],
            'turns_per_page' => self::OWNER_TURNS_PER_PAGE,
            'has_newer_page' => $page > 1,
            'has_older_page' => $rangeStart > 1,
        ];
    }

    /**
     * @deprecated Use ownerPage() to make the destination explicit.
     *
     * @return array<string, mixed>
     */
    public function page(Nation $nation, int $page = 1, ?int $anchorTurn = null): array
    {
        return $this->ownerPage($nation, $page, $anchorTurn);
    }

    /**
     * Project the World news feed from public events only. Metadata and
     * coordinates are deliberately never part of this public response.
     *
     * @return array{
     *     groups: list<array{target_turn: int, events: list<array<string, mixed>>}>,
     *     page: int,
     *     anchor_turn: int,
     *     turn_range: array{start: int, end: int}|null,
     *     turns_per_page: int,
     *     has_newer_page: bool,
     *     has_older_page: bool
     * }
     */
    public function publicWorldPage(World $world, int $page = 1, ?int $anchorTurn = null): array
    {
        return $this->publicIslandPage(
            $world,
            null,
            $page,
            $anchorTurn,
            self::PUBLIC_WORLD_TURNS_PER_PAGE,
        );
    }

    /**
     * @deprecated Use publicWorldPage() to make the destination explicit.
     *
     * @return array<string, mixed>
     */
    public function publicPage(World $world, int $page = 1, ?int $anchorTurn = null): array
    {
        return $this->publicWorldPage($world, $page, $anchorTurn);
    }

    /** @return array<string, mixed> */
    public function publicNationPage(Nation $nation, int $page = 1, ?int $anchorTurn = null): array
    {
        return $this->publicIslandPage(
            $nation->world,
            $nation->id,
            $page,
            $anchorTurn,
            self::PUBLIC_NATION_TURNS_PER_PAGE,
        );
    }

    /** @return array{groups: list<array{target_turn: int, events: list<array<string, mixed>>}>, limit: int} */
    public function majorNews(World $world): array
    {
        $rows = DB::table('audit_events as events')
            ->leftJoin('nations as event_nations', 'event_nations.id', '=', 'events.nation_id')
            ->where('events.world_id', $world->id)
            ->where('events.visibility', 'public')
            ->whereIn('events.event_type', self::MAJOR_NEWS_EVENT_TYPES)
            ->orderByDesc('events.turn')
            ->orderByDesc('events.id')
            ->limit(self::MAJOR_NEWS_LIMIT)
            ->get([
                'events.id',
                'events.event_type',
                'events.metadata',
                'events.turn',
                'event_nations.name as event_nation_name',
            ]);

        $events = $rows->map(function (object $row): array {
            $metadata = $this->metadata($row->metadata);
            $nationName = is_string($metadata['nation_name'] ?? null)
                ? $metadata['nation_name']
                : (is_string($row->event_nation_name) ? $row->event_nation_name : null);

            return [
                'id' => (int) $row->id,
                'type' => (string) $row->event_type,
                'message' => $this->majorNewsMessage((string) $row->event_type, $nationName),
                'importance' => 'notable',
                'target_turn' => (int) $row->turn,
            ];
        })->all();

        return ['groups' => $this->groupByTurn($events), 'limit' => self::MAJOR_NEWS_LIMIT];
    }

    /** @return array<string, mixed> */
    private function publicIslandPage(
        World $world,
        ?int $nationId,
        int $page,
        ?int $anchorTurn,
        int $turnsPerPage,
    ): array {
        $anchorTurn ??= (int) $world->current_turn;
        $range = $this->turnRange($anchorTurn, $page, $turnsPerPage);
        if ($range === null) {
            return $this->emptyPage($page, $anchorTurn, $turnsPerPage);
        }
        [$rangeStart, $rangeEnd] = $range;

        $rows = DB::table('audit_events as events')
            ->leftJoin('nations as event_nations', 'event_nations.id', '=', 'events.nation_id')
            ->where('events.world_id', $world->id)
            ->where('events.visibility', 'public')
            ->whereIn('events.event_type', self::PUBLIC_ISLAND_EVENT_TYPES)
            ->whereBetween('events.turn', [$rangeStart, $rangeEnd])
            ->where(function (Builder $historicalHostAttribution): void {
                $this->constrainHistoricalHostAttribution($historicalHostAttribution);
            })
            ->when($nationId !== null, function (Builder $query) use ($nationId): Builder {
                return $query->where(function (Builder $destination) use ($nationId): void {
                    $this->constrainPublicIslandDestination($destination, $nationId);
                });
            })
            ->orderByDesc('events.turn')
            ->orderByDesc('events.id')
            ->get([
                'events.id',
                'events.event_type',
                'events.metadata',
                'events.visibility',
                'events.turn',
                'events.x',
                'events.y',
                'event_nations.name as event_nation_name',
            ]);

        $destinationNationNames = $this->publicDestinationNationNames($world, $rows->all());
        $rows = $this->filterResolvablePublicDestinations($rows, $destinationNationNames);
        $events = $rows->map(function (object $row) use ($destinationNationNames): array {
            $rawMetadata = $this->metadata($row->metadata);
            $eventType = (string) $row->event_type;
            $rawMetadata['nation_name'] = $this->publicDestinationNationName(
                $eventType,
                $rawMetadata,
                $row,
                $destinationNationNames,
            );
            if ($row->x !== null && $row->y !== null) {
                $rawMetadata['x'] ??= (int) $row->x;
                $rawMetadata['y'] ??= (int) $row->y;
            }
            $metadata = $this->publicSafeMetadata($eventType, $rawMetadata);
            $targetTurn = (int) $row->turn;

            return [
                'id' => (int) $row->id,
                'type' => $eventType,
                'message' => $this->publicIslandMessage($eventType, $metadata),
                'importance' => $this->importance($eventType),
                'target_turn' => $targetTurn,
                '_aggregation_key' => $this->publicAggregationKey($eventType, $rawMetadata),
            ];
        })->all();
        $events = $this->aggregatePublicRefugees($events);

        return [
            'groups' => $this->groupByTurn($events),
            'page' => $page,
            'anchor_turn' => $anchorTurn,
            'turn_range' => ['start' => $rangeStart, 'end' => $rangeEnd],
            'turns_per_page' => $turnsPerPage,
            'has_newer_page' => $page > 1,
            'has_older_page' => $rangeStart > 1,
        ];
    }

    private function constrainHistoricalHostAttribution(Builder $query): void
    {
        // Historical ownership is taken only from the event snapshot.
        // Legacy damage rows without host_nation_id stay fail-closed.
        $query->whereNotIn('events.event_type', self::HOST_ISLAND_MONSTER_EVENT_TYPES)
            ->orWhereRaw("events.metadata->>'host_nation_id' IS NOT NULL");
    }

    private function constrainPublicIslandDestination(Builder $query, int $nationId): void
    {
        $query->where(function (Builder $monsterHost) use ($nationId): void {
            $monsterHost->whereIn('events.event_type', self::HOST_ISLAND_MONSTER_EVENT_TYPES)
                ->whereRaw("events.metadata->>'host_nation_id' = ?", [(string) $nationId]);
        })->orWhere(function (Builder $defenseHost) use ($nationId): void {
            $defenseHost->where('events.event_type', 'monster.defense_self_destructed')
                ->whereRaw("events.metadata->>'defense_owner_nation_id' = ?", [(string) $nationId]);
        })->orWhere(function (Builder $aid) use ($nationId): void {
            $aid->whereIn('events.event_type', self::CROSS_NATION_PUBLIC_EVENT_TYPES)
                ->where(function (Builder $related) use ($nationId): void {
                    $related->whereRaw("events.metadata->>'sender_nation_id' = ?", [(string) $nationId])
                        ->orWhereRaw("events.metadata->>'receiver_nation_id' = ?", [(string) $nationId]);
                });
        })->orWhere(function (Builder $ordinaryEvent) use ($nationId): void {
            $ordinaryEvent->whereNotIn('events.event_type', [
                ...self::HOST_ISLAND_MONSTER_EVENT_TYPES,
                ...self::CROSS_NATION_PUBLIC_EVENT_TYPES,
                'monster.defense_self_destructed',
            ])->where('events.nation_id', $nationId);
        });
    }

    /**
     * @param  Collection<int, \stdClass>  $rows
     * @param  array<int, string>  $destinationNationNames
     * @return Collection<int, \stdClass>
     */
    private function filterResolvablePublicDestinations(Collection $rows, array $destinationNationNames): Collection
    {
        return $rows->filter(function (object $row) use ($destinationNationNames): bool {
            if (($row->visibility ?? null) !== 'public') {
                return true;
            }

            $destinationKey = match ((string) $row->event_type) {
                'monster.damage_blocked', 'monster.damaged', 'monster.killed' => 'host_nation_id',
                'monster.defense_self_destructed' => 'defense_owner_nation_id',
                default => null,
            };
            if ($destinationKey === null) {
                return true;
            }

            $metadata = $this->metadata($row->metadata);
            $destinationNationId = $metadata[$destinationKey] ?? null;
            $snapshotKey = $destinationKey === 'host_nation_id'
                ? 'host_nation_name'
                : 'defense_owner_nation_name';

            return is_numeric($destinationNationId)
                && (is_string($metadata[$snapshotKey] ?? null)
                    || array_key_exists((int) $destinationNationId, $destinationNationNames));
        });
    }

    /**
     * @param  list<object>  $rows
     * @return array<int, string>
     */
    private function publicDestinationNationNames(World $world, array $rows): array
    {
        // Snapshot names stay row-local. Resolve current names only for legacy
        // rows that captured the destination ID but not its name.
        $nationIds = [];
        foreach ($rows as $row) {
            $eventType = (string) $row->event_type;
            $metadata = $this->metadata($row->metadata);
            $key = match ($eventType) {
                'monster.damage_blocked', 'monster.damaged', 'monster.killed' => 'host_nation_id',
                'monster.defense_self_destructed' => 'defense_owner_nation_id',
                default => null,
            };
            if ($key !== null && is_numeric($metadata[$key] ?? null)) {
                $nationId = (int) $metadata[$key];
                $nameKey = $key === 'host_nation_id'
                    ? 'host_nation_name'
                    : 'defense_owner_nation_name';
                if (! is_string($metadata[$nameKey] ?? null)) {
                    $nationIds[] = $nationId;
                }
            }
        }

        if ($nationIds === []) {
            return [];
        }

        return Nation::query()
            ->where('world_id', $world->id)
            ->whereIn('id', array_values(array_unique($nationIds)))
            ->pluck('name', 'id')
            ->mapWithKeys(static fn (string $name, int|string $id): array => [(int) $id => $name])
            ->all();
    }

    /**
     * Resolve the island whose public log owns the event. Monster damage is
     * stored against the attacker in audit_events.nation_id, so using that
     * column here would leak host coordinates into the attacker's island log.
     *
     * @param  array<string, mixed>  $metadata
     * @param  array<int, string>  $destinationNationNames
     */
    private function publicDestinationNationName(
        string $eventType,
        array $metadata,
        object $row,
        array $destinationNationNames,
    ): ?string {
        $destinationKey = match ($eventType) {
            'monster.damage_blocked', 'monster.damaged', 'monster.killed' => 'host_nation_id',
            'monster.defense_self_destructed' => 'defense_owner_nation_id',
            default => null,
        };
        if ($destinationKey !== null) {
            $snapshotKey = $destinationKey === 'host_nation_id'
                ? 'host_nation_name'
                : 'defense_owner_nation_name';
            if (is_string($metadata[$snapshotKey] ?? null)) {
                return $metadata[$snapshotKey];
            }
            if (is_numeric($metadata[$destinationKey] ?? null)) {
                return $destinationNationNames[(int) $metadata[$destinationKey]] ?? null;
            }

            return null;
        }

        if (is_string($metadata['nation_name'] ?? null)) {
            return $metadata['nation_name'];
        }

        return is_string($row->event_nation_name) ? $row->event_nation_name : null;
    }

    /** @return array{0: int, 1: int}|null */
    private function turnRange(int $anchorTurn, int $page, int $turnsPerPage): ?array
    {
        $rangeEnd = $anchorTurn - (($page - 1) * $turnsPerPage);

        return $rangeEnd < 1 ? null : [max(1, $rangeEnd - $turnsPerPage + 1), $rangeEnd];
    }

    /** @return array<string, mixed> */
    private function emptyPage(int $page, int $anchorTurn, int $turnsPerPage): array
    {
        return [
            'groups' => [],
            'page' => $page,
            'anchor_turn' => $anchorTurn,
            'turn_range' => null,
            'turns_per_page' => $turnsPerPage,
            'has_newer_page' => $page > 1,
            'has_older_page' => false,
        ];
    }

    /** @param list<array<string, mixed>> $events
     * @return list<array{target_turn: int, events: list<array<string, mixed>>}>
     */
    private function groupByTurn(array $events): array
    {
        $groups = [];
        foreach ($events as $event) {
            $last = array_key_last($groups);
            if ($last === null || $groups[$last]['target_turn'] !== $event['target_turn']) {
                $groups[] = ['target_turn' => $event['target_turn'], 'events' => []];
                $last = array_key_last($groups);
            }
            unset($event['_metadata'], $event['_aggregation_key'], $event['_visibility']);
            $groups[$last]['events'][] = $event;
        }

        return $groups;
    }

    private function majorNewsMessage(string $eventType, ?string $nationName): string
    {
        $nation = $nationName ?? '島';

        return match ($eventType) {
            'world.expanded_public' => '大きな地響きが鳴り響き、世界がより広くなりました',
            'nation.created' => "{$nation}ができました。",
            'nation.abandoned' => "{$nation}は破棄され、忘れ去られた。",
            'nation.disappeared', 'nation.depopulated' => "{$nation}が消えました。",
            default => '世界で大きな出来事がありました。',
        };
    }

    /**
     * Public text is built only from explicitly safe fields. Never delegate
     * this projection to the owner formatter, which accepts rich metadata.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function publicIslandMessage(string $eventType, array $metadata): string
    {
        $nation = is_string($metadata['nation_name'] ?? null) ? $metadata['nation_name'] : '島';
        $x = $this->publicCoordinate($metadata, 'x');
        $y = $this->publicCoordinate($metadata, 'y');
        $monster = $this->monsterLabel($metadata['monster_key'] ?? null);

        return match ($eventType) {
            'award.granted' => sprintf(
                '%sに%sが進呈されました。',
                $nation,
                is_string($metadata['award_name'] ?? null) ? $metadata['award_name'] : '賞',
            ),
            'command.terrain_changed_public' => sprintf(
                '%s(%s,%s)で%sが行われました。',
                $nation,
                $x,
                $y,
                $this->commandLabel($metadata),
            ),
            'command.forest_planted_public' => "こころなしか、{$nation}のどこかで森が増えた気がします。",
            'command.logging_public' => "こころなしか、{$nation}のどこかで森が減った気がします。",
            'command.seabed_base_built_public' => "{$nation}で海底基地が建設されたようです(?,?)。",
            'command.facility_built_public' => $this->publicFacilityBuiltMessage($metadata),
            'command.capital_relocated_public' => sprintf(
                '%sの首都が(%s,%s)から(%s,%s)へ移転しました。',
                $nation,
                $this->publicCoordinate($metadata, 'from_x'),
                $this->publicCoordinate($metadata, 'from_y'),
                $x,
                $y,
            ),
            'command.attraction_started_public' => "{$nation}で誘致活動が行われました。",
            'command.money_aid_public' => sprintf(
                '%sから%sへ%s億円の資金援助が行われました。',
                $metadata['sender_nation_name'] ?? '送信島',
                $metadata['receiver_nation_name'] ?? '受信島',
                number_format($this->integer($metadata, 'transferred_money')),
            ),
            'command.food_aid_public' => sprintf(
                '%sから%sへ食料%sトンの援助が行われました。',
                $metadata['sender_nation_name'] ?? '送信島',
                $metadata['receiver_nation_name'] ?? '受信島',
                number_format($this->integer($metadata, 'transferred_food_tons')),
            ),
            'command.territory_expanded', 'territory.influenced' => sprintf(
                '%s(%s,%s)の土地は、%sの領地となりました。',
                $metadata['old_owner_nation_name'] ?? '中立地域',
                $x,
                $y,
                $metadata['new_owner_nation_name'] ?? $nation,
            ),
            'disaster.triggered' => $this->publicDisasterMessage($metadata),
            'land_subsidence.triggered' => $nation === '島'
                ? '地盤沈下が発生しました。'
                : "{$nation}で地盤沈下が発生しました。",
            'disaster.cell_damaged', 'fire.damaged' => sprintf(
                '%s(%s,%s)で%s',
                $nation,
                $x,
                $y,
                $this->disasterCellDamageMessage($metadata),
            ),
            'capital.disaster_damaged' => sprintf(
                '%s(%s,%s)で%sにより首都人口が%s%%減少し、%s人になりました。',
                $nation,
                $x,
                $y,
                $this->disasterLabel($metadata['disaster_key'] ?? null),
                number_format($this->integer($metadata, 'damage_percent')),
                number_format($this->integer($metadata, 'after_population')),
            ),
            'monster.spawned' => ($metadata['spawn_source'] ?? null) === 'world_aoi_disaster'
                ? "中立海域({$x},{$y})に{$monster}が出現しました。"
                : "{$nation}({$x},{$y})に{$monster}が出現し、一帯を踏み荒らしました。",
            'monster.moved' => ($nation === '島' ? '中立海域' : $nation)."({$x},{$y})へ{$monster}が移動した模様です。",
            'monster.trampled' => sprintf(
                '%s(%s,%s)の%sが%sに踏み荒らされました。',
                $nation,
                $x,
                $y,
                $metadata['location_label'] ?? '土地',
                $monster,
            ),
            'monster.damage_blocked' => "{$nation}({$x},{$y})の{$monster}に攻撃が命中しましたが、硬化中のため効果がありませんでした。",
            'monster.damaged' => "{$nation}({$x},{$y})の{$monster}に攻撃が命中し、苦しそうに咆哮しました。",
            'monster.killed' => "{$nation}({$x},{$y})の{$monster}は力尽き、倒れました。"
                .(($metadata['reward_distributed'] ?? false) === true
                    ? '怪獣は解体され、報酬が分配されました。'
                    : ''),
            'monster.defense_self_destructed' => sprintf(
                '%s(%s,%s)で%sが防衛施設へ接触し、施設とともに消滅しました。',
                $nation,
                $this->publicCoordinate($metadata, 'center_x'),
                $this->publicCoordinate($metadata, 'center_y'),
                $monster,
            ),
            'monster.nuclear_self_destructed' => sprintf(
                '%s(%s,%s)のメカいのら零式が突然輝きだし、とてつもない爆発を起こしました！',
                $nation === '島' ? '中立海域' : $nation,
                $this->publicCoordinate($metadata, 'center_x'),
                $this->publicCoordinate($metadata, 'center_y'),
            ),
            'monster.removed_by_terrain_event' => "{$nation}({$x},{$y})の{$monster}が地形変化により消滅しました。",
            'missile.launched' => sprintf(
                '%sが%sを%s発発射しました。',
                $nation,
                $this->missileLabel($metadata['command_key'] ?? null),
                number_format($this->integer($metadata, 'fired_shots')),
            ),
            'missile.ineffective_aggregated' => sprintf(
                '%sのうち%s発は効果がありませんでした。',
                $this->missileLabel($metadata['command_key'] ?? null),
                number_format($this->integer($metadata, 'ineffective_impacts')),
            ),
            'missile.dormancy_protected' => sprintf(
                '%s(%s,%s)に%sが落下しましたが、まるで時間が止まったかのように動かなくなった後、空中で自爆しました',
                $nation,
                $x,
                $y,
                $this->missileLabel($metadata['missile_key'] ?? null),
            ),
            'missile.recovery_protected' => sprintf(
                '%s(%s,%s)への%s攻撃は箱庭協定によって禁じられ、空中で自爆しました。',
                $nation,
                $x,
                $y,
                $this->missileLabel($metadata['missile_key'] ?? null),
            ),
            'missile.impact' => sprintf(
                '%s(%s,%s)に%sの%sが着弾し、%s。',
                $metadata['target_nation_name'] ?? $nation,
                $x,
                $y,
                $metadata['firing_nation_name'] ?? '他島',
                $this->missileLabel($metadata['missile_key'] ?? null),
                $this->missileEffectLabel($metadata['effect'] ?? null),
            ),
            'refugee_generated' => "{$nation}でミサイル攻撃による難民が発生しました。",
            'nation.dormant' => $this->publicDormancyMessage($metadata),
            'nation.dormancy_resumed' => "{$nation}に春が訪れ、活動を再開しました。",
            'nation.recovery_started' => "{$nation}は壊滅し、箱庭連合の復興支援による休戦に入りました。",
            'nation.recovery_ended' => "{$nation}の休戦期間が終了しました。",
            'nation.recovery_monsters_removed' => "{$nation}の怪獣は、復興支援に入った箱庭連合によって退治されました。",
            'karma.sanction_decided' => "箱庭連合は、{$nation}への制裁を決議しました。",
            'karma.sanction_launched' => sprintf(
                '%sに箱庭連合の制裁ミサイルが%s発発射されました。',
                $nation,
                number_format($this->integer($metadata, 'sanction_shots')),
            ),
            'trading_post.won_public' => $this->tradingPostWonMessage($metadata),
            default => "{$nation}で出来事がありました。",
        };
    }

    /** @param array<string, mixed> $metadata */
    private function publicDormancyMessage(array $metadata): string
    {
        $nation = is_string($metadata['nation_name'] ?? null) ? $metadata['nation_name'] : '島';
        $secretary = is_string($metadata['secretary_name'] ?? null)
            ? '秘書の'.$metadata['secretary_name']
            : '秘書';

        return ($metadata['reason'] ?? null) === 'collapse'
            ? "{$nation}から住民が居なくなった悲しみで{$secretary}が涙を流しました。{$nation}に冬が訪れています……"
            : "主が帰ってくるまでの間、{$secretary}が禁呪を解き放ちました。{$nation}に冬が訪れています……";
    }

    /** @param array<string, mixed> $metadata */
    private function tradingPostWonMessage(array $metadata): string
    {
        $nation = is_string($metadata['nation_name'] ?? null) ? $metadata['nation_name'] : '島';
        $price = number_format($this->integer($metadata, 'winning_bid'));
        if (($metadata['product_type'] ?? null) === 'item') {
            return sprintf(
                '%sが交易場で「%s Lv%s」を%s億円で落札しました。',
                $nation,
                is_string($metadata['item_name'] ?? null) ? $metadata['item_name'] : 'Item',
                number_format($this->integer($metadata, 'item_level')),
                $price,
            );
        }

        return sprintf(
            '%sが交易場で%s%s%sを%s億円で落札しました。',
            $nation,
            is_string($metadata['resource_name'] ?? null) ? $metadata['resource_name'] : '資源',
            number_format($this->integer($metadata, 'quantity')),
            is_string($metadata['unit_label'] ?? null) ? $metadata['unit_label'] : '',
            $price,
        );
    }

    /** @param array<string, mixed> $metadata */
    private function tradingPostSoldMessage(array $metadata): string
    {
        $product = ($metadata['product_type'] ?? null) === 'item'
            ? sprintf(
                '「%s Lv%s」',
                is_string($metadata['item_name'] ?? null) ? $metadata['item_name'] : 'Item',
                number_format($this->integer($metadata, 'item_level')),
            )
            : sprintf(
                '%s%s%s',
                is_string($metadata['resource_name'] ?? null) ? $metadata['resource_name'] : '資源',
                number_format($this->integer($metadata, 'quantity')),
                is_string($metadata['unit_label'] ?? null) ? $metadata['unit_label'] : '',
            );
        $details = '手数料:'.number_format($this->integer($metadata, 'trading_fee')).'億円';
        $overflow = $this->integer($metadata, 'seller_proceeds_overflow');
        if ($overflow > 0) {
            $details .= '、資金上限超過:'.number_format($overflow).'億円';
        }

        return sprintf(
            'あなたが競売に出した%sが落札され、%s億円を入手しました（%s）。',
            $product,
            number_format($this->integer($metadata, 'seller_proceeds')),
            $details,
        );
    }

    /** @param array<string, mixed> $metadata */
    private function publicDisasterMessage(array $metadata): string
    {
        $x = $this->publicCoordinate($metadata, 'center_x');
        $y = $this->publicCoordinate($metadata, 'center_y');

        return match ($metadata['disaster_key'] ?? null) {
            'earthquake' => "地震発生！！ 震源地は({$x},{$y})地点！！",
            'tsunami' => "({$x},{$y})付近で津波の被害が出ています。",
            'typhoon' => "({$x},{$y})付近で台風の被害が出ています。",
            'meteor_shower' => "({$x},{$y})付近に流星群が降り注ぎました。",
            'huge_meteor' => "({$x},{$y})に巨大隕石が落下しました。",
            'defense_self_destruct' => "({$x},{$y})で防衛施設が自爆しました。",
            'monument_flight' => '何かとてつもないものが落ちてきました！',
            'eruption' => "({$x},{$y})で火山噴火、山ができました。",
            default => "({$x},{$y})付近で災害が発生しました。",
        };
    }

    /** @param array<string, mixed> $metadata */
    private function publicCoordinate(array $metadata, string $key): string
    {
        return is_numeric($metadata[$key] ?? null) ? number_format((int) $metadata[$key]) : '?';
    }

    /**
     * Reduce raw audit metadata to an event-specific public DTO before the
     * formatter sees it. Secret coordinates, identities, draws, complete
     * missile impacts, and non-aid asset details cannot cross this boundary
     * by accident. Aid's applied transfer and the exact identity of a facility
     * after it is destroyed are explicit public exceptions.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function publicSafeMetadata(string $eventType, array $metadata): array
    {
        $keys = match ($eventType) {
            'award.granted' => ['nation_name', 'award_key', 'award_name'],
            'command.terrain_changed_public' => ['nation_name', 'command_key', 'x', 'y'],
            'command.forest_planted_public', 'command.logging_public',
            'command.seabed_base_built_public', 'land_subsidence.triggered',
            'refugee_generated' => ['nation_name'],
            'command.facility_built_public' => [
                'nation_name', 'facility_key', 'expanded', 'before_scale', 'facility_scale', 'x', 'y',
            ],
            'command.capital_relocated_public' => ['nation_name', 'from_x', 'from_y', 'x', 'y'],
            'command.attraction_started_public' => ['nation_name'],
            'command.money_aid_public' => [
                'sender_nation_name', 'receiver_nation_name', 'transferred_money',
            ],
            'command.food_aid_public' => [
                'sender_nation_name', 'receiver_nation_name', 'transferred_food_tons',
            ],
            'command.territory_expanded', 'territory.influenced' => [
                'nation_name', 'old_owner_nation_name', 'new_owner_nation_name', 'x', 'y',
            ],
            'disaster.triggered' => ['disaster_key', 'center_x', 'center_y'],
            'disaster.cell_damaged', 'fire.damaged' => [
                'nation_name', 'x', 'y', 'disaster_key', 'from_terrain_key',
                'to_terrain_key', 'removed_facility_key',
            ],
            'capital.disaster_damaged' => [
                'nation_name', 'x', 'y', 'disaster_key', 'damage_percent', 'after_population',
            ],
            'monster.damage_blocked', 'monster.damaged',
            'monster.killed',
            'monster.removed_by_terrain_event' => ['nation_name', 'monster_key', 'x', 'y'],
            'monster.spawned' => ['nation_name', 'monster_key', 'x', 'y', 'spawn_source'],
            'monster.defense_self_destructed' => [
                'nation_name', 'monster_key', 'center_x', 'center_y',
            ],
            'monster.nuclear_self_destructed' => [
                'nation_name', 'monster_key', 'center_x', 'center_y',
            ],
            'monster.moved' => ['nation_name', 'monster_key', 'x', 'y'],
            'monster.trampled' => ['nation_name', 'monster_key', 'x', 'y'],
            'missile.launched' => ['nation_name', 'command_key', 'fired_shots'],
            'missile.ineffective_aggregated' => [
                'nation_name', 'command_key', 'ineffective_impacts',
            ],
            'missile.dormancy_protected', 'missile.recovery_protected' => [
                'nation_name', 'x', 'y', 'missile_key',
            ],
            'missile.impact' => [
                'nation_name', 'target_nation_name', 'firing_nation_name',
                'missile_key', 'effect', 'x', 'y',
            ],
            'nation.dormant' => ['nation_name', 'reason', 'secretary_name'],
            'nation.dormancy_resumed' => ['nation_name'],
            'nation.recovery_started', 'nation.recovery_ended',
            'nation.recovery_monsters_removed' => ['nation_name'],
            'karma.sanction_decided' => ['nation_name'],
            'karma.sanction_launched' => ['nation_name', 'sanction_shots'],
            'trading_post.won_public' => ($metadata['product_type'] ?? null) === 'item'
                ? ['nation_name', 'product_type', 'winning_bid', 'item_name', 'item_level']
                : ['nation_name', 'product_type', 'winning_bid', 'resource_name', 'quantity', 'unit_label'],
            default => [],
        };
        $safe = array_intersect_key($metadata, array_fill_keys($keys, true));
        if ($eventType === 'command.facility_built_public') {
            if (in_array($safe['facility_key'] ?? null, ['missile_base', 'seabed_base'], true)) {
                return [
                    'nation_name' => $safe['nation_name'] ?? null,
                    'masked_facility' => $safe['facility_key'] === 'missile_base' ? 'forest' : 'seabed_base',
                ];
            }
            $safe['facility_key'] = match ($safe['facility_key'] ?? null) {
                'decoy' => 'defense',
                default => $safe['facility_key'] ?? null,
            };
        }
        if ($eventType === 'monster.killed' && is_numeric($metadata['killer_nation_id'] ?? null)) {
            $safe['reward_distributed'] = true;
        }
        if ($eventType === 'monster.trampled') {
            $safe['location_label'] = $this->publicAffectedLocationLabel($metadata);
        }

        return $safe;
    }

    /** @param array<string, mixed> $metadata */
    private function publicFacilityBuiltMessage(array $metadata): string
    {
        $nation = is_string($metadata['nation_name'] ?? null) ? $metadata['nation_name'] : '島';
        $maskedFacility = $metadata['masked_facility'] ?? null;
        if ($maskedFacility === 'forest') {
            return "こころなしか、{$nation}のどこかで森が増えた気がします。";
        }
        if ($maskedFacility === 'seabed_base') {
            return "{$nation}で海底基地が建設されたようです(?,?)。";
        }

        $facilityKey = $metadata['facility_key'] ?? null;
        if (in_array($facilityKey, ['farm', 'factory', 'mine'], true)) {
            return $this->productiveFacilityMessage(
                $metadata,
                $this->facilityLabel($facilityKey),
            );
        }

        return $this->constructionMessage(
            $metadata,
            $this->facilityLabel($facilityKey),
        );
    }

    /** @param array<string, mixed> $metadata */
    private function productiveFacilityMessage(array $metadata, string $facility): string
    {
        $message = sprintf(
            '%s(%s,%s)で%s整備が行われました。',
            $metadata['nation_name'] ?? 'Nation',
            number_format($this->integer($metadata, 'x')),
            number_format($this->integer($metadata, 'y')),
            $facility,
        );
        if (($metadata['expanded'] ?? false) !== true) {
            return $message;
        }

        return $message.sprintf(
            '（規模 %s → %s）',
            number_format($this->integer($metadata, 'before_scale')),
            number_format($this->integer($metadata, 'facility_scale')),
        );
    }

    /** @param array<string, mixed> $metadata */
    private function publicAffectedLocationLabel(array $metadata): string
    {
        $facility = $metadata['pre_impact_facility_key'] ?? $metadata['removed_facility_key'] ?? null;
        if (is_string($facility) && $facility !== '') {
            return $this->facilityLabel($facility);
        }

        return $this->terrainLabel(
            $metadata['pre_impact_terrain_key'] ?? $metadata['from_terrain_key'] ?? null,
        );
    }

    /** @param array<string, mixed> $metadata */
    private function publicAggregationKey(string $eventType, array $metadata): ?string
    {
        if ($eventType !== 'refugee_generated') {
            return null;
        }
        if (is_numeric($metadata['queue_item_id'] ?? null)) {
            return 'queue:'.(int) $metadata['queue_item_id'];
        }

        return implode(':', [
            (string) ($metadata['nation_id'] ?? ''),
            (string) ($metadata['recipient_nation_id'] ?? ''),
            (string) ($metadata['missile_key'] ?? ''),
        ]);
    }

    /**
     * Refugee audit rows remain impact-level records. The public projection
     * shows one deliberately amount-free line per launch and target.
     *
     * @param  list<array<string, mixed>>  $events
     * @return list<array<string, mixed>>
     */
    private function aggregatePublicRefugees(array $events): array
    {
        $result = [];
        $seen = [];
        foreach ($events as $event) {
            if (($event['type'] ?? null) !== 'refugee_generated') {
                $result[] = $event;

                continue;
            }
            $key = $event['target_turn'].':'.($event['_aggregation_key'] ?? 'unknown');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $event;
        }

        return $result;
    }

    /**
     * @param  list<object>  $rows
     * @return array<string, array<int, array{x: int, y: int}>>
     */
    private function subjectCoordinates(array $rows): array
    {
        $cellIds = [];
        $itemIds = [];
        foreach ($rows as $row) {
            if ($row->subject_type === MapCell::class && $row->subject_id !== null) {
                $cellIds[] = (int) $row->subject_id;
            }
            if ($row->subject_type === NationCommandQueueItem::class && $row->subject_id !== null) {
                $itemIds[] = (int) $row->subject_id;
            }
        }

        return [
            MapCell::class => MapCell::query()->whereIn('id', array_values(array_unique($cellIds)))
                ->get(['id', 'x', 'y'])->mapWithKeys(static fn (MapCell $cell): array => [
                    $cell->id => ['x' => $cell->x, 'y' => $cell->y],
                ])->all(),
            NationCommandQueueItem::class => NationCommandQueueItem::query()
                ->whereIn('id', array_values(array_unique($itemIds)))
                ->get(['id', 'target_x', 'target_y'])
                ->mapWithKeys(static fn (NationCommandQueueItem $item): array => [
                    $item->id => ['x' => $item->target_x, 'y' => $item->target_y],
                ])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, array<int, array{x: int, y: int}>>  $coordinates
     * @return array{x: int, y: int}|null
     */
    private function coordinate(object $row, array $metadata, array $coordinates): ?array
    {
        if ($row->x !== null && $row->y !== null) {
            return ['x' => (int) $row->x, 'y' => (int) $row->y];
        }
        if (isset($metadata['x'], $metadata['y']) && is_numeric($metadata['x']) && is_numeric($metadata['y'])) {
            return ['x' => (int) $metadata['x'], 'y' => (int) $metadata['y']];
        }
        if (isset($metadata['center_x'], $metadata['center_y'])
            && is_numeric($metadata['center_x']) && is_numeric($metadata['center_y'])) {
            return ['x' => (int) $metadata['center_x'], 'y' => (int) $metadata['center_y']];
        }
        if ($row->subject_id === null || ! is_string($row->subject_type)) {
            return null;
        }

        return $coordinates[$row->subject_type][(int) $row->subject_id] ?? null;
    }

    /** @return array<string, mixed> */
    private function metadata(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value)) {
            return [];
        }

        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $metadata */
    private function message(string $eventType, array $metadata, int $targetTurn, int $audienceNationId): string
    {
        return match ($eventType) {
            'nation.created' => sprintf('%sが成立しました。', $metadata['nation_name'] ?? '新しい島'),
            'command.success' => $this->commandLabel($metadata).'が完了しました。',
            'command.failed', 'command.invalid', 'command.insufficient_assets' => $this->commandFailureMessage($metadata),
            'terrain.changed' => sprintf(
                '%sを実行し、%sを%sへ変更しました。',
                $this->commandLabel($metadata),
                $this->terrainLabel($metadata['from_terrain_key'] ?? null),
                $this->terrainLabel($metadata['to_terrain_key'] ?? null),
            ),
            'facility.constructed' => $this->facilityLabel($metadata['facility_key'] ?? null).'を建設しました。',
            'facility.expanded' => sprintf(
                '%sを増築しました（規模 %s → %s）。',
                $this->facilityLabel($metadata['facility_key'] ?? null),
                number_format($this->integer($metadata, 'before_scale')),
                number_format($this->integer($metadata, 'facility_scale')),
            ),
            'command.buried_treasure' => $this->buriedTreasureMessage($metadata),
            'command.seabed_oil_search' => $this->seabedOilSearchMessage($metadata),
            'command.land_level_earthquake' => sprintf(
                '地ならし直後に地震が発生しました（中心 %s, %s）。',
                number_format($this->integer($metadata, 'center_x')),
                number_format($this->integer($metadata, 'center_y')),
            ),
            'disaster.triggered' => sprintf(
                '%sが発生しました（中心 %s, %s）。',
                $this->disasterLabel($metadata['disaster_key'] ?? null),
                number_format($this->integer($metadata, 'center_x')),
                number_format($this->integer($metadata, 'center_y')),
            ),
            'land_subsidence.triggered' => sprintf(
                '地盤沈下が発生し、浅瀬%sセルが海へ、陸地%sセルが浅瀬へ沈下しました。',
                number_format($this->integer($metadata, 'changed_to_sea_count')),
                number_format($this->integer($metadata, 'changed_to_shallow_count')),
            ),
            'disaster.cell_damaged' => $this->disasterCellDamageMessage($metadata),
            'capital.disaster_damaged' => sprintf(
                '%sにより首都人口が%s%%減少し、%s人になりました。',
                $this->disasterLabel($metadata['disaster_key'] ?? null),
                number_format($this->integer($metadata, 'damage_percent')),
                number_format($this->integer($metadata, 'after_population')),
            ),
            'fire.prevented' => '周囲の森または記念碑が火災を防ぎました。',
            'fire.damaged' => '火災により施設または都市が荒地になりました。',
            'oil.income' => $this->oilIncomeMessage($metadata),
            'oil.depleted' => '海底油田が枯渇し、中立の深海へ戻りました。',
            'settlement.appeared' => sprintf(
                '村が発生しました（人口%s人）。',
                number_format($this->integer($metadata, 'population')),
            ),
            'settlement.stage_transitioned' => sprintf(
                '%sが%sへ発展しました（人口%s人）。',
                $this->facilityLabel($metadata['from_facility_key'] ?? null, '集落'),
                $this->facilityLabel($metadata['to_facility_key'] ?? null, '集落'),
                number_format($this->integer($metadata, 'population')),
            ),
            'population.increased' => sprintf(
                '人口が%s人増加し、%s人になりました。',
                number_format($this->integer($metadata, 'increase')),
                number_format($this->integer($metadata, 'after')),
            ),
            'population.decreased' => sprintf(
                '人口が%s人減少し、%s人になりました。',
                number_format($this->integer($metadata, 'actual_loss')),
                number_format($this->integer($metadata, 'after')),
            ),
            'resource.food_shortage' => sprintf(
                '%sで食料が不足しています！',
                $metadata['nation_name'] ?? $metadata['audience_nation_name'] ?? '自国',
            ),
            'famine.applied' => sprintf(
                '飢餓により人口が%s人減少し、%s人になりました。',
                number_format($this->integer($metadata, 'actual_loss')),
                number_format($this->integer($metadata, 'after')),
            ),
            'facility.riot' => sprintf(
                '暴動により%sが荒地になりました。',
                $this->facilityLabel($metadata['facility_key'] ?? null),
            ),
            'resource.automatic_sale' => sprintf(
                '%sを%s売却し、%s億円を得ました。',
                $this->resourceLabel($metadata['resource_key'] ?? null),
                number_format($this->integer($metadata, 'sold')),
                number_format($this->integer($metadata, 'revenue')),
            ),
            'resource.food_overflow_resolved' => sprintf(
                '食料上限を超えた%s%sトンのうち%sトンを売却して%s億円を得て、%sトンを破棄しました。',
                $this->resourceLabel($metadata['resource_key'] ?? null),
                number_format($this->integer($metadata, 'requested_overflow_tons')),
                number_format($this->integer($metadata, 'sold_tons')),
                number_format($this->integer($metadata, 'revenue')),
                number_format($this->integer($metadata, 'discarded_tons')),
            ),
            'capacity.overflow' => $this->capacityOverflowMessage($metadata),
            'resource.food_produced' => sprintf(
                '農場で小麦%sトンを生産しました。',
                number_format($this->integer($metadata, 'applied_tons')),
            ),
            'resource.industrial_produced' => sprintf(
                '工場で工業品%sユニットを生産しました。',
                number_format($this->integer($metadata, 'produced_units')),
            ),
            'resource.mineral_produced' => sprintf(
                '採掘場で鉱物%sトンを生産しました。',
                number_format($this->integer($metadata, 'produced_units')),
            ),
            'resource.food_consumed' => sprintf(
                '人口維持のため栄養%sを消費しました。',
                number_format($this->integer($metadata, 'supplied_nutrition')),
            ),
            'capacity.applied' => sprintf(
                'ターン終了時の保有資金は%s億円、食料は%sトンです。',
                number_format($this->integer($metadata, 'money')),
                number_format($this->integer($metadata, 'food_tons')),
            ),
            'monster.spawned' => sprintf(
                '%sが出現しました（HP %s）。',
                $this->monsterLabel($metadata['monster_key'] ?? null),
                number_format($this->integer($metadata, 'initial_hp')),
            ),
            'monster.moved' => sprintf(
                '%sが移動しました。',
                $this->monsterLabel($metadata['monster_key'] ?? null),
            ),
            'monster.trampled' => sprintf(
                '%sが%s(%s,%s)を踏み荒らしました。',
                $this->monsterLabel($metadata['monster_key'] ?? null),
                $this->trampledLocationLabel($metadata),
                number_format($this->integer($metadata, 'x')),
                number_format($this->integer($metadata, 'y')),
            ),
            'monster.stayed' => sprintf(
                '%sが%sため、その場に留まりました。',
                $this->monsterLabel($metadata['monster_key'] ?? null),
                ($metadata['reason'] ?? null) === 'hardened' ? '硬化している' : '移動できなかった',
            ),
            'monster.damage_blocked' => sprintf(
                '%sは硬化中のため攻撃を防ぎました。',
                $this->monsterLabel($metadata['monster_key'] ?? null),
            ),
            'monster.damaged' => sprintf(
                '%sへ攻撃し、HPを%sにしました。',
                $this->monsterLabel($metadata['monster_key'] ?? null),
                number_format($this->integer($metadata, 'after_hp')),
            ),
            'monster.killed' => sprintf(
                '%sが倒されました（撃破報酬の受取国なし）。',
                $this->monsterLabel($metadata['monster_key'] ?? null),
            ),
            'monster.reward_distributed' => $this->monsterRewardMessage($metadata, $audienceNationId),
            'monster.item_drop_received' => sprintf(
                '怪獣の戦利品として「%s Lv%s」を入手しました。',
                is_string($metadata['item_name'] ?? null) ? $metadata['item_name'] : 'Item',
                number_format($this->integer($metadata, 'item_level')),
            ),
            'monster.item_drop_inventory_full' => '倉庫がいっぱいのため、怪獣の戦利品を受け取れませんでした。',
            'trading_post.sold_private' => $this->tradingPostSoldMessage($metadata),
            'monster.defense_self_destructed' => sprintf(
                '%sが防衛施設へ接触し、施設とともに消滅しました。',
                $this->monsterLabel($metadata['monster_key'] ?? null),
            ),
            'monster.nuclear_self_destructed' => 'メカいのら零式が突然輝きだし、とてつもない爆発を起こしました（撃破報酬なし）。',
            'monster.removed_by_terrain_event' => sprintf(
                '%sが地形変化により消滅しました（撃破報酬なし）。',
                $this->monsterLabel($metadata['monster_key'] ?? null),
            ),
            'command.automatic_finance' => $this->financeMessage($metadata, true),
            'command.finance' => $this->financeMessage($metadata, false),
            'nation.idle_counter_changed' => sprintf(
                '連続資金繰りターン数は%sです。',
                number_format($this->integer($metadata, 'after')),
            ),
            'command.forest_planted_public' => 'こころなしか、どこかで森が増えた気がします。',
            'command.forest_planted_private' => sprintf(
                '%s(%s,%s)で植林しました。',
                $metadata['nation_name'] ?? '自国',
                number_format($this->integer($metadata, 'x')),
                number_format($this->integer($metadata, 'y')),
            ),
            'command.missile_base_built_public' => 'こころなしか、どこかで森が増えた気がします。',
            'command.missile_base_built_private' => $this->privateConstructionMessage($metadata, 'ミサイル基地'),
            'command.seabed_base_built_public' => 'どこかで海底基地が建設されたようです(?,?)。',
            'command.seabed_base_built_private' => $this->privateConstructionMessage($metadata, '海底基地'),
            'command.decoy_built_public' => $this->constructionMessage($metadata, '防衛施設'),
            'command.decoy_built_private' => $this->privateConstructionMessage($metadata, 'ハリボテ'),
            'command.facility_built_public' => $this->publicFacilityBuiltMessage($metadata),
            'command.logging_public' => 'こころなしか、どこかで森が減った気がします。',
            'command.logging_private' => sprintf(
                '%s(%s,%s)で伐採し、%s億円を得ました。',
                $metadata['nation_name'] ?? '自国',
                number_format($this->integer($metadata, 'x')),
                number_format($this->integer($metadata, 'y')),
                number_format($this->integer($metadata, 'applied_money')),
            ),
            'command.territory_expanded' => $this->ownershipTransferMessage($metadata, '領土拡張'),
            'territory.influenced' => $this->ownershipTransferMessage($metadata, '領地感化'),
            'command.capital_relocated' => sprintf(
                '首都を(%s,%s)から(%s,%s)へ遷都しました。',
                number_format($this->integer($metadata, 'from_x')),
                number_format($this->integer($metadata, 'from_y')),
                number_format($this->integer($metadata, 'x')),
                number_format($this->integer($metadata, 'y')),
            ),
            'command.monument_launched' => sprintf(
                '座標(%s,%s)の記念碑を対象Nationへ発射しました。',
                number_format($this->integer($metadata, 'source_x')),
                number_format($this->integer($metadata, 'source_y')),
            ),
            'command.attraction_started' => '誘致活動を開始しました。',
            'command.money_aid_transferred' => $this->moneyAidMessage($metadata, true),
            'command.money_aid_received' => $this->moneyAidMessage($metadata, false),
            'command.food_aid_transferred' => $this->foodAidMessage($metadata, true),
            'command.food_aid_received' => $this->foodAidMessage($metadata, false),
            'command.monster_dispatched' => sprintf(
                '%sを対象Nationへ派遣しました。',
                $this->monsterLabel($metadata['monster_key'] ?? null),
            ),
            'missile.launch_failed' => sprintf(
                '%sは発射基地の状態または資金不足のため1発も発射できませんでした。',
                $this->missileLabel($metadata['command_key'] ?? null),
            ),
            'missile.launched' => sprintf(
                '%sが%sを%s発を発射しました。',
                $metadata['nation_name'] ?? 'Nation',
                $this->missileLabel($metadata['command_key'] ?? null),
                number_format($this->integer($metadata, 'fired_shots')),
            ),
            'missile.ineffective_aggregated' => sprintf(
                '%sのうち%s発は効果がありませんでした。',
                $this->missileLabel($metadata['command_key'] ?? null),
                number_format($this->integer($metadata, 'ineffective_impacts')),
            ),
            'missile.defense_intercepted' => sprintf(
                '防衛施設が%s発のミサイルを迎撃しました。',
                number_format(max(1, $this->integer($metadata, 'intercepted_impacts'))),
            ),
            'missile.launch_detail' => $this->missileLaunchDetailMessage($metadata),
            'secretary.missile_intercepted' => sprintf(
                '%sが%s発のミサイルを迎撃しました。',
                $this->secretaryLabel($metadata),
                number_format(max(1, $this->integer($metadata, 'intercepted_impacts'))),
            ),
            'missile.impact' => $this->missileImpactMessage($metadata),
            'karma.spp_self_destruct_setup' => sprintf(
                '%s「%s様……先ほどのSPPミサイルの本数ですが……」（カルマ +20）',
                is_string($metadata['secretary_name'] ?? null) ? $metadata['secretary_name'] : '秘書',
                $metadata['player_address'] ?? '島主',
            ),
            'refugee_generated' => sprintf(
                'ミサイル被害により難民%s人が発生しました。',
                number_format($this->integer($metadata, 'generated_population')),
            ),
            'refugee_received' => sprintf(
                '難民%s人を受け入れました。',
                number_format($this->integer($metadata, 'received_population')),
            ),
            'turn.completed' => "第{$targetTurn}ターンが完了しました。",
            'turn.summary' => "第{$targetTurn}ターンの資源変化",
            default => '島で出来事がありました。',
        };
    }

    /**
     * Preserve one audit row per impact, but project at most one row of each
     * defense kind per target turn for this owner Nation.
     *
     * @param  list<array<string, mixed>>  $events
     * @return list<array<string, mixed>>
     */
    private function aggregateMissileDefenseEvents(array $events): array
    {
        $aggregated = [];
        $indexes = [];
        foreach ($events as $event) {
            $type = $event['type'] ?? null;
            if (! in_array($type, ['missile.defense_intercepted', 'secretary.missile_intercepted'], true)) {
                $aggregated[] = $event;

                continue;
            }
            $key = $event['target_turn'].':'.$type;
            if (! array_key_exists($key, $indexes)) {
                $metadata = is_array($event['_metadata'] ?? null) ? $event['_metadata'] : [];
                $metadata['intercepted_impacts'] = 1;
                $event['_metadata'] = $metadata;
                $event['message'] = $this->message($type, $metadata, (int) $event['target_turn'], 0);
                $indexes[$key] = count($aggregated);
                $aggregated[] = $event;

                continue;
            }
            $index = $indexes[$key];
            $metadata = is_array($aggregated[$index]['_metadata'] ?? null)
                ? $aggregated[$index]['_metadata']
                : [];
            $metadata['intercepted_impacts'] = ((int) ($metadata['intercepted_impacts'] ?? 1)) + 1;
            $aggregated[$index]['_metadata'] = $metadata;
            $aggregated[$index]['message'] = $this->message(
                $type,
                $metadata,
                (int) $event['target_turn'],
                0,
            );
        }

        return $aggregated;
    }

    /** @param array<string, mixed> $metadata */
    private function trampledLocationLabel(array $metadata): string
    {
        $facility = $metadata['pre_impact_facility_key'] ?? $metadata['removed_facility_key'] ?? null;
        if (is_string($facility) && $facility !== '') {
            return $this->facilityLabel($facility);
        }

        return $this->terrainLabel(
            $metadata['pre_impact_terrain_key'] ?? $metadata['from_terrain_key'] ?? null,
        );
    }

    /** @param array<string, mixed> $metadata */
    private function commandFailureMessage(array $metadata): string
    {
        $command = $this->commandLabel($metadata);
        $nation = is_string($metadata['nation_name'] ?? null) && $metadata['nation_name'] !== ''
            ? $metadata['nation_name']
            : '自国';
        $x = $this->integer($metadata, 'x');
        $y = $this->integer($metadata, 'y');
        $prefix = sprintf('%s(%s,%s)で行われようとしていた%sは、', $nation, $x, $y, $command);
        $failureReason = $metadata['failure_reason'] ?? $metadata['failure_code'] ?? null;
        $reason = match ($failureReason) {
            'insufficient_funds', 'insufficient_money' => '資金不足のため実行できませんでした。',
            'insufficient_resource', 'insufficient_resources' => '必要な資源が不足しているため実行できませんでした。',
            'missing_adjacent_territory', 'no_adjacent_owned_land' => '隣接する自国領地がないため実行できませんでした。',
            'foreign_adjacent_water' => '他国領に接する水域のため実行できませんでした。',
            'foreign_owned' => '他国領のため実行できませんでした。',
            'not_owned', 'ownership_mismatch' => '自国領ではないため実行できませんでした。',
            'already_owned' => 'すでに所有地となっているため実行できませんでした。',
            'occupied_by_monster', 'monster_occupied' => '怪獣が存在するため実行できませんでした。',
            'facility_exists', 'facility_not_empty' => 'すでに施設が存在するため実行できませんでした。',
            'no_target', 'target_missing' => '対象地点が存在しないため実行できませんでした。',
            'capital_protected' => '首都を変更できないため実行できませんでした。',
            'invalid_target_nation' => '対象Nationがactiveではないか存在しないため実行できませんでした。',
            'same_nation_target' => '自国を対象にできないcommandのため実行できませんでした。',
            'invalid_parameter' => 'commandの指定内容が不正なため実行できませんでした。',
            'no_launch_base' => '利用可能な発射基地がないため実行できませんでした。',
            'invalid_facility', 'invalid_facility_scale' => '必要な施設の状態ではないため実行できませんでした。',
            'invalid_terrain' => match ($metadata['command_key'] ?? null) {
                'build_farm' => '農場建設可能な平地ではありませんでした。',
                'build_factory' => '工場建設可能な平地ではありませんでした。',
                'build_mine' => '採掘場建設可能な山ではありませんでした。',
                'reclaim' => '埋め立て可能な海または浅瀬ではありませんでした。',
                default => "{$command}を実行可能な地形ではありませんでした。",
            },
            'ruleset_mismatch' => '現在のrulesetに属するcommandではないため実行できませんでした。',
            'ceasefire_prohibited' => '箱庭協定による休戦中のため実行できませんでした。',
            default => '実行条件を満たさなくなったため実行できませんでした。',
        };

        return $prefix.$reason;
    }

    /** @param array<string, mixed> $metadata
     * @return array<string, array{start: int, end: int, delta: int}>
     */
    private function turnSummaryProjection(array $metadata): array
    {
        $summary = is_array($metadata['summary'] ?? null) ? $metadata['summary'] : [];
        $result = [];
        foreach (['money', 'population', 'food'] as $key) {
            $values = is_array($summary[$key] ?? null) ? $summary[$key] : [];
            $result[$key] = [
                'start' => $this->integer($values, 'start'),
                'end' => $this->integer($values, 'end'),
                'delta' => $this->integer($values, 'delta'),
            ];
        }

        return $result;
    }

    /**
     * Owner pages contain both the Nation's safe public timeline and its
     * private detail rows. Suppress only known one-to-one public companions;
     * unrelated impacts, rewards, disasters, and other gameplay results stay.
     *
     * @param  list<array<string, mixed>>  $events
     * @return list<array<string, mixed>>
     */
    private function preferOwnerEventDetails(array $events): array
    {
        $specializedGenericKeys = [];
        foreach ($events as $event) {
            $key = $this->specializedOwnerGenericKey($event);
            if ($key !== null) {
                $specializedGenericKeys[$key] = true;
            }
        }

        $events = array_values(array_filter($events, function (array $event) use ($specializedGenericKeys): bool {
            if (! in_array($event['type'] ?? null, ['terrain.changed', 'facility.constructed', 'facility.expanded'], true)) {
                return true;
            }

            $key = $this->ownerCoordinateKey(
                str_starts_with((string) $event['type'], 'terrain.') ? 'terrain' : 'facility',
                $event,
            );

            return $key === null || ! isset($specializedGenericKeys[$key]);
        }));

        $detailCounts = [];
        foreach ($events as $event) {
            if (($event['_visibility'] ?? null) === 'public') {
                continue;
            }
            $key = $this->ownerDetailCompanionKey($event);
            if ($key !== null) {
                $detailCounts[$key] = ($detailCounts[$key] ?? 0) + 1;
            }
        }

        $result = [];
        foreach ($events as $event) {
            if (($event['_visibility'] ?? null) !== 'public') {
                $result[] = $event;

                continue;
            }
            $key = $this->ownerPublicCompanionKey($event);
            if ($key === null || ($detailCounts[$key] ?? 0) === 0) {
                $result[] = $event;

                continue;
            }
            if (! str_starts_with($key, 'missile:')) {
                $detailCounts[$key]--;
            }
        }

        return $result;
    }

    /** @param array<string, mixed> $event */
    private function specializedOwnerGenericKey(array $event): ?string
    {
        $prefix = match ($event['type'] ?? null) {
            'command.forest_planted_private' => 'terrain',
            'command.missile_base_built_private', 'command.seabed_base_built_private',
            'command.decoy_built_private' => 'facility',
            default => null,
        };

        return $prefix === null ? null : $this->ownerCoordinateKey($prefix, $event);
    }

    /** @param array<string, mixed> $event */
    private function ownerDetailCompanionKey(array $event): ?string
    {
        $metadata = is_array($event['_metadata'] ?? null) ? $event['_metadata'] : [];
        $turn = (int) ($event['target_turn'] ?? 0);

        return match ($event['type'] ?? null) {
            'terrain.changed' => $this->ownerCoordinateKey('terrain', $event),
            'facility.constructed', 'facility.expanded' => $this->ownerCoordinateKey('facility', $event),
            'command.forest_planted_private', 'command.missile_base_built_private' => "forest:{$turn}",
            'command.seabed_base_built_private' => "seabed:{$turn}",
            'command.decoy_built_private' => $this->ownerCoordinateKey('facility', $event),
            'command.logging_private' => "logging:{$turn}",
            'command.capital_relocated' => $this->ownerCoordinateKey('capital', $event),
            'command.attraction_started' => "attraction:{$turn}",
            'command.money_aid_transferred', 'command.money_aid_received' => $this->ownerAidKey('money', $turn, $metadata),
            'command.food_aid_transferred', 'command.food_aid_received' => $this->ownerAidKey('food', $turn, $metadata),
            'missile.launch_detail' => is_numeric($metadata['queue_item_id'] ?? null)
                ? "missile:{$turn}:".(int) $metadata['queue_item_id']
                : null,
            default => null,
        };
    }

    /** @param array<string, mixed> $event */
    private function ownerPublicCompanionKey(array $event): ?string
    {
        $metadata = is_array($event['_metadata'] ?? null) ? $event['_metadata'] : [];
        $turn = (int) ($event['target_turn'] ?? 0);

        return match ($event['type'] ?? null) {
            'command.terrain_changed_public' => $this->ownerCoordinateKey('terrain', $event),
            'command.facility_built_public' => $this->ownerCoordinateKey('facility', $event),
            'command.forest_planted_public' => "forest:{$turn}",
            'command.seabed_base_built_public' => "seabed:{$turn}",
            'command.logging_public' => "logging:{$turn}",
            'command.capital_relocated_public' => $this->ownerCoordinateKey('capital', $event),
            'command.attraction_started_public' => "attraction:{$turn}",
            'command.money_aid_public' => $this->ownerAidKey('money', $turn, $metadata),
            'command.food_aid_public' => $this->ownerAidKey('food', $turn, $metadata),
            'missile.launched', 'missile.ineffective_aggregated' => is_numeric($metadata['queue_item_id'] ?? null)
                ? "missile:{$turn}:".(int) $metadata['queue_item_id']
                : null,
            default => null,
        };
    }

    /** @param array<string, mixed> $event */
    private function ownerCoordinateKey(string $prefix, array $event): ?string
    {
        $metadata = is_array($event['_metadata'] ?? null) ? $event['_metadata'] : [];
        if (! is_numeric($metadata['x'] ?? null) || ! is_numeric($metadata['y'] ?? null)) {
            return null;
        }

        return implode(':', [
            $prefix,
            (string) ((int) ($event['target_turn'] ?? 0)),
            (string) ((int) $metadata['x']),
            (string) ((int) $metadata['y']),
        ]);
    }

    /** @param array<string, mixed> $metadata */
    private function ownerAidKey(string $resource, int $turn, array $metadata): ?string
    {
        $amountKey = $resource === 'money' ? 'transferred_money' : 'transferred_food_tons';
        if (! is_numeric($metadata['sender_nation_id'] ?? null)
            || ! is_numeric($metadata['receiver_nation_id'] ?? null)
            || ! is_numeric($metadata[$amountKey] ?? null)) {
            return null;
        }

        return implode(':', [
            'aid',
            $resource,
            (string) $turn,
            (string) ((int) $metadata['sender_nation_id']),
            (string) ((int) $metadata['receiver_nation_id']),
            (string) ((int) $metadata[$amountKey]),
        ]);
    }

    /**
     * Published public rulesets aggregate ineffective impacts per launch.
     * The firing Nation's existing private launch detail is projected into
     * coordinate-level player entries without widening that visibility.
     *
     * @param  list<array<string, mixed>>  $events
     * @return list<array<string, mixed>>
     */
    private function expandMissileLaunchDetails(array $events): array
    {
        $result = [];
        foreach ($events as $event) {
            if (($event['type'] ?? null) !== 'missile.launch_detail') {
                $result[] = $event;

                continue;
            }
            $metadata = is_array($event['_metadata'] ?? null) ? $event['_metadata'] : [];
            $impacts = is_array($metadata['impacts'] ?? null) ? $metadata['impacts'] : [];
            $grouped = [];
            foreach ($impacts as $impact) {
                if (! is_array($impact)) {
                    continue;
                }
                $effect = is_string($impact['effect'] ?? null) ? $impact['effect'] : 'ineffective_sea';
                if (($impact['meaningful'] ?? false) === true
                    || in_array($effect, ['defense_intercepted', 'secretary_intercepted'], true)
                    || ! is_numeric($impact['x'] ?? null) || ! is_numeric($impact['y'] ?? null)) {
                    continue;
                }
                $x = (int) $impact['x'];
                $y = (int) $impact['y'];
                $key = "{$x}:{$y}:{$effect}";
                if (! isset($grouped[$key])) {
                    $grouped[$key] = ['x' => $x, 'y' => $y, 'effect' => $effect, 'hits' => 0, ...$impact];
                }
                $grouped[$key]['hits']++;
            }
            if ($grouped === []) {
                $result[] = $event;

                continue;
            }
            // Coordinate-level ineffective rows supplement the firing Nation's
            // private launch record; they must not replace its target, cost,
            // and complete impact list.
            $result[] = $event;
            foreach (array_values($grouped) as $index => $impact) {
                $hits = (int) $impact['hits'];
                $location = $this->ineffectiveImpactLocation($impact);
                $missile = $this->missileLabel($metadata['command_key'] ?? null);
                $hitText = $hits > 1 ? number_format($hits).'発' : '';
                $result[] = [
                    ...$event,
                    'id' => -(((int) $event['id'] * 1_000) + $index + 1),
                    'type' => 'missile.ineffective_impact',
                    'message' => sprintf(
                        '%sが%s(%s,%s)に%s着弾しましたが、効果はありませんでした。',
                        $missile,
                        $location,
                        number_format((int) $impact['x']),
                        number_format((int) $impact['y']),
                        $hitText,
                    ),
                    'summary' => null,
                    '_metadata' => [...$metadata, ...$impact],
                ];
            }
        }

        return $result;
    }

    /** @param array<string, mixed> $impact */
    private function ineffectiveImpactLocation(array $impact): string
    {
        $terrain = $impact['terrain_key'] ?? null;
        if (is_string($terrain)) {
            return $this->terrainLabel($terrain);
        }

        return match ($impact['effect'] ?? null) {
            'ineffective_barren_land' => '荒地',
            'dormant_owner_protected' => '保護対象領地',
            default => '海',
        };
    }

    /**
     * Keep one structured event per impact in audit_events, but present the
     * population totals once per launch and target turn in the player log.
     *
     * @param  list<array<string, mixed>>  $events
     * @return list<array<string, mixed>>
     */
    private function aggregateRefugeeEvents(array $events, int $audienceNationId): array
    {
        $result = [];
        $indexes = [];
        foreach ($events as $event) {
            $type = $event['type'] ?? null;
            $metadata = is_array($event['_metadata'] ?? null) ? $event['_metadata'] : [];
            if (! in_array($type, ['refugee_generated', 'refugee_received'], true)) {
                unset($event['_metadata']);
                $result[] = $event;

                continue;
            }

            $launchIdentity = is_numeric($metadata['queue_item_id'] ?? null)
                ? 'queue:'.(int) $metadata['queue_item_id']
                : implode(':', [
                    'fallback',
                    (string) ($metadata['source_nation_id'] ?? ''),
                    (string) ($metadata['recipient_nation_id'] ?? ''),
                    (string) ($metadata['missile_key'] ?? ''),
                ]);
            $key = implode(':', [(string) $event['target_turn'], (string) $type, $launchIdentity]);
            $populationKey = $type === 'refugee_generated'
                ? 'generated_population'
                : 'received_population';
            if (! isset($indexes[$key])) {
                $indexes[$key] = count($result);
                $result[] = $event;

                continue;
            }

            $index = $indexes[$key];
            $existingMetadata = is_array($result[$index]['_metadata'] ?? null)
                ? $result[$index]['_metadata']
                : [];
            $existingMetadata[$populationKey] = $this->integer($existingMetadata, $populationKey)
                + $this->integer($metadata, $populationKey);
            if ($type === 'refugee_received') {
                $existingMetadata['generated_population'] = $this->integer($existingMetadata, 'generated_population')
                    + $this->integer($metadata, 'generated_population');
                $existingMetadata['unreceived_population'] = $this->integer($existingMetadata, 'unreceived_population')
                    + $this->integer($metadata, 'unreceived_population');
            }
            $result[$index]['_metadata'] = $existingMetadata;
            $result[$index]['message'] = $this->message(
                (string) $type,
                $existingMetadata,
                (int) $event['target_turn'],
                $audienceNationId,
            );
        }

        foreach ($result as &$event) {
            unset($event['_metadata']);
        }
        unset($event);

        return $result;
    }

    /** @param array<string, mixed> $metadata */
    private function buriedTreasureMessage(array $metadata): string
    {
        $overflow = $this->integer($metadata, 'overflow_money');

        if ($overflow > 0) {
            return sprintf(
                '埋蔵金%s億円を発見し、%s億円を受け取りました（収容上限超過 %s億円）。',
                number_format($this->integer($metadata, 'reward_money')),
                number_format($this->integer($metadata, 'applied_money')),
                number_format($overflow),
            );
        }

        return sprintf(
            '埋蔵金%s億円を発見し、%s億円を受け取りました。',
            number_format($this->integer($metadata, 'reward_money')),
            number_format($this->integer($metadata, 'applied_money')),
        );
    }

    /** @param array<string, mixed> $metadata */
    private function moneyAidMessage(array $metadata, bool $senderView): string
    {
        $transferred = $this->integer($metadata, 'transferred_money');
        if ($transferred === 0) {
            return $senderView
                ? sprintf(
                    '%sは資金収容上限に達していたため、資金援助を送れませんでした。',
                    $metadata['receiver_nation_name'] ?? '対象Nation',
                )
                : sprintf(
                    '資金収容上限に達していたため、%sからの資金援助を受け取れませんでした。',
                    $metadata['sender_nation_name'] ?? '他Nation',
                );
        }

        return $senderView
            ? sprintf(
                '%sへ資金援助として%s億円を送りました。',
                $metadata['receiver_nation_name'] ?? '対象Nation',
                number_format($transferred),
            )
            : sprintf(
                '%sから資金援助として%s億円を受け取りました。',
                $metadata['sender_nation_name'] ?? '他Nation',
                number_format($transferred),
            );
    }

    /** @param array<string, mixed> $metadata */
    private function foodAidMessage(array $metadata, bool $senderView): string
    {
        $transferred = $this->integer($metadata, 'transferred_food_tons');
        if ($transferred === 0) {
            return $senderView
                ? sprintf(
                    '%sは食料収容上限に達していたため、食料援助を送れませんでした。',
                    $metadata['receiver_nation_name'] ?? '対象Nation',
                )
                : sprintf(
                    '食料収容上限に達していたため、%sからの食料援助を受け取れませんでした。',
                    $metadata['sender_nation_name'] ?? '他Nation',
                );
        }

        return $senderView
            ? sprintf(
                '%sへ食料援助として%sトンを送りました。',
                $metadata['receiver_nation_name'] ?? '対象Nation',
                number_format($transferred),
            )
            : sprintf(
                '%sから食料援助として%sトンを受け取りました。',
                $metadata['sender_nation_name'] ?? '他Nation',
                number_format($transferred),
            );
    }

    /** @param array<string, mixed> $metadata */
    private function capacityOverflowMessage(array $metadata): string
    {
        $overflow = number_format($this->integer($metadata, 'overflow'));

        return match ($metadata['asset'] ?? null) {
            'money' => "資金が収容上限を{$overflow}億円超過しています。",
            'aggregate_food' => "食料が収容上限を{$overflow}トン超過しています。",
            'resource' => sprintf(
                '%sが収容上限を%s%s超過し、超過分を破棄しました。',
                $this->resourceLabel($metadata['resource_key'] ?? null),
                $overflow,
                $this->resourceUnitLabel($metadata['resource_key'] ?? null),
            ),
            default => "資源が収容上限を{$overflow}超過しています。",
        };
    }

    /** @param array<string, mixed> $metadata */
    private function seabedOilSearchMessage(array $metadata): string
    {
        $spent = number_format($this->integer($metadata, 'spent_money'));
        $denominator = max(1, $this->integer($metadata, 'denominator'));
        $chance = number_format(
            $this->integer($metadata, 'success_threshold') * 100 / $denominator,
            2,
        );
        $chance = rtrim(rtrim($chance, '0'), '.');

        return ($metadata['found'] ?? false) === true
            ? "海底油田の探索に成功しました（投入 {$spent}億円、成功率 {$chance}%）。"
            : "海底油田は発見できませんでした（投入 {$spent}億円、成功率 {$chance}%）。";
    }

    /** @param array<string, mixed> $metadata */
    private function oilIncomeMessage(array $metadata): string
    {
        if (($metadata['resource_key'] ?? null) === 'oil') {
            return sprintf(
                '海底油田から石油%s万バレルを産出しました。',
                number_format($this->integer($metadata, 'applied_units')),
            );
        }

        $overflow = $this->integer($metadata, 'overflow_money');
        $message = sprintf(
            '海底油田から%s億円の収入を得ました。',
            number_format($this->integer($metadata, 'applied_money')),
        );

        return $overflow > 0
            ? $message.sprintf('（収容上限超過 %s億円）', number_format($overflow))
            : $message;
    }

    /** @param array<string, mixed> $metadata */
    private function financeMessage(array $metadata, bool $automatic): string
    {
        return sprintf(
            '%s資金繰りにより%s億円を受け取りました。',
            $automatic ? '自動' : '',
            number_format($this->integer($metadata, 'applied')),
        );
    }

    /** @param array<string, mixed> $metadata */
    private function constructionMessage(array $metadata, string $facility): string
    {
        return sprintf(
            '%s(%s,%s)で%sが建設されました。',
            $metadata['nation_name'] ?? 'Nation',
            number_format($this->integer($metadata, 'x')),
            number_format($this->integer($metadata, 'y')),
            $facility,
        );
    }

    /** @param array<string, mixed> $metadata */
    private function privateConstructionMessage(array $metadata, string $facility): string
    {
        return sprintf(
            '%s(%s,%s)で%sを建設しました。',
            $metadata['nation_name'] ?? '自国',
            number_format($this->integer($metadata, 'x')),
            number_format($this->integer($metadata, 'y')),
            $facility,
        );
    }

    /** @param array<string, mixed> $metadata */
    private function missileLaunchDetailMessage(array $metadata): string
    {
        $firingBases = is_array($metadata['firing_bases'] ?? null) ? $metadata['firing_bases'] : [];
        $baseDetails = [];
        foreach ($firingBases as $base) {
            if (! is_array($base)) {
                continue;
            }
            $baseDetails[] = sprintf(
                '(%s,%s)から%s発',
                number_format(is_numeric($base['x'] ?? null) ? (int) $base['x'] : 0),
                number_format(is_numeric($base['y'] ?? null) ? (int) $base['y'] : 0),
                number_format(is_numeric($base['fired_shots'] ?? null) ? (int) $base['fired_shots'] : 0),
            );
        }
        $impacts = is_array($metadata['impacts'] ?? null) ? $metadata['impacts'] : [];
        $details = [];
        foreach ($impacts as $impact) {
            if (! is_array($impact)) {
                continue;
            }
            $x = is_numeric($impact['x'] ?? null) ? (int) $impact['x'] : 0;
            $y = is_numeric($impact['y'] ?? null) ? (int) $impact['y'] : 0;
            $effect = $this->missileEffectLabel($impact['effect'] ?? null);
            if (($impact['terrain_scorched'] ?? false) === true) {
                $effect .= '（怪獣がいた荒地は焦土化しました）';
            }
            $details[] = sprintf(
                '(%s,%s): %s',
                number_format($x),
                number_format($y),
                $effect,
            );
        }

        return sprintf(
            '%sを狙点(%s,%s)へ%s発を発射し、費用%s億円。発射基地: %s。着弾結果: %s',
            $this->missileLabel($metadata['command_key'] ?? null),
            number_format($this->integer($metadata, 'target_x')),
            number_format($this->integer($metadata, 'target_y')),
            number_format($this->integer($metadata, 'fired_shots')),
            number_format($this->integer($metadata, 'cost_money')),
            $baseDetails === [] ? '記録なし' : implode('、', $baseDetails),
            $details === [] ? '着弾なし' : implode('、', $details),
        );
    }

    /** @param array<string, mixed> $metadata */
    private function missileImpactMessage(array $metadata): string
    {
        return sprintf(
            '%sの%sが(%s,%s)へ着弾し、%s。',
            $metadata['firing_nation_name'] ?? 'Nation',
            $this->missileLabel($metadata['missile_key'] ?? null),
            number_format($this->integer($metadata, 'x')),
            number_format($this->integer($metadata, 'y')),
            $this->missileEffectLabel($metadata['effect'] ?? null),
        );
    }

    private function missileLabel(mixed $key): string
    {
        return match ($key) {
            'missile' => 'ミサイル',
            'pp_missile' => 'PPミサイル',
            'land_destruction_missile' => '陸地破壊弾',
            'spp_missile' => 'SPPミサイル',
            default => 'ミサイル',
        };
    }

    /** @param array<string, mixed> $metadata */
    private function secretaryLabel(array $metadata): string
    {
        $label = $metadata['secretary_label'] ?? null;

        return is_string($label) && $label !== '' ? $label : '秘書';
    }

    private function missileEffectLabel(mixed $effect): string
    {
        return match ($effect) {
            'monster_hit', 'damaged' => '怪獣へ命中しました',
            'killed' => '怪獣を撃破しました',
            'blocked' => '硬化中の怪獣に防がれました',
            'capital_damaged' => '首都人口へ被害を与えました',
            'capital_at_minimum' => '首都人口が最低人口のため効果はありませんでした',
            'water_facility_destroyed' => '水上施設を破壊しました',
            'land_scorched' => '土地を焼け跡にしました',
            'terrain_destroyed' => '陸地を破壊しました',
            'out_of_bounds_sea' => '狙点外の海へ落下し効果はありませんでした',
            'dormant_owner_protected' => '休眠Nation領へ落下し効果はありませんでした',
            'defense_intercepted' => '防衛施設に迎撃されました',
            'secretary_intercepted' => '最終防衛ラインに迎撃されました',
            'ineffective_barren_land' => '被害のない土地へ落下しました',
            'ineffective_sea' => '海へ落下し効果はありませんでした',
            default => '着弾結果が記録されました',
        };
    }

    private function disasterLabel(mixed $key): string
    {
        return match ($key) {
            'earthquake' => '地震',
            'tsunami' => '津波',
            'typhoon' => '台風',
            'meteor_shower' => '流星群',
            'huge_meteor' => '巨大隕石',
            'defense_self_destruct' => '防衛施設の自爆',
            'monument_flight' => 'とてつもない落下物',
            'eruption' => '噴火',
            'land_subsidence' => '地盤沈下',
            'fire' => '火災',
            default => '災害',
        };
    }

    /** @param array<string, mixed> $metadata */
    private function disasterCellDamageMessage(array $metadata): string
    {
        $removedFacilityKey = $metadata['removed_facility_key'] ?? null;
        if (is_string($removedFacilityKey) && $removedFacilityKey !== '') {
            return sprintf(
                '%sにより%sが失われ、%sになりました。',
                $this->disasterLabel($metadata['disaster_key'] ?? null),
                $this->facilityLabel($removedFacilityKey),
                $this->terrainLabel($metadata['to_terrain_key'] ?? null),
            );
        }

        return sprintf(
            '%sにより%sが%sへ変化しました。',
            $this->disasterLabel($metadata['disaster_key'] ?? null),
            $this->terrainLabel($metadata['from_terrain_key'] ?? null),
            $this->terrainLabel($metadata['to_terrain_key'] ?? null),
        );
    }

    /** @param array<string, mixed> $metadata */
    private function ownershipTransferMessage(array $metadata, string $cause): string
    {
        return sprintf(
            '%sにより、%s(%s,%s)の所有権が%sから%sへ移りました。',
            $cause,
            $metadata['new_owner_nation_name'] ?? 'Nation',
            number_format($this->integer($metadata, 'x')),
            number_format($this->integer($metadata, 'y')),
            $metadata['old_owner_nation_name'] ?? '中立',
            $metadata['new_owner_nation_name'] ?? 'Nation',
        );
    }

    /** @param array<string, mixed> $metadata */
    private function commandLabel(array $metadata): string
    {
        return match ($metadata['command_key'] ?? null) {
            'land_clear' => '整地',
            'land_level' => '地ならし',
            'reclaim' => '埋め立て',
            'excavate' => '掘削',
            'build_farm' => '農場建設',
            'build_factory' => '工場建設',
            'build_mine' => '採掘場建設',
            'logging' => '伐採',
            'territory_expand' => '領土拡張',
            'plant_forest' => '植林',
            'build_missile_base' => 'ミサイル基地建設',
            'build_defense_facility' => '防衛施設建設',
            'build_seabed_base' => '海底基地建設',
            'build_monument' => '記念碑建設',
            'build_decoy' => 'ハリボテ建築',
            'missile' => 'ミサイル発射',
            'pp_missile' => 'PPミサイル発射',
            'land_destruction_missile' => '陸地破壊弾発射',
            'spp_missile' => 'SPPミサイル発射',
            'monster_dispatch' => '怪獣派遣',
            'finance' => '資金繰り',
            'money_aid' => '資金援助',
            'food_aid' => '食料援助',
            'attraction' => '誘致活動',
            'relocate_capital' => '首都遷都',
            default => '開発計画',
        };
    }

    private function terrainLabel(mixed $key): string
    {
        return match ($key) {
            'sea' => '海',
            'shallow' => '浅瀬',
            'wasteland' => '荒地',
            'plain' => '平地',
            'forest' => '森',
            'mountain' => '山',
            'scorched' => '焼け跡',
            default => '地形',
        };
    }

    private function facilityLabel(mixed $key, string $fallback = '施設'): string
    {
        return match ($key) {
            'capital' => '首都',
            'village' => '村',
            'town' => '町',
            'city' => '都市',
            'farm' => '農場',
            'factory' => '工場',
            'mine' => '採掘場',
            'missile_base' => 'ミサイル基地',
            'seabed_oil_field' => '海底油田',
            'defense' => '防衛施設',
            'seabed_base' => '海底基地',
            'monument' => '記念碑',
            'decoy' => 'ハリボテ',
            default => $fallback,
        };
    }

    private function resourceLabel(mixed $key): string
    {
        return match ($key) {
            'industrial_goods' => '工業品',
            'minerals' => '鉱物',
            'wheat' => '小麦',
            'fish' => '魚',
            'monster_meat' => '怪獣肉',
            'oil' => '石油',
            default => '資源',
        };
    }

    private function resourceUnitLabel(mixed $key): string
    {
        return match ($key) {
            'industrial_goods' => 'ユニット',
            'minerals', 'wheat', 'fish', 'monster_meat' => 'トン',
            'oil' => '万バレル',
            default => '',
        };
    }

    /** @param array<string, mixed> $metadata */
    private function monsterRewardMessage(array $metadata, int $audienceNationId): string
    {
        $monster = $this->monsterLabel($metadata['monster_key'] ?? null);
        $isKiller = $this->integer($metadata, 'killer_nation_id') === $audienceNationId;
        $isHost = $this->integer($metadata, 'host_nation_id') === $audienceNationId;
        $money = number_format($this->nestedInteger($metadata, 'killer_money', 'applied'));
        $meat = number_format($this->nestedInteger($metadata, 'host_meat_food', 'applied'));

        if ($isKiller && $isHost) {
            return "{$monster}を撃破し、賞金{$money}億円と怪獣肉{$meat}トンを受け取りました。";
        }
        if ($isHost) {
            return "{$monster}が倒され、怪獣肉{$meat}トンを受け取りました。";
        }
        if (! $isKiller) {
            return "{$monster}が倒され、撃破報酬が配分されました。";
        }

        return "{$monster}を撃破し、賞金{$money}億円を受け取りました。";
    }

    private function monsterLabel(mixed $key): string
    {
        return match ($key) {
            'mecha_inora' => 'メカいのら',
            'mecha_inora_zero' => 'メカいのら零式',
            'inora' => 'いのら',
            'sanjira' => 'サンジラ',
            'red_inora' => 'レッドいのら',
            'dark_inora' => 'ダークいのら',
            'aoi_inora' => 'あおいのら',
            'inora_ghost' => 'いのらゴースト',
            'whale' => 'クジラ',
            'king_inora' => 'キングいのら',
            default => '怪獣',
        };
    }

    private function importance(string $eventType): string
    {
        return match ($eventType) {
            'command.failed', 'command.invalid', 'command.insufficient_assets', 'resource.food_shortage',
            'famine.applied', 'facility.riot', 'capacity.overflow', 'resource.food_overflow_resolved',
            'disaster.cell_damaged', 'capital.disaster_damaged', 'fire.damaged', 'oil.depleted',
            'monster.damage_blocked', 'monster.damaged', 'monster.defense_self_destructed',
            'monster.nuclear_self_destructed',
            'monster.removed_by_terrain_event' => 'warning',
            'missile.launch_failed' => 'warning',
            'command.buried_treasure', 'command.seabed_oil_search',
            'command.land_level_earthquake', 'disaster.triggered', 'fire.prevented', 'oil.income',
            'land_subsidence.triggered', 'monster.spawned', 'monster.reward_distributed', 'award.granted',
            'settlement.appeared', 'settlement.stage_transitioned' => 'notable',
            'missile.launched', 'missile.impact', 'command.capital_relocated',
            'command.capital_relocated_public', 'command.monument_launched' => 'notable',
            default => 'info',
        };
    }

    /** @param array<string, mixed> $metadata */
    private function integer(array $metadata, string $key): int
    {
        $value = $metadata[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }

    /** @param array<string, mixed> $metadata */
    private function nestedInteger(array $metadata, string $key, string $nestedKey): int
    {
        $value = $metadata[$key] ?? null;

        return is_array($value) && is_numeric($value[$nestedKey] ?? null)
            ? (int) $value[$nestedKey]
            : 0;
    }
}
