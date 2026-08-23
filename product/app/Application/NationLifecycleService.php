<?php

namespace App\Application;

use App\Domain\Economy\CapacityBoundedAssetService;
use App\Domain\Map\GridCoordinate;
use App\Domain\Map\MapCellStateService;
use App\Domain\Turn\TurnContext;
use App\Models\FacilityDefinition;
use App\Models\MapCell;
use App\Models\MapChunk;
use App\Models\MapSpace;
use App\Models\Nation;
use App\Models\NationCapital;
use App\Models\ResourceDefinition;
use App\Models\RulesetVersion;
use App\Models\TerrainDefinition;
use App\Models\User;
use App\Models\World;
use DomainException;
use Illuminate\Support\Facades\DB;

final class NationLifecycleService
{
    public function __construct(
        private readonly DomesticCommandExecutor $commands,
        private readonly NationAbandonmentService $abandonment,
        private readonly NationBasicStatusProjection $status,
        private readonly CapacityBoundedAssetService $boundedAssets,
        private readonly MapCellStateService $cellStates,
        private readonly TurnEventRecorder $events,
    ) {}

    /** @return array{participants: int, active: int, dormant: int, resumed: int} */
    public function prepare(TurnContext $context): array
    {
        $settings = $this->settings($context->ruleset);
        if (Nation::query()->where('world_id', $context->world->id)->where('state', 'recovery')->exists()) {
            throw new DomainException('Recovery has no ver 2.4.0 runtime entry path and cannot enter an official Turn.');
        }
        $nations = Nation::query()->where('world_id', $context->world->id)
            ->whereIn('state', ['active', 'dormant'])->orderBy('id')->lockForUpdate()->get();
        $resumed = 0;
        foreach ($nations as $nation) {
            if ($nation->state !== 'dormant') {
                continue;
            }
            $manualDue = $nation->state_reason === 'manual'
                && is_int($nation->resume_at_turn)
                && $context->targetTurn >= $nation->resume_at_turn;
            $queuedResume = $nation->state_reason !== 'manual'
                && $this->hasQueuedNonFinanceCommand($nation, $settings['finance_command_key']);
            if (! $manualDue && ! $queuedResume) {
                continue;
            }
            $reason = $manualDue ? 'manual_period_complete' : 'queued_non_finance_command';
            $beforeReason = $nation->state_reason;
            $nation->state = 'active';
            $nation->state_reason = null;
            $nation->state_started_turn = null;
            $nation->resume_at_turn = null;
            $nation->save();
            $resumed++;
            $this->events->record($context, 'nation.dormancy_resumed', $nation, [
                'nation_id' => $nation->id,
                'nation_name' => $nation->name,
                'before_state' => 'dormant',
                'after_state' => 'active',
                'before_reason' => $beforeReason,
                'reason' => $reason,
            ], 'public', message: "{$nation->name}に春が訪れ、活動を再開しました。");
        }

        $nations = Nation::query()->where('world_id', $context->world->id)
            ->whereIn('state', ['active', 'dormant'])->orderBy('id')->get();
        $capitals = NationCapital::query()->whereIn('nation_id', $nations->modelKeys())
            ->orderBy('nation_id')->lockForUpdate()->get()->keyBy('nation_id');
        if ($capitals->count() !== $nations->count()) {
            throw new DomainException('Every active or dormant Nation must retain exactly one Capital.');
        }
        $context->state->setLifecycleNationIds($nations->modelKeys());
        foreach ($nations as $nation) {
            $capital = $capitals->get($nation->id);
            $context->state->setNationLifecycleSnapshot($nation->id, [
                'state' => $nation->state,
                'reason' => $nation->state_reason,
                'state_started_turn' => $nation->state_started_turn,
                'resume_at_turn' => $nation->resume_at_turn,
                'capital_x' => (int) $capital->x,
                'capital_y' => (int) $capital->y,
            ]);
        }

        return [
            'participants' => $nations->count(),
            'active' => $nations->where('state', 'active')->count(),
            'dormant' => $nations->where('state', 'dormant')->count(),
            'resumed' => $resumed,
        ];
    }

