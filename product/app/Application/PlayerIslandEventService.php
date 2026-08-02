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
        'command.invalid',
        'command.insufficient_assets',
        'terrain.changed',
        'facility.constructed',
        'facility.expanded',
        'command.buried_treasure',
        'command.seabed_oil_search',
        'settlement.appeared',
        'settlement.stage_transitioned',
        'population.increased',
        'population.decreased',
        'resource.food_shortage',
        'famine.applied',
        'facility.riot',
        'resource.automatic_sale',
        'capacity.overflow',
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
            ->whereRaw("events.metadata->>'world_id' = ?", [(string) $nation->world_id])
            ->whereRaw("jsonb_exists(events.metadata, 'target_turn')")
            ->whereRaw(
                "(events.metadata->>'target_turn')::bigint BETWEEN ? AND ?",
                [$rangeStart, $rangeEnd],
            )
            ->where(function (Builder $audience) use ($nation): void {
                $audience->whereRaw("events.metadata->>'nation_id' = ?", [(string) $nation->id])
                    ->orWhere(function (Builder $subject) use ($nation): void {
                        $subject->where('events.subject_type', Nation::class)
                            ->where('events.subject_id', $nation->id);
                    })
                    ->orWhere('events.event_type', 'turn.completed');
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
            });

        $rows = $query
            ->orderByRaw("(events.metadata->>'target_turn')::bigint DESC")
            ->orderByDesc('events.id')
            ->get([
                'events.id',
                'events.event_type',
                'events.subject_type',
                'events.subject_id',
                'events.metadata',
                'events.occurred_at',
            ]);
        $coordinates = $this->subjectCoordinates($rows->all());
        $events = $rows->map(function (object $row) use ($coordinates): array {
            $metadata = $this->metadata($row->metadata);
            $targetTurn = $this->integer($metadata, 'target_turn');

            return [
                'id' => (int) $row->id,
                'type' => (string) $row->event_type,
                'message' => $this->message((string) $row->event_type, $metadata, $targetTurn),
                'importance' => $this->importance((string) $row->event_type),
                'target_turn' => $targetTurn,
                'coordinate' => $this->coordinate($row, $metadata, $coordinates),
                'occurred_at' => (string) $row->occurred_at,
            ];
        })->all();

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
        if (isset($metadata['x'], $metadata['y']) && is_numeric($metadata['x']) && is_numeric($metadata['y'])) {
            return ['x' => (int) $metadata['x'], 'y' => (int) $metadata['y']];
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
    private function message(string $eventType, array $metadata, int $targetTurn): string
    {
        return match ($eventType) {
            'command.success' => $this->commandLabel($metadata).'が完了しました。',
            'command.invalid', 'command.insufficient_assets' => $this->commandFailureMessage($metadata),
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
            'turn.completed' => "第{$targetTurn}ターンが完了しました。",
            default => '島で出来事がありました。',
        };
    }

    /** @param array<string, mixed> $metadata */
    private function commandFailureMessage(array $metadata): string
    {
        $reason = match ($metadata['failure_code'] ?? null) {
            'insufficient_money' => '資金が不足していた',
            'insufficient_resources' => '必要な資源が不足していた',
            'target_missing' => '対象地点が見つからなかった',
            'ownership_mismatch' => '対象地点を利用できなかった',
            'capital_protected' => '首都は変更できない',
            default => '対象地点の状態が変わっていた',
        };

        return sprintf('%sは%sため、実行できませんでした。', $this->commandLabel($metadata), $reason);
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

    private function importance(string $eventType): string
    {
        return match ($eventType) {
            'command.invalid', 'command.insufficient_assets', 'resource.food_shortage',
            'famine.applied', 'facility.riot', 'capacity.overflow' => 'warning',
            'command.buried_treasure', 'command.seabed_oil_search',
            'settlement.appeared', 'settlement.stage_transitioned' => 'notable',
            default => 'info',
        };
    }

    /** @param array<string, mixed> $metadata */
    private function integer(array $metadata, string $key): int
    {
        $value = $metadata[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }
}
