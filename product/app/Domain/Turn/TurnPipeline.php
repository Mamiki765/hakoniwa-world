<?php

namespace App\Domain\Turn;

final class TurnPipeline
{
    /** @var list<string> */
    public const CANONICAL_PHASE_KEYS = [
        'prepare_turn',
        'calculate_terrain_context',
        'resolve_territory_influence',
        'nation_economy',
        'development_commands',
        'process_cells',
        'settle_deferred_effects',
        'global_disasters',
        'aggregate_nations',
        'enforce_capacities',
        'finalize_turn',
    ];

    /** @var list<string> */
    public const CANONICAL_REQUIRED_PHASE_KEYS = self::CANONICAL_PHASE_KEYS;

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
                static fn (TurnPhase $phase): bool => in_array(
                    $phase->key(),
                    self::CANONICAL_REQUIRED_PHASE_KEYS,
                    true,
                ) && ! $phase->implemented(),
            ),
        ));
    }

    /**
     * @return array{
     *     valid: bool,
     *     expected_phase_order: list<string>,
     *     actual_phase_order: list<string>,
     *     missing_phases: list<string>,
     *     duplicated_phases: list<string>,
     *     unexpected_phases: list<string>,
     *     non_required_phases: list<string>,
     *     out_of_order_phases: list<array{position: int, expected: string|null, actual: string|null}>
     * }
     */
    public function canonicalValidation(): array
    {
        $actual = array_map(
            static fn (TurnPhase $phase): string => $phase->key(),
            $this->phases,
        );
        $counts = array_count_values($actual);
        $duplicated = [];
        foreach ($actual as $key) {
            if (($counts[$key] ?? 0) > 1 && ! in_array($key, $duplicated, true)) {
                $duplicated[] = $key;
            }
        }
        $missing = array_values(array_filter(
            self::CANONICAL_PHASE_KEYS,
            static fn (string $key): bool => ! in_array($key, $actual, true),
        ));
        $unexpected = [];
        foreach ($actual as $key) {
            if (! in_array($key, self::CANONICAL_PHASE_KEYS, true)
                && ! in_array($key, $unexpected, true)) {
                $unexpected[] = $key;
            }
        }
        $nonRequired = [];
        foreach ($this->phases as $phase) {
            if (in_array($phase->key(), self::CANONICAL_REQUIRED_PHASE_KEYS, true)
                && ! $phase->required()
                && ! in_array($phase->key(), $nonRequired, true)) {
                $nonRequired[] = $phase->key();
            }
        }
        $outOfOrder = [];
        $positions = max(count(self::CANONICAL_PHASE_KEYS), count($actual));
        for ($index = 0; $index < $positions; $index++) {
            $expected = self::CANONICAL_PHASE_KEYS[$index] ?? null;
            $supplied = $actual[$index] ?? null;
            if ($expected !== $supplied) {
                $outOfOrder[] = [
                    'position' => $index + 1,
                    'expected' => $expected,
                    'actual' => $supplied,
                ];
            }
        }

        return [
            'valid' => $actual === self::CANONICAL_PHASE_KEYS && $nonRequired === [],
            'expected_phase_order' => self::CANONICAL_PHASE_KEYS,
            'actual_phase_order' => $actual,
            'missing_phases' => $missing,
            'duplicated_phases' => $duplicated,
            'unexpected_phases' => $unexpected,
            'non_required_phases' => $nonRequired,
            'out_of_order_phases' => $outOfOrder,
        ];
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
