<?php

namespace App\Application;

use App\Domain\Command\PlayerFacingCommandException;
use App\Models\CommandDefinition;
use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\Nation;
use DomainException;
use Illuminate\Database\Eloquent\Builder;

final class NationCommandTargetService
{
    /**
     * @return list<array{value: int, label: string, nation_number: int}>
     */
    public function options(Nation $sender): array
    {
        $targets = $this->selectableQuery($sender)
            ->orderBy('nation_number')
            ->orderBy('id')
            ->get(['id', 'name', 'nation_number']);

        return $this->presentOptions($targets);
    }

    /**
     * @return list<array{value: int, label: string, nation_number: int}>
     */
    public function monsterDispatchOptions(Nation $sender): array
    {
        $targets = $this->selectableQuery($sender, ['active', 'dormant'])
            ->orderBy('nation_number')
            ->orderBy('id')
            ->get(['id', 'name', 'nation_number']);

        return $this->presentOptions($targets);
    }

    /**
     * @return list<array{value: int, label: string, nation_number: int}>
     */
    public function monumentFlightOptions(Nation $sender): array
    {
        $targets = $this->selectableQuery($sender)
            ->orderBy('nation_number')
            ->orderBy('id')
            ->get(['id', 'world_id', 'name', 'nation_number'])
            ->filter(fn (Nation $target): bool => $this->hasCompleteCapitalChunk($target));

        return $this->presentOptions($targets);
    }

    public function validateMonumentFlightRegistration(Nation $sender, int $targetNationId): void
    {
        $target = $this->selectableQuery($sender)->whereKey($targetNationId)->first();
        if ($target === null) {
            throw new PlayerFacingCommandException('対象島は同じWorldの選択可能なactive島から選んでください。');
        }
        if (! $this->hasCompleteCapitalChunk($target)) {
            throw new PlayerFacingCommandException('対象島の首都海域が16×16セルに満たないため、記念碑を飛ばせません。');
        }
    }

    public function hasCompleteCapitalChunk(Nation $target): bool
    {
        $capitalCell = MapCell::query()
            ->join('nation_capitals', 'nation_capitals.map_cell_id', '=', 'map_cells.id')
            ->where('nation_capitals.nation_id', $target->id)
            ->first(['map_cells.map_space_id', 'map_cells.chunk_x', 'map_cells.chunk_y']);
        if ($capitalCell === null) {
            return false;
        }
        $space = MapSpace::query()->whereKey($capitalCell->map_space_id)->first();
        if ($space === null || $space->world_id !== $target->world_id
            || $space->currentBounds()->cellCountWithinChunk($capitalCell->chunk_x, $capitalCell->chunk_y) !== 256) {
            return false;
        }

        return MapCell::query()->where('map_space_id', $space->id)
            ->where('chunk_x', $capitalCell->chunk_x)
            ->where('chunk_y', $capitalCell->chunk_y)
            ->count() === 256;
    }

    /**
     * @param  iterable<int, Nation>  $targets
     * @return list<array{value: int, label: string, nation_number: int}>
     */
    private function presentOptions(iterable $targets): array
    {
        return collect($targets)
            ->map(static fn (Nation $nation): array => [
                'value' => (int) $nation->id,
                'label' => $nation->name,
                'nation_number' => (int) $nation->nation_number,
            ])->all();
    }

    public function requiresTarget(CommandDefinition $definition): bool
    {
        $schemas = $definition->metadata['parameters'] ?? [];

        return is_array($schemas)
            && is_array($schemas['target_nation_id'] ?? null)
            && ($schemas['target_nation_id']['required'] ?? false) === true;
    }

    /** @return array<string, mixed>|null */
    private function targetSchema(CommandDefinition $definition): ?array
    {
        $schemas = $definition->metadata['parameters'] ?? [];

        return is_array($schemas) && is_array($schemas['target_nation_id'] ?? null)
            ? $schemas['target_nation_id']
            : null;
    }

    /**
     * @param  list<array{value: int, label: string, nation_number: int}>  $options
     * @return array<string, array<string, mixed>>
     */
    public function presentParameters(CommandDefinition $definition, array $options): array
    {
        $schemas = $definition->metadata['parameters'] ?? [];
        if (! is_array($schemas)) {
            throw new DomainException('command parameter schema is invalid.');
        }

        $presented = [];
        foreach ($schemas as $key => $schema) {
            if (! is_string($key) || ! is_array($schema)) {
                throw new DomainException('command parameter schema is invalid.');
            }

            $presented[$key] = [
                ...$schema,
                'input_semantics' => $key === 'target_nation_id' ? 'nation_selector' : 'number',
                'options' => $key === 'target_nation_id' ? $options : [],
            ];
            if ($key === 'target_nation_id') {
                $presented[$key]['label'] = '対象島';
            }
        }

        return $presented;
    }

    /** @param array<string, mixed> $parameters */
    public function validateRegistration(Nation $sender, CommandDefinition $definition, array $parameters): void
    {
        $schema = $this->targetSchema($definition);
        if ($schema === null) {
            return;
        }

        $targetNationId = $parameters['target_nation_id'] ?? null;
        if ($targetNationId === null && ($schema['required'] ?? false) !== true) {
            return;
        }
        $targetStates = $definition->key === 'monster_dispatch' ? ['active', 'dormant'] : ['active'];
        if (! is_int($targetNationId)
            || ! $this->selectableQuery($sender, $targetStates)->whereKey($targetNationId)->exists()) {
            throw new PlayerFacingCommandException($definition->key === 'monster_dispatch'
                ? '怪獣派遣の対象島は同じWorldの選択可能な島から選んでください。'
                : '対象島は同じWorldの選択可能なactive島から選んでください。');
        }
    }

    /**
     * @param  non-empty-list<string>  $states
     * @return Builder<Nation>
     */
    private function selectableQuery(Nation $sender, array $states = ['active']): Builder
    {
        return Nation::query()
            ->where('world_id', $sender->world_id)
            ->whereIn('state', $states)
            ->where('id', '<>', $sender->id);
    }
}
