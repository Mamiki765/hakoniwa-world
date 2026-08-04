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
                    ->orWhereRaw("events.metadata->>'killer_nation_id' = ?", [(string) $nation->id])
                    ->orWhereRaw("events.metadata->>'host_nation_id' = ?", [(string) $nation->id])
                    ->orWhere(function (Builder $subject) use ($nation): void {
                        $subject->where('events.subject_type', Nation::class)
                            ->where('events.subject_id', $nation->id);
                    })
                    ->orWhereIn('events.event_type', ['turn.completed', 'disaster.triggered']);
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
                // the immutable kill fact remains in audit/storage without tripling
                // the player log with killed/reward/recorded copies.
                $monsterRewardProjection->where('events.event_type', '!=', 'monster.killed')
                    ->orWhereRaw("events.metadata->>'killer_nation_id' IS NULL");
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
        $events = $rows->map(function (object $row) use ($coordinates, $nation): array {
            $metadata = $this->metadata($row->metadata);
            $targetTurn = $this->integer($metadata, 'target_turn');

            return [
                'id' => (int) $row->id,
                'type' => (string) $row->event_type,
                'message' => $this->message(
                    (string) $row->event_type,
                    $metadata,
                    $targetTurn,
                    $nation->id,
                ),
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
            'command.invalid', 'command.insufficient_assets', 'resource.food_shortage',
            'famine.applied', 'facility.riot', 'capacity.overflow',
            'disaster.cell_damaged', 'capital.disaster_damaged', 'fire.damaged', 'oil.depleted',
            'monster.damage_blocked', 'monster.damaged', 'monster.defense_self_destructed',
            'monster.removed_by_terrain_event' => 'warning',
            'command.buried_treasure', 'command.seabed_oil_search',
            'command.land_level_earthquake', 'disaster.triggered', 'fire.prevented', 'oil.income',
            'land_subsidence.triggered', 'monster.spawned', 'monster.reward_distributed',
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

    /** @param array<string, mixed> $metadata */
    private function nestedInteger(array $metadata, string $key, string $nestedKey): int
    {
        $value = $metadata[$key] ?? null;

        return is_array($value) && is_numeric($value[$nestedKey] ?? null)
            ? (int) $value[$nestedKey]
            : 0;
    }
}
