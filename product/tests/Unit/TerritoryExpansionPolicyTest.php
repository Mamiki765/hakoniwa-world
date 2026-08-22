<?php

namespace Tests\Unit;

use App\Domain\Command\CommandFailureReason;
use App\Domain\Command\TerritoryExpansionFacts;
use App\Domain\Command\TerritoryExpansionPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class TerritoryExpansionPolicyTest extends TestCase
{
    #[DataProvider('currentCases')]
    public function test_current_manual_expansion_contract(
        array $changes,
        ?CommandFailureReason $expected,
    ): void {
        $facts = $this->facts($changes);
        $metadata = $this->territoryDefinition(
            config('hakoniwa.ruleset'),
        )['metadata'];

        $this->assertSame(
            $expected,
            app(TerritoryExpansionPolicy::class)->failureReason($metadata, $facts),
        );
    }

    /** @return iterable<string, array{array<string, mixed>, CommandFailureReason|null}> */
    public static function currentCases(): iterable
    {
        yield 'neutral wasteland remains allowed' => [
            ['targetOwnerNationId' => null, 'targetOwnerNationState' => null],
            null,
        ];
        yield 'foreign wasteland' => [[], null];
        yield 'foreign scorched' => [['terrainKey' => 'scorched'], null];
        yield 'foreign settlement terrain' => [['terrainKey' => 'plain'], CommandFailureReason::ForeignOwned];
        yield 'foreign facility' => [['facilityKey' => 'farm'], CommandFailureReason::FacilityExists];
        yield 'missing adjacency' => [['adjacentActorTerritory' => false], CommandFailureReason::MissingAdjacentTerritory];
        yield 'capital core' => [['capitalCoreProtected' => true], CommandFailureReason::CapitalProtected];
        yield 'monster occupancy' => [['monsterOccupied' => true], CommandFailureReason::OccupiedByMonster];
        yield 'inactive actor' => [['actorNationState' => 'dormant_frozen'], CommandFailureReason::InvalidTargetNation];
        yield 'dormant foreign owner' => [['targetOwnerNationState' => 'dormant_frozen'], CommandFailureReason::InvalidTargetNation];
        yield 'sunken foreign owner' => [['targetOwnerNationState' => 'sunken_archived'], CommandFailureReason::InvalidTargetNation];
        yield 'foreign owner from another World' => [['targetOwnerInActorWorld' => false], CommandFailureReason::InvalidTargetNation];
        yield 'self owned' => [['targetOwnerNationId' => 10], CommandFailureReason::AlreadyOwned];
    }

    /** @param array<string, mixed> $changes */
    private function facts(array $changes = []): TerritoryExpansionFacts
    {
        $defaults = [
            'actorNationId' => 10,
            'actorNationState' => 'active',
            'targetOwnerNationId' => 20,
            'targetOwnerNationState' => 'active',
            'targetOwnerInActorWorld' => true,
            'terrainKey' => 'wasteland',
            'facilityKey' => null,
            'monsterOccupied' => false,
            'capitalCoreProtected' => false,
            'adjacentActorTerritory' => true,
            'definitionTargetTerrainKeys' => ['wasteland', 'scorched', 'plain', 'forest', 'mountain'],
            'definitionRequiresEmptyFacility' => true,
        ];

        return new TerritoryExpansionFacts(...array_values([...$defaults, ...$changes]));
    }

    /**
     * @param  array<string, mixed>  $ruleset
     * @return array<string, mixed>
     */
    private function territoryDefinition(array $ruleset): array
    {
        return collect($ruleset['command_definitions'])
            ->firstWhere('key', 'territory_expand');
    }
}
