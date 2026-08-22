<?php

namespace Tests\Feature;

use App\Application\RulesetPublisher;
use App\Models\MonsterDefinition;
use App\Models\RulesetVersion;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class MonsterFoundationPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_schema_persists_explicit_order_and_enforces_nonnegative_unique_values(): void
    {
        $this->assertTrue(Schema::hasColumn('monster_definitions', 'display_order'));
        $current = RulesetVersion::query()->where('key', config('hakoniwa.ruleset.key'))->sole();
        $definitions = MonsterDefinition::query()
            ->where('ruleset_version_id', $current->id)
            ->orderBy('display_order')
            ->get();

        $this->assertSame(
            [0, 50, 100, 200, 300, 400, 450, 500, 600, 700],
            $definitions->pluck('display_order')->all(),
        );

        foreach ([
            static fn () => $definitions[2]->update(['display_order' => -1]),
            static fn () => $definitions[2]->update(['display_order' => 50]),
        ] as $mutation) {
            try {
                DB::transaction($mutation);
                $this->fail('Expected display-order database constraint failure.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }

        try {
            DB::transaction(static function () use ($definitions): void {
                $definitions[2]->update(['display_order' => 75]);
                $definitions[3]->update(['display_order' => 50]);
            });
            $this->fail('Expected the duplicate order to roll back the whole transaction.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(100, $definitions[2]->fresh()->display_order);
        $this->assertSame(200, $definitions[3]->fresh()->display_order);
    }

    public function test_current_publisher_reuses_exact_orders_and_rejects_persisted_order_drift(): void
    {
        $settings = config('hakoniwa.ruleset');
        $publisher = app(RulesetPublisher::class);
        $published = RulesetVersion::query()->where('key', $settings['key'])->sole();

        $this->assertSame($published->id, $publisher->publish($settings)->id);
        $zero = MonsterDefinition::query()
            ->where('ruleset_version_id', $published->id)
            ->where('key', 'mecha_inora_zero')
            ->sole();
        $zero->update(['display_order' => 51]);

        try {
            $publisher->publish($settings);
            $this->fail('Published explicit display-order drift was accepted.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('differs from its snapshot', $exception->getMessage());
        }
        $this->assertSame(51, $zero->fresh()->display_order);
    }
}
