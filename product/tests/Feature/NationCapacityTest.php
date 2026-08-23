<?php

namespace Tests\Feature;

use App\Application\NationCreationService;
use App\Domain\Economy\CapacityBoundedAssetService;
use App\Domain\Economy\CapacityModifier;
use App\Domain\Economy\NationCapacityResolver;
use App\Models\Nation;
use App\Models\NationResource;
use App\Models\ResourceDefinition;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

class NationCapacityTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    public function test_current_capacity_resolution_modifier_boundary_and_bounded_credits(): void
    {
        [, $nation] = $this->nation('容量国');
        $base = app(NationCapacityResolver::class)->resolve($nation);

        $this->assertSame(9_999, $base->money);
        $this->assertSame(999_900, $base->foodTons);
        $this->assertSame([
            'industrial_goods' => 9_999_000,
            'minerals' => 9_999_000,
        ], $base->resources);
        $modifier = new class implements CapacityModifier {};

        try {
            (new NationCapacityResolver([$modifier]))->resolve($nation);
            $this->fail('Expected the deferred capacity modifier boundary to fail closed.');
        } catch (DomainException $exception) {
            $this->assertSame(
                'Capacity modifier semantics are deferred until E-04 is decided.',
                $exception->getMessage(),
            );
        }

        $service = app(CapacityBoundedAssetService::class);

        $nation->update(['money' => 9_998]);
        $result = $service->creditMoney($nation, 10);
        $this->assertSame(1, $result->applied);
        $this->assertSame(9, $result->overflow);
        $this->assertSame(9_999, $result->after);
        $this->assertSame(9_999, $nation->fresh()->money);

        $full = $service->creditMoney($nation, 1);
        $this->assertSame(0, $full->applied);
        $this->assertSame(1, $full->overflow);
        $this->assertSame(9_999, $nation->fresh()->money);

        $balances = NationResource::query()->where('nation_id', $nation->id)->get()
            ->keyBy('resource_definition_id');
        $definitions = ResourceDefinition::query()->get()->keyBy('key');

        foreach (['wheat', 'fish', 'monster_meat'] as $key) {
            $balances[$definitions[$key]->id]->update(['amount' => 0]);
        }
        $balances[$definitions['wheat']->id]->update(['amount' => 700_000]);
        $balances[$definitions['fish']->id]->update(['amount' => 150_000]);
        $balances[$definitions['monster_meat']->id]->update(['amount' => 149_800]);
        $balances[$definitions['industrial_goods']->id]->update(['amount' => 500_000]);

        $newFood = ResourceDefinition::query()->create([
            'key' => 'seaweed',
            'name' => '海藻',
            'category' => 'food',
            'unit' => 'ton',
            'unit_label' => 'トン',
            'nutrition_per_unit' => 1,
            'storable' => true,
            'tradable' => true,
            'sale_price_key' => null,
            'sort_order' => 35,
            'metadata' => [],
        ]);
        NationResource::query()->create([
            'nation_id' => $nation->id,
            'resource_definition_id' => $newFood->id,
            'amount' => 0,
        ]);

        $result = app(CapacityBoundedAssetService::class)->creditFood($nation, $newFood, 500);

        $this->assertSame(999_800, $result->before);
        $this->assertSame(100, $result->applied);
        $this->assertSame(400, $result->overflow);
        $this->assertSame(999_900, $result->after);
        $this->assertSame(
            999_900,
            (int) NationResource::query()
                ->where('nation_id', $nation->id)
                ->whereHas('definition', fn ($query) => $query->where('category', 'food'))
                ->sum('amount'),
        );
        $this->assertSame(100, NationResource::query()
            ->where('nation_id', $nation->id)
            ->where('resource_definition_id', $newFood->id)
            ->value('amount'));
        $this->assertSame(500_000, $balances[$definitions['industrial_goods']->id]->fresh()->amount);
    }

    /** @return array{User, Nation} */
    private function nation(string $name): array
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, $name, '試験島主');

        return [$user, $nation];
    }
}
