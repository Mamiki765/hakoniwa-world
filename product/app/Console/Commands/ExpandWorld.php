<?php

namespace App\Console\Commands;

use App\Application\MapSpaceCoveragePreflight;
use App\Application\WorldExpansionService;
use App\Domain\Map\ChunkCoordinateService;
use App\Domain\Ruleset\CurrentRulesetGuard;
use App\Domain\World\MapBounds;
use App\Models\MapSpace;
use App\Models\TurnRun;
use App\Models\World;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ExpandWorld extends Command
{
    protected $signature = 'hakoniwa:world:expand
        {--world= : Exact configured World key}
        {--expected-min-x= : Expected current minimum x}
        {--expected-max-x= : Expected current maximum x}
        {--expected-min-y= : Expected current minimum y}
        {--expected-max-y= : Expected current maximum y}
        {--target-min-x= : Requested target minimum x}
        {--target-max-x= : Requested target maximum x}
        {--target-min-y= : Requested target minimum y}
        {--target-max-y= : Requested target maximum y}
        {--reason= : Required operator reason recorded in the expansion audit event}
        {--confirm= : Must exactly equal the command-reported EXPAND token}
        {--dry-run : Run the complete read-only preflight without changing data}';

    protected $description = 'Preflight and expand the configured World to explicit containing MapSpace bounds.';

    public function handle(
        WorldExpansionService $expansions,
        MapSpaceCoveragePreflight $coverage,
        CurrentRulesetGuard $rulesetGuard,
        ChunkCoordinateService $chunks,
    ): int {
        $worldKey = (string) $this->option('world');
        $configuredWorldKey = (string) config('hakoniwa.world.key');
        if ($worldKey === '' || $worldKey !== $configuredWorldKey) {
            $this->error("Only the configured World key '{$configuredWorldKey}' can be expanded.");

            return self::FAILURE;
        }

        $reason = trim((string) $this->option('reason'));
        if ($reason === '' || mb_strlen($reason) > 500 || preg_match('/[\x00-\x1F\x7F]/u', $reason) === 1) {
            $this->error('Reason must be a non-empty, single-line value of at most 500 characters.');

            return self::FAILURE;
        }

        try {
            $expected = $this->readBounds('expected');
            $target = $this->readBounds('target');
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        if (! $target->containsBounds($expected)) {
            $this->error('Target bounds must completely contain expected-before bounds.');

            return self::FAILURE;
        }
        $approvedTarget = new MapBounds(0, 63, 0, 63, (int) config('hakoniwa.ruleset.chunk_size'));
        if (! $target->equals($approvedTarget)) {
            $this->line('operation_contract=failed');
            $this->error(
                'preflight_blocker=This operator command is approved only for '
                .'shared-world x=0..59,y=0..59 to x=0..63,y=0..63.',
            );
            $this->error('preflight=failed execution=not_started');

            return self::FAILURE;
        }

        $world = World::query()->where('key', $worldKey)->first();
        if ($world === null) {
            $this->error("World '{$worldKey}' does not exist.");

            return self::FAILURE;
        }
        $mapSpace = MapSpace::query()
            ->where('world_id', $world->id)
            ->where('key', config('hakoniwa.world.map_space_key'))
            ->first();
        if ($mapSpace === null) {
            $this->error("World '{$worldKey}' has no configured surface MapSpace.");

            return self::FAILURE;
        }

        $current = $mapSpace->currentBounds();
        $currentState = $current->equals($expected)
            ? 'expected-before'
            : ($current->equals($target) ? 'target' : 'unexpected');
        $blockers = [];
        $dryRun = (bool) $this->option('dry-run');
        $productionEnvironment = app()->environment('production');
        $approvedOperation = $this->isApprovedProductionOperation($expected, $target);

        if (! $approvedOperation) {
            $blockers[] = 'Expected-before bounds do not match the approved x=0..59,y=0..59 operation contract.';
        }
        if (! $dryRun && ! $productionEnvironment) {
            $blockers[] = 'World expansion execution is allowed only when APP_ENV is production; use --dry-run elsewhere.';
        }

        $rulesetStatus = 'ok';
        try {
            $rulesetGuard->assertMutable($world, $world->rulesetVersion()->firstOrFail());
        } catch (Throwable $exception) {
            $rulesetStatus = 'failed';
            $blockers[] = $exception->getMessage();
        }

        if ($mapSpace->coordinate_system !== 'staggered_square_offset') {
            $blockers[] = 'The surface MapSpace does not use the canonical staggered x/y coordinate system.';
        }
        if ($currentState === 'unexpected') {
            $blockers[] = 'Current bounds match neither expected-before nor target bounds.';
        }

        $unresolved = TurnRun::query()
            ->where('world_id', $world->id)
            ->unresolvedProduction()
            ->orderBy('id')
            ->get(['id', 'target_turn', 'status']);
        if ($unresolved->isNotEmpty()) {
            $blockers[] = 'The World has unresolved production TurnRuns.';
        }

        $coverageStatus = 'ok';
        try {
            $coverage->assertComplete($mapSpace);
        } catch (Throwable $exception) {
            $coverageStatus = 'failed';
            $blockers[] = $exception->getMessage();
        }

        $chunkRows = $mapSpace->chunks()
            ->orderBy('chunk_y')
            ->orderBy('chunk_x')
            ->get(['chunk_x', 'chunk_y']);
        $existingChunkKeys = $chunkRows
            ->map(static fn (object $chunk): string => $chunk->chunk_x.':'.$chunk->chunk_y)
            ->all();
        $expectedCurrentChunkKeys = $this->chunkKeys($current, $chunks);
        sort($existingChunkKeys);
        sort($expectedCurrentChunkKeys);
        $chunkCoverageStatus = $existingChunkKeys === $expectedCurrentChunkKeys ? 'ok' : 'failed';
        if ($chunkCoverageStatus === 'failed') {
            $blockers[] = 'MapChunk rows do not exactly match the chunks intersecting current bounds.';
        }

        $currentCells = $mapSpace->cells()->count();
        $existingChunks = $chunkRows->count();
        $expectedAdded = $currentState === 'expected-before'
            ? $target->cellCount() - $currentCells
            : ($currentState === 'target' ? 0 : null);
        [$createdChunks, $touchedExistingChunks] = $currentState === 'expected-before'
            ? $this->predictedChunkChanges($current, $target, $existingChunkKeys, $chunks)
            : [0, 0];

        $confirmation = $this->confirmationToken($worldKey, $expected, $target);
        $this->line('app_env='.app()->environment());
        $this->line("world={$worldKey} world_id={$world->id} current_turn={$world->current_turn}");
        $this->line("map_space={$mapSpace->key} coordinate_system={$mapSpace->coordinate_system}");
        $this->line('current_bounds='.$this->boundsLabel($current));
        $this->line('expected_before_bounds='.$this->boundsLabel($expected));
        $this->line('target_bounds='.$this->boundsLabel($target));
        $this->line("current_cells={$currentCells} target_cells={$target->cellCount()} expected_added_cells=".($expectedAdded ?? 'n/a'));
        $this->line(
            "existing_chunks={$existingChunks} target_chunks={$target->chunkCount()} "
            ."predicted_created_chunks={$createdChunks} predicted_touched_existing_chunks={$touchedExistingChunks}",
        );
        $this->line(
            'operation_contract='.($approvedOperation ? 'ok' : 'failed')
            .' production_guard='.($dryRun ? 'not-required-dry-run' : ($productionEnvironment ? 'ok' : 'failed')),
        );
        $this->line("ruleset={$rulesetStatus} coverage={$coverageStatus} chunk_coverage={$chunkCoverageStatus}");
        $this->line("unresolved_turn_runs={$unresolved->count()}");
        foreach ($unresolved as $turnRun) {
            $this->line(
                "unresolved_turn_run_id={$turnRun->id} target_turn={$turnRun->target_turn} status={$turnRun->status}",
            );
        }
        $this->line("current_state={$currentState} requested_operation=".($currentState === 'target' ? 'no-op' : 'expand'));
        $this->line("bounds_revision={$mapSpace->boundsRevision()}");
        $this->writeLatestExpansionAudit($world);
        $this->line("confirmation_token={$confirmation}");

        if ($blockers !== []) {
            foreach (array_values(array_unique($blockers)) as $blocker) {
                $this->error('preflight_blocker='.$blocker);
            }
            $this->error('preflight=failed execution=not_started');

            return self::FAILURE;
        }

        $this->info('preflight=ok');
        if ($dryRun) {
            $this->info('execution=not_started dry_run=true');

            return self::SUCCESS;
        }

        if ((string) $this->option('confirm') !== $confirmation) {
            $this->error("Confirmation must exactly equal {$confirmation}.");

            return self::FAILURE;
        }

        try {
            $expanded = $expansions->expand($world, $expected, $target, null, $reason);
        } catch (Throwable $exception) {
            $this->error('World expansion failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $expanded->refresh();
        $this->info(
            'execution=complete requested_operation='.($currentState === 'target' ? 'no-op' : 'expand')
            .' result_bounds='.$this->boundsLabel($expanded->currentBounds())
            .' cells='.$expanded->cells()->count()
            .' chunks='.$expanded->chunks()->count()
            .' bounds_revision='.$expanded->boundsRevision(),
        );
        $this->writeLatestExpansionAudit($world);

        return self::SUCCESS;
    }

    private function readBounds(string $prefix): MapBounds
    {
        return new MapBounds(
            $this->integerOption("{$prefix}-min-x"),
            $this->integerOption("{$prefix}-max-x"),
            $this->integerOption("{$prefix}-min-y"),
            $this->integerOption("{$prefix}-max-y"),
            (int) config('hakoniwa.ruleset.chunk_size'),
        );
    }

    private function integerOption(string $name): int
    {
        $value = $this->option($name);
        if (! is_string($value) || preg_match('/^-?\d+$/D', $value) !== 1) {
            throw new DomainException("Option --{$name} must be an explicit integer.");
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if ($integer === false) {
            throw new DomainException("Option --{$name} is outside the supported integer range.");
        }

        return $integer;
    }

    /** @return list<string> */
    private function chunkKeys(MapBounds $bounds, ChunkCoordinateService $chunks): array
    {
        $keys = [];
        for ($chunkY = $chunks->floorDiv($bounds->minY); $chunkY <= $chunks->floorDiv($bounds->maxY); $chunkY++) {
            for ($chunkX = $chunks->floorDiv($bounds->minX); $chunkX <= $chunks->floorDiv($bounds->maxX); $chunkX++) {
                $keys[] = $chunkX.':'.$chunkY;
            }
        }

        return $keys;
    }

    /**
     * @param  list<string>  $existingChunkKeys
     * @return array{int, int}
     */
    private function predictedChunkChanges(
        MapBounds $current,
        MapBounds $target,
        array $existingChunkKeys,
        ChunkCoordinateService $chunks,
    ): array {
        $needed = [];
        for ($y = $target->minY; $y <= $target->maxY; $y++) {
            for ($x = $target->minX; $x <= $target->maxX; $x++) {
                if ($current->contains($x, $y)) {
                    continue;
                }
                $location = $chunks->locate($x, $y);
                $needed[$location['chunk_x'].':'.$location['chunk_y']] = true;
            }
        }

        $existing = array_fill_keys($existingChunkKeys, true);
        $created = 0;
        $touched = 0;
        foreach (array_keys($needed) as $key) {
            if (isset($existing[$key])) {
                $touched++;
            } else {
                $created++;
            }
        }

        return [$created, $touched];
    }

    private function confirmationToken(string $worldKey, MapBounds $expected, MapBounds $target): string
    {
        return implode(':', [
            'EXPAND',
            $worldKey,
            $expected->minX,
            $expected->maxX,
            $expected->minY,
            $expected->maxY,
            'TO',
            $target->minX,
            $target->maxX,
            $target->minY,
            $target->maxY,
        ]);
    }

    private function isApprovedProductionOperation(MapBounds $expected, MapBounds $target): bool
    {
        $chunkSize = (int) config('hakoniwa.ruleset.chunk_size');

        return $expected->equals(new MapBounds(0, 59, 0, 59, $chunkSize))
            && $target->equals(new MapBounds(0, 63, 0, 63, $chunkSize));
    }

    private function boundsLabel(MapBounds $bounds): string
    {
        return "x={$bounds->minX}..{$bounds->maxX},y={$bounds->minY}..{$bounds->maxY}";
    }

    private function writeLatestExpansionAudit(World $world): void
    {
        $event = DB::table('audit_events')
            ->where('world_id', $world->id)
            ->where('event_type', 'world.expanded')
            ->orderByDesc('id')
            ->first(['id', 'occurred_at', 'metadata']);
        if ($event === null) {
            $this->line('latest_expansion_audit=none');

            return;
        }

        $metadata = json_decode((string) $event->metadata, true);
        $encodedMetadata = is_array($metadata)
            ? json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : 'invalid';
        $this->line(
            "latest_expansion_audit_id={$event->id} occurred_at={$event->occurred_at} metadata={$encodedMetadata}",
        );
    }
}