    /** @return array{dormant_heartbeats: int, money_applied: int, idle_counter_increments: int, emergency_farms: int} */
    public function heartbeat(TurnContext $context): array
    {
        $settings = $this->settings($context->ruleset);
        $metrics = [
            'dormant_heartbeats' => 0,
            'money_applied' => 0,
            'idle_counter_increments' => 0,
            'emergency_farms' => 0,
        ];
        foreach ($context->state->dormantNationIds() as $nationId) {
            $nation = Nation::query()->whereKey($nationId)->lockForUpdate()->firstOrFail();
            if ($nation->state !== 'dormant') {
                throw new DomainException('Nation lifecycle changed inside a frozen target Turn.');
            }
            $finance = $this->commands->executeFinanceOnly(
                $context,
                $nation,
                $settings['dormant_finance_money'],
                'command.dormant_finance',
                'dormant_heartbeat',
            );
            $metrics['dormant_heartbeats']++;
            $metrics['money_applied'] += $finance['money_applied'];
            $metrics['idle_counter_increments'] += $finance['idle_counter_incremented'];
            $metrics['emergency_farms'] += $this->ensureEmergencyFarm($nation, $context->ruleset, $context) ? 1 : 0;
        }

        return $metrics;
    }

    /** @return array{entered_dormant: int, abandoned: int} */
    public function finalize(TurnContext $context): array
    {
        $settings = $this->settings($context->ruleset);
        $metrics = ['entered_dormant' => 0, 'abandoned' => 0];
        foreach ($context->state->nationLifecycleSnapshots() as $nationId => $snapshot) {
            $nation = Nation::query()->whereKey($nationId)->lockForUpdate()->firstOrFail();
            if ($nation->state === 'abandoned') {
                continue;
            }
            if ($snapshot['state'] === 'dormant') {
                if ($nation->state !== 'dormant') {
                    throw new DomainException('Dormant Nation state changed inside a frozen target Turn.');
                }
                if ($nation->idle_counter >= $settings['abandonment_idle_threshold']) {
                    $this->abandonment->abandonAutomatically($context, $nation);
                    $metrics['abandoned']++;
                }

                continue;
            }
            if ($nation->state !== 'active') {
                throw new DomainException('Active Nation state changed inside a frozen target Turn.');
            }
            if ($nation->idle_counter >= $settings['abandonment_idle_threshold']) {
                $this->enterDormant($context->world, $context->ruleset, $nation, 'idle', $context->targetTurn, null, null, $context);
                $this->abandonment->abandonAutomatically($context, $nation->fresh());
                $metrics['abandoned']++;

                continue;
            }

            $status = $this->status->forNation($nation);
            $collapse = $status['total_population'] === 100 && $status['food_total_tons'] === 0;
            if ($collapse || $nation->idle_counter >= $settings['dormant_idle_threshold']) {
                $this->enterDormant(
                    $context->world,
                    $context->ruleset,
                    $nation,
                    $collapse ? 'collapse' : 'idle',
                    $context->targetTurn,
                    null,
                    null,
                    $context,
                );
                $metrics['entered_dormant']++;
            }
        }

        return $metrics;
    }

    public function enterManual(
        World $world,
        RulesetVersion $ruleset,
        Nation $nation,
        User $actor,
        int $days,
    ): Nation {
        $settings = $this->settings($ruleset);
        if ($days < $settings['manual_dormancy_min_days'] || $days > $settings['manual_dormancy_max_days']) {
            throw new DomainException('Manual dormancy days are outside the current Ruleset range.');
        }
        $resumeAt = (int) $world->current_turn + ($days * $settings['turns_per_day']) + 1;
        $this->enterDormant(
            $world,
            $ruleset,
            $nation,
            'manual',
            (int) $world->current_turn,
            $resumeAt,
            $actor,
            null,
        );

        return $nation->fresh();
    }

