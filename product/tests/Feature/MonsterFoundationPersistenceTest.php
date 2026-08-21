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
use Tests\Support\V11SecretaryItemRulesetFixture;
use Tests\TestCase;

final class MonsterFoundationPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_keeps_all_historical_monster_rows_null_and_enforces_nonnegative_unique_explicit_orders(): void
    {
        $this->assertTrue(Schema::hasColumn('monster_definitions', 'display_order'));
        $this->assertSame(0, MonsterDefinition::query()
            ->whereHas('rulesetVersion', fn ($query) => $query->where('version', '<=', 10))
            ->whereNotNull('display_order')->count());
        $current = RulesetVersion::query()->where('key', 'hakoniwa-2s-plus-v10')->firstOrFail();
        $definitions = MonsterDefinition::query()->where('ruleset_version_id', $current->id)->orderBy('id')->get();
        $this->assertCount(8, $definitions);
        $definitions[0]->update(['display_order' => 0]);
        $definitions[1]->update(['display_order' => 50]);
        $this->assertSame(0, $definitions[0]->fresh()->display_order);
        $this->assertSame(50, $definitions[1]->fresh()->display_order);
        $this->assertIsInt($definitions[1]->fresh()->display_order);

        $nullable = $definitions[2]->replicate();
        $nullable->key = 'future_nullable_monster';
        $nullable->asset_key = 'hakoniwa_custom.monster.future_nullable_monster';
        $nullable->display_order = null;
        $nullable->save();
        $this->assertNull($nullable->fresh()->display_order);
        $this->assertGreaterThan(1, MonsterDefinition::query()
            ->where('ruleset_version_id', $current->id)
            ->whereNull('display_order')
            ->count());

        $differentRuleset = MonsterDefinition::query()
            ->where('ruleset_version_id', '!=', $current->id)
            ->firstOrFail();
        $differentRuleset->update(['display_order' => 50]);
        $this->assertSame(50, $differentRuleset->fresh()->display_order);

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
        $this->assertNull($definitions[2]->fresh()->display_order);
        $this->assertNull($definitions[3]->fresh()->display_order);
    }

    public function test_historical_publisher_remains_idempotent_with_and_without_the_new_schema_capability(): void
    {
        $settings = config('hakoniwa.published_rulesets.hakoniwa-2s-plus-v10');
        $published = RulesetVersion::query()->where('key', 'hakoniwa-2s-plus-v10')->firstOrFail();
        $this->assertSame($published->id, app(RulesetPublisher::class)->publish($settings)->id);
        $this->assertSame(0, MonsterDefinition::query()
            ->where('ruleset_version_id', $published->id)
            ->whereNotNull('display_order')
            ->count());

        DB::statement('ALTER TABLE monster_definitions DROP COLUMN display_order');
        $this->assertFalse(Schema::hasColumn('monster_definitions', 'display_order'));
        $this->assertSame($published->id, app(RulesetPublisher::class)->publish($settings)->id);
    }

    public function test_publisher_persists_and_immutably_compares_every_explicit_order_in_the_extended_fixture(): void
    {
        $settings = V11SecretaryItemRulesetFixture::settings();

        $published = app(RulesetPublisher::class)->publish($settings);

        $this->assertSame($settings['key'], $published->key);
        $this->assertSame($settings['version'], $published->version);
        $this->assertSame(
            [0, 50, 100, 200, 300, 400, 450, 500, 600, 700],
            MonsterDefinition::query()->where('ruleset_version_id', $published->id)
                ->orderBy('display_order')->pluck('display_order')->all(),
        );
        $this->assertSame($published->id, app(RulesetPublisher::class)->publish($settings)->id);

        $zero = MonsterDefinition::query()->where('ruleset_version_id', $published->id)
            ->where('key', 'mecha_inora_zero')->firstOrFail();
        $zero->update(['display_order' => 51]);
        try {
            app(RulesetPublisher::class)->publish($settings);
            $this->fail('Published explicit display-order drift was accepted.');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(51, $zero->fresh()->display_order);
    }
}
