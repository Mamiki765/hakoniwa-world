<?php

namespace App\Domain\Turn;

final class TurnPipeline
{
    /** @var list<TurnPhase> */
    private array $phases;

    /** @param list<TurnPhase>|null $phases */
    public function __construct(?array $phases = null)
    {
        $this->phases = $phases ?? $this->legacyDerivedScaffold();
    }

    /** @return list<TurnPhase> */
    public function phases(): array
    {
        return $this->phases;
    }

    /** @return list<string> */
    public function missingRequiredPhases(): array
    {
        return array_values(array_map(
            static fn (TurnPhase $phase): string => $phase->key(),
            array_filter(
                $this->phases,
                static fn (TurnPhase $phase): bool => $phase->required() && ! $phase->implemented(),
            ),
        ));
    }

    /** @return list<array{key: string, required: bool, implemented: bool, legacy_reference: string|null}> */
    public function snapshot(): array
    {
        return array_map(
            static fn (TurnPhase $phase): array => [
                'key' => $phase->key(),
                'required' => $phase->required(),
                'implemented' => $phase->implemented(),
                'legacy_reference' => $phase instanceof ScaffoldTurnPhase ? $phase->legacyReference() : null,
            ],
            $this->phases,
        );
    }

    /** @return list<TurnPhase> */
    private function legacyDerivedScaffold(): array
    {
        return [
            new ScaffoldTurnPhase('prepare_turn', true, legacyReference: 'turn.c:9-24'),
            new ScaffoldTurnPhase('calculate_terrain_context', false, legacyReference: 'turn.c:26-31'),
            new ScaffoldTurnPhase('resolve_territory_influence', false, legacyReference: 'turn.c:33-36'),
            new ScaffoldTurnPhase('nation_economy', false, legacyReference: 'turn.c:38-47; info.c:285-303'),
            new ScaffoldTurnPhase('development_commands', false, legacyReference: 'turn.c:49-55; command.c:74-103'),
            new ScaffoldTurnPhase('process_cells', false, legacyReference: 'turn.c:57-60; map.c:264-739'),
            new ScaffoldTurnPhase('settle_deferred_effects', false, legacyReference: 'turn.c:62-66'),
            new ScaffoldTurnPhase('global_disasters', false, legacyReference: 'turn.c:68-69; map.c:742-819'),
            new ScaffoldTurnPhase('aggregate_nations', false, legacyReference: 'turn.c:71-72; map.c:1293-1326'),
            new ScaffoldTurnPhase('enforce_capacities', false, legacyReference: 'turn.c:74-91'),
            new ScaffoldTurnPhase('finalize_turn', true, legacyReference: 'turn.c:93-148'),
        ];
    }
}