    private function enterDormant(
        World $world,
        RulesetVersion $ruleset,
        Nation $nation,
        string $reason,
        int $startedTurn,
        ?int $resumeAtTurn,
        ?User $actor,
        ?TurnContext $context,
    ): void {
        $settings = $this->settings($ruleset);
        if ($nation->state !== 'active' || ! in_array($reason, ['idle', 'collapse', 'manual'], true)) {
            throw new DomainException('Nation cannot enter dormant from its current lifecycle state.');
        }
        $beforeCounter = (int) $nation->idle_counter;
        $nation->state = 'dormant';
        $nation->state_reason = $reason;
        $nation->state_started_turn = $startedTurn;
        $nation->resume_at_turn = $resumeAtTurn;
        if ($reason === 'collapse') {
            $nation->idle_counter = max($beforeCounter, $settings['dormant_idle_threshold']);
        }
        $nation->save();

        $foodRecovered = $this->recoverInitialFood($nation, $ruleset);
        $farmCreated = $this->ensureEmergencyFarm($nation, $ruleset, $context);
        [$secretaryId, $secretaryName] = $this->secretary($nation);
        $message = $reason === 'collapse'
            ? ($secretaryName === null
                ? "{$nation->name}から住民が居なくなった悲しみで秘書が涙を流しました。{$nation->name}に冬が訪れています……"
                : "{$nation->name}から住民が居なくなった悲しみで秘書の{$secretaryName}が涙を流しました。{$nation->name}に冬が訪れています……")
            : ($secretaryName === null
                ? "主が帰ってくるまでの間、秘書が禁呪を解き放ちました。{$nation->name}に冬が訪れています……"
                : "主が帰ってくるまでの間、秘書の{$secretaryName}が禁呪を解き放ちました。{$nation->name}に冬が訪れています……");
        $metadata = [
            'nation_id' => $nation->id,
            'nation_name' => $nation->name,
            'reason' => $reason,
            'before_state' => 'active',
            'after_state' => 'dormant',
            'target_turn' => $startedTurn,
            'idle_counter' => (int) $nation->idle_counter,
            'resume_at_turn' => $resumeAtTurn,
            'protection_radius' => $settings['dormant_protection_radius'],
            'secretary_id' => $secretaryId,
            'secretary_name' => $secretaryName,
            'automatic' => $actor === null,
            'manual' => $actor !== null,
            'food_recovered_tons' => $foodRecovered,
            'emergency_farm_created' => $farmCreated,
        ];
        if ($context !== null) {
            $this->events->record($context, 'nation.dormant', $nation, $metadata, 'public', 'info', $message);
        } else {
            $this->recordManualEvent($world, $nation, $actor, $message, $metadata);
        }
    }

    private function recoverInitialFood(Nation $nation, RulesetVersion $ruleset): int
    {
        $settings = $this->settings($ruleset);
        $key = $settings['initial_food_resource_key'];
        $initial = $ruleset->settings['initial_resources'][$key] ?? null;
        if (! is_int($initial) || $initial < 0) {
            throw new DomainException('Dormancy initial food reference is invalid.');
        }
        $current = $this->status->forNation($nation)['food_total_tons'];
        if ($current >= $initial) {
            return 0;
        }
        $definition = ResourceDefinition::query()->where('key', $key)->firstOrFail();
        $result = $this->boundedAssets->creditFood($nation, $definition, $initial - $current, $ruleset);

        return $result->applied;
    }

    private function ensureEmergencyFarm(Nation $nation, RulesetVersion $ruleset, ?TurnContext $context): bool
    {
        $settings = $this->settings($ruleset);
        $farm = $settings['emergency_farm'];
        $farmScale = (int) MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereHas('facility', fn ($query) => $query->where('key', $farm['facility_key']))
            ->sum('facility_scale');
        if ($farmScale > 0) {
            return false;
        }
        $capital = NationCapital::query()->where('nation_id', $nation->id)->lockForUpdate()->firstOrFail();
        $surface = MapSpace::query()->where('world_id', $nation->world_id)->where('key', 'surface')->firstOrFail();
        $origin = new GridCoordinate((int) $capital->x, (int) $capital->y);
        $radius = $settings['dormant_protection_radius'];
        $candidates = MapCell::query()->where('map_space_id', $surface->id)
            ->whereBetween('x', [$origin->x - $radius, $origin->x + $radius])
            ->whereBetween('y', [$origin->y - $radius, $origin->y + $radius])
            ->where(function ($query) use ($nation): void {
                $query->whereNull('owner_nation_id')->orWhere('owner_nation_id', $nation->id);
            })
            ->whereNull('facility_definition_id')->whereDoesntHave('monsterOccupancy')
            ->whereHas('terrain', fn ($query) => $query->whereIn('key', $farm['candidate_terrain_keys']))
            ->with(['terrain', 'facility'])->orderBy('id')->lockForUpdate()->get()
            ->filter(fn (MapCell $cell): bool => $origin->distanceTo(new GridCoordinate($cell->x, $cell->y)) <= $radius)
            ->sort(function (MapCell $first, MapCell $second) use ($origin): int {
                return [
                    $origin->distanceTo(new GridCoordinate($first->x, $first->y)), $first->y, $first->x,
                ] <=> [
                    $origin->distanceTo(new GridCoordinate($second->x, $second->y)), $second->y, $second->x,
                ];
            })->values();
        $cell = $candidates->first();
        if (! $cell instanceof MapCell) {
            return false;
        }

        $plain = TerrainDefinition::query()->where('key', $farm['result_terrain_key'])->firstOrFail();
        $facility = FacilityDefinition::query()->where('key', $farm['facility_key'])->firstOrFail();
        $this->cellStates->transitionTerrain($cell, $plain);
        $this->cellStates->setFacility($cell, $facility);
        $cell->owner_nation_id = $nation->id;
        $cell->population = 0;
        $cell->version++;
        $cell->save();
        MapChunk::query()->whereKey($cell->map_chunk_id)->lockForUpdate()->increment('version');
        if ($context !== null) {
            $this->events->record($context, 'nation.emergency_farm_created', $cell, [
                'nation_id' => $nation->id, 'x' => $cell->x, 'y' => $cell->y,
                'selection' => ['distance', 'y', 'x'],
            ], 'public');
        }

        return true;
    }

