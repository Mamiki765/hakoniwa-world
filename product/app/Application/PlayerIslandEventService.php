<?php

namespace App\Application;

use App\Models\MapCell;
use App\Models\Nation;
use App\Models\NationCommandQueueItem;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class PlayerIslandEventService
{
    public const TURNS_PER_PAGE = 24;

    /** @var list<string> */
    private const ALLOWED_EVENT_TYPES = [
        'command.success',
        'command.failed',
        'command.invalid',
        'command.insufficient_assets',
        'terrain.changed',
        'facility.constructed',
        'facility.expanded',
        'command.buried_treasure',
        'command.seabed_oil_search',
        'command.land_level_earthquake',
        'disaster.triggered',
        'land_subsidence.triggered',
        'disaster.cell_damaged',
        'capital.disaster_damaged',
        'fire.prevented',
        'fire.damaged',
        'oil.income',
        'oil.depleted',
        'settlement.appeared',
        'settlement.stage_transitioned',
        'population.increased',
        'population.decreased',
        'resource.food_shortage',
        'famine.applied',
        'facility.riot',
        'resource.automatic_sale',
        'capacity.overflow',
        'monster.spawned',
        'monster.spawn_failed_no_settlement',
        'monster.moved',
        'monster.trampled',
        'monster.stayed',
        'monster.damage_blocked',
        'monster.damaged',
        'monster.killed',
        'monster.reward_distributed',
        'monster.defense_self_destructed',
        'monster.removed_by_terrain_event',
        'command.automatic_finance',
        'command.finance',
        'nation.idle_counter_changed',
        'command.forest_planted_public',
        'command.forest_planted_private',
        'command.missile_base_built_public',
        'command.missile_base_built_private',
        'command.seabed_base_built_public',
        'command.seabed_base_built_private',
        'command.decoy_built_public',
        'command.decoy_built_private',
        'command.facility_built_public',
        'command.logging_public',
        'command.logging_private',
        'command.territory_expanded',
        'command.capital_relocated',
        'command.attraction_started',
        'command.money_aid_transferred',
        'command.money_aid_received',
        'command.food_aid_transferred',
        'command.food_aid_received',
        'command.monster_dispatched',
        'missile.launch_failed',
        'missile.launched',
        'missile.ineffective_aggregated',
        'missile.launch_detail',
        'missile.impact',
        'refugee_generated',
        'refugee_received',
        'turn.completed',
    ];

    /** @var list<string> */
    private const COMMANDS_WITH_SPECIFIC_RESULT_EVENT = [
        'land_clear',
        'land_level',
        'reclaim',
        'excavate',
        'build_farm',
        'build_factory',
        'build_mine',
        'logging',
        'territory_expand',
        'plant_forest',
        'build_missile_base',
        'build_defense_facility',
        'build_seabed_base',
        'build_monument',
        'build_decoy',
        'finance',
        'money_aid',
        'food_aid',
        'attraction',
        'monster_dispatch',
        'relocate_capital',
        'missile',
        'pp_missile',
        'land_destruction_missile',
        'spp_missile',
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
    public function page(Nation $nation, int $page = 1, ?int $anchorTurn = null): array
    {
        $currentTurn = (int) $nation->world()->value('current_turn');
        $anchorTurn ??= $currentTurn;
        $rangeEnd = $anchorTurn - (($page - 1) * self::TURNS_PER_PAGE);
        if ($rangeEnd < 1) {
            return [
                'groups' => [],
                'page' => $page,
                'anchor_turn' => $anchorTurn,
                'turn_range' => null,
                'turns_per_page' => self::TURNS_PER_PAGE,
                'has_newer_page' => $page > 1,
                'has_older_page' => false,
            ];
        }
        $rangeStart = max(1, $rangeEnd - self::TURNS_PER_PAGE + 1);

        $query = DB::table('audit_events as events')
            ->whereIn('events.event_type', self::ALLOWED_EVENT_TYPES)
            ->where('events.world_id', $nation->world_id)
            ->whereBetween('events.turn', [$rangeStart, $rangeEnd])
            ->where(function (Builder $audience) use ($nation): void {
                $audience->where('events.visibility', 'public')
                    ->orWhere(function (Builder $own) use ($nation): void {
                        $own->whereIn('events.visibility', ['nation', 'private'])
                            ->where('events.nation_id', $nation->id);
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
                $placeholders = implode(', ', array_fill(0, count(self::COMMANDS_WITH_SPECIFIC_RESULT_EVENT), '?'));
                $deduplicated->where('events.event_type', '!=', 'command.success')
                    ->orWhereRaw(
                        "events.metadata->>'command_key' NOT IN ({$placeholders})",
                        self::COMMANDS_WITH_SPECIFIC_RESULT_EVENT,
                    );
            })
            ->where(function (Builder $deduplicated): void {
                $deduplicated->where('events.event_type', '!=', 'population.decreased')
                    ->orWhereRaw("COALESCE(events.metadata->>'reason', '') <> 'famine'");
            })
            ->where(function (Builder $monsterRewardProjection): void {
                // Attributed kills are represented by one role-aware reward event;
                // structured audit events retain the individual history without
                // tripling the player log with killed/reward/stat copies.
                $monsterRewardProjection->where('events.event_type', '!=', 'monster.killed')
                    ->orWhereRaw("events.metadata->>'killer_nation_id' IS NULL");
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
                'events.occurred_at',
            ]);
        $coordinates = $this->subjectCoordinates($rows->all());
        $events = $rows->map(function (object $row) use ($coordinates, $nation): array {
            $metadata = $this->metadata($row->metadata);
            $targetTurn = (int) $row->turn;
            $message = $this->message(
                (string) $row->event_type,
                $metadata,
                $targetTurn,
                $nation->id,
            );
            if ((string) $row->visibility === 'private') {
                $message = '（秘密）'.$message;
            }

            return [
                'id' => (int) $row->id,
                'type' => (string) $row->event_type,
                'message' => $message,
                'importance' => $this->importance((string) $row->event_type),
                'target_turn' => $targetTurn,
                'coordinate' => $this->coordinate($row, $metadata, $coordinates),
                'occurred_at' => (string) $row->occurred_at,
                '_metadata' => $metadata,
            ];
        })->all();
        $events = $this->aggregateRefugeeEvents($events, $nation->id);

        $groups = [];
        foreach ($events as $event) {
            $last = array_key_last($groups);
            if ($last === null || $groups[$last]['target_turn'] !== $event['target_turn']) {
                $groups[] = ['target_turn' => $event['target_turn'], 'events' => []];
                $last = array_key_last($groups);
            }
            $groups[$last]['events'][] = $event;
        }

        return [
            'groups' => $groups,
            'page' => $page,
            'anchor_turn' => $anchorTurn,
            'turn_range' => ['start' => $rangeStart, 'end' => $rangeEnd],
            'turns_per_page' => self::TURNS_PER_PAGE,
            'has_newer_page' => $page > 1,
            'has_older_page' => $rangeStart > 1,
        ];
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
            'disaster.cell_damaged' => sprintf(
                '%sにより%sが%sへ変化しました。',
                $this->disasterLabel($metadata['disaster_key'] ?? null),
                $this->terrainLabel($metadata['from_terrain_key'] ?? null),
                $this->terrainLabel($metadata['to_terrain_key'] ?? null),
            ),
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
            'resource.food_shortage' => '食料が不足し、島内で飢餓が発生しました。',
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
            'capacity.overflow' => $this->capacityOverflowMessage($metadata),
            'monster.spawned' => sprintf(
                '%sが出現しました（HP %s）。',
                $this->monsterLabel($metadata['monster_key'] ?? null),
                number_format($this->integer($metadata, 'initial_hp')),
            ),
            'monster.spawn_failed_no_settlement' => '怪獣出現判定が発生しましたが、対象となる集落がありませんでした。',
            'monster.moved' => sprintf(
                '%sが移動しました。',
                $this->monsterLabel($metadata['monster_key'] ?? null),
            ),
            'monster.trampled' => sprintf(
                '%sが土地を踏み荒らし、荒地にしました。',
                $this->monsterLabel($metadata['monster_key'] ?? null),
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
            'monster.defense_self_destructed' => sprintf(
                '%sが防衛施設へ接触し、施設とともに消滅しました。',
                $this->monsterLabel($metadata['monster_key'] ?? null),
            ),
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
            'command.facility_built_public' => $this->constructionMessage(
                $metadata,
                $this->facilityLabel($metadata['facility_key'] ?? null),
            ),
            'command.logging_public' => 'こころなしか、どこかで森が減った気がします。',
            'command.logging_private' => sprintf(
                '%s(%s,%s)で伐採し、%s億円を得ました。',
                $metadata['nation_name'] ?? '自国',
                number_format($this->integer($metadata, 'x')),
                number_format($this->integer($metadata, 'y')),
                number_format($this->integer($metadata, 'applied_money')),
            ),
            'command.territory_expanded' => '領土拡張が完了しました。',
            'command.capital_relocated' => sprintf(
                '首都を(%s,%s)から(%s,%s)へ遷都しました。',
                number_format($this->integer($metadata, 'from_x')),
                number_format($this->integer($metadata, 'from_y')),
                number_format($this->integer($metadata, 'x')),
                number_format($this->integer($metadata, 'y')),
            ),
            'command.attraction_started' => '誘致活動を開始しました。',
            'command.money_aid_transferred' => sprintf(
                '%sへ資金援助として%s億円を送りました。',
                $metadata['receiver_nation_name'] ?? '対象Nation',
                number_format($this->integer($metadata, 'transferred_money')),
            ),
            'command.money_aid_received' => sprintf(
                '%sから資金援助として%s億円を受け取りました。',
                $metadata['sender_nation_name'] ?? '他Nation',
                number_format($this->integer($metadata, 'transferred_money')),
            ),
            'command.food_aid_transferred' => sprintf(
                '%sへ食料援助として%sトンを送りました。',
                $metadata['receiver_nation_name'] ?? '対象Nation',
                number_format($this->integer($metadata, 'transferred_food_tons')),
            ),
            'command.food_aid_received' => sprintf(
                '%sから食料援助として%sトンを受け取りました。',
                $metadata['sender_nation_name'] ?? '他Nation',
                number_format($this->integer($metadata, 'transferred_food_tons')),
            ),
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
                '%sの効果のない着弾%s件は発射単位で集約されました。',
                $this->missileLabel($metadata['command_key'] ?? null),
                number_format($this->integer($metadata, 'ineffective_impacts')),
            ),
            'missile.launch_detail' => $this->missileLaunchDetailMessage($metadata),
            'missile.impact' => $this->missileImpactMessage($metadata),
            'refugee_generated' => sprintf(
                'ミサイル被害により難民%s人が発生しました。',
                number_format($this->integer($metadata, 'generated_population')),
            ),
            'refugee_received' => sprintf(
                '難民%s人を受け入れました。',
                number_format($this->integer($metadata, 'received_population')),
            ),
            'turn.completed' => "第{$targetTurn}ターンが完了しました。",
            default => '島で出来事がありました。',
        };
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
            'missing_adjacent_territory' => '隣接する自国領地がないため実行できませんでした。',
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
            default => '実行条件を満たさなくなったため実行できませんでした。',
        };

        return $prefix.$reason;
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
            $result[$index]['coordinate'] = null;
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
        $impacts = is_array($metadata['impacts'] ?? null) ? $metadata['impacts'] : [];
        $details = [];
        foreach ($impacts as $impact) {
            if (! is_array($impact)) {
                continue;
            }
            $x = is_numeric($impact['x'] ?? null) ? (int) $impact['x'] : 0;
            $y = is_numeric($impact['y'] ?? null) ? (int) $impact['y'] : 0;
            $details[] = sprintf(
                '(%s,%s): %s',
                number_format($x),
                number_format($y),
                $this->missileEffectLabel($impact['effect'] ?? null),
            );
        }

        return sprintf(
            '%sを狙点(%s,%s)へ%s発を発射し、費用%s億円。着弾結果: %s',
            $this->missileLabel($metadata['command_key'] ?? null),
            number_format($this->integer($metadata, 'target_x')),
            number_format($this->integer($metadata, 'target_y')),
            number_format($this->integer($metadata, 'fired_shots')),
            number_format($this->integer($metadata, 'cost_money')),
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
            'eruption' => '噴火',
            'land_subsidence' => '地盤沈下',
            'fire' => '火災',
            default => '災害',
        };
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
            'build_decoy' => '防衛施設建設',
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
            default => '資源',
        };
    }

    private function resourceUnitLabel(mixed $key): string
    {
        return match ($key) {
            'industrial_goods' => 'ユニット',
            'minerals', 'wheat', 'fish', 'monster_meat' => 'トン',
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

        return "{$monster}を撃破し、賞金{$money}億円を受け取りました。";
    }

    private function monsterLabel(mixed $key): string
    {
        return match ($key) {
            'mecha_inora' => 'メカいのら',
            'inora' => 'いのら',
            'sanjira' => 'サンジラ',
            'red_inora' => 'レッドいのら',
            'dark_inora' => 'ダークいのら',
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
            'famine.applied', 'facility.riot', 'capacity.overflow',
            'disaster.cell_damaged', 'capital.disaster_damaged', 'fire.damaged', 'oil.depleted',
            'monster.damage_blocked', 'monster.damaged', 'monster.defense_self_destructed',
            'monster.removed_by_terrain_event' => 'warning',
            'missile.launch_failed' => 'warning',
            'command.buried_treasure', 'command.seabed_oil_search',
            'command.land_level_earthquake', 'disaster.triggered', 'fire.prevented', 'oil.income',
            'land_subsidence.triggered', 'monster.spawned', 'monster.reward_distributed',
            'settlement.appeared', 'settlement.stage_transitioned' => 'notable',
            'missile.launched', 'missile.impact', 'command.capital_relocated' => 'notable',
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
