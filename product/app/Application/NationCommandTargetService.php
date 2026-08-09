<?php

namespace App\Application;

use App\Models\CommandDefinition;
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
        return $this->selectableQuery($sender)
            ->orderBy('nation_number')
            ->orderBy('id')
            ->get(['id', 'name', 'nation_number'])
            ->map(static fn (Nation $nation): array => [
                'value' => (int) $nation->id,
                'label' => $nation->name,
                'nation_number' => (int) $nation->nation_number,
            ])->all();
    }

    public function requiresTarget(CommandDefinition $definition): bool
    {
        $schemas = $definition->metadata['parameters'] ?? [];

        return is_array($schemas) && array_key_exists('target_nation_id', $schemas);
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
        if (! $this->requiresTarget($definition)) {
            return;
        }

        $targetNationId = $parameters['target_nation_id'] ?? null;
        if (! is_int($targetNationId) || ! $this->selectableQuery($sender)->whereKey($targetNationId)->exists()) {
            throw new DomainException('対象島は同じWorldの選択可能なactive島から選んでください。');
        }
    }

    /** @return Builder<Nation> */
    private function selectableQuery(Nation $sender): Builder
    {
        return Nation::query()
            ->where('world_id', $sender->world_id)
            ->where('state', 'active')
            ->where('id', '<>', $sender->id);
    }
}