    private function hasQueuedNonFinanceCommand(Nation $nation, string $financeKey): bool
    {
        return DB::table('nation_command_queue_items as item')
            ->join('nation_command_queues as queue', 'queue.id', '=', 'item.nation_command_queue_id')
            ->join('command_definitions as definition', 'definition.id', '=', 'item.command_definition_id')
            ->where('queue.nation_id', $nation->id)->where('item.status', 'queued')
            ->where('definition.key', '<>', $financeKey)->exists();
    }

    /** @return array{0: int|null, 1: string|null} */
    private function secretary(Nation $nation): array
    {
        $row = DB::table('secretaries as secretary')
            ->join('nation_memberships as membership', 'membership.user_id', '=', 'secretary.user_id')
            ->where('membership.nation_id', $nation->id)->where('membership.role', 'owner')
            ->first(['secretary.id', 'secretary.name']);

        return [$row?->id === null ? null : (int) $row->id, $row?->name];
    }

    /** @param array<string, mixed> $metadata */
    private function recordManualEvent(
        World $world,
        Nation $nation,
        ?User $actor,
        string $message,
        array $metadata,
    ): void {
        $now = now();
        DB::table('audit_events')->insert([
            'actor_user_id' => $actor?->id,
            'world_id' => $world->id,
            'turn' => $world->current_turn,
            'nation_id' => $nation->id,
            'x' => null,
            'y' => null,
            'message' => $message,
            'visibility' => 'public',
            'event_type' => 'nation.dormant',
            'severity' => 'info',
            'subject_type' => Nation::class,
            'subject_id' => $nation->id,
            'metadata' => json_encode(['world_id' => $world->id, ...$metadata], JSON_THROW_ON_ERROR),
            'occurred_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @return array{initial_idle_counter: int, dormant_idle_threshold: int, abandonment_idle_threshold: int, turns_per_day: int, manual_dormancy_min_days: int, manual_dormancy_max_days: int, dormant_finance_money: int, dormant_protection_radius: int, dormant_visual_theme: string, initial_food_resource_key: string, finance_command_key: string, emergency_farm: array<string, mixed>}
     */
    private function settings(RulesetVersion $ruleset): array
    {
        $settings = $ruleset->settings['nation_lifecycle'] ?? null;
        if (! is_array($settings) || ($settings['recovery_entry_enabled'] ?? null) !== false) {
            throw new DomainException('The current Ruleset has no supported Nation lifecycle contract.');
        }

        /** @var array{initial_idle_counter: int, dormant_idle_threshold: int, abandonment_idle_threshold: int, turns_per_day: int, manual_dormancy_min_days: int, manual_dormancy_max_days: int, dormant_finance_money: int, dormant_protection_radius: int, dormant_visual_theme: string, initial_food_resource_key: string, finance_command_key: string, emergency_farm: array<string, mixed>} $settings */
        return $settings;
    }
}
