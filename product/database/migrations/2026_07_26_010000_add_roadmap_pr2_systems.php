<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const FOREST_INITIAL_TREES = 500;

    private const MISSILE_BASE_INITIAL_EXPERIENCE = 0;

    public function up(): void
    {
        Schema::table('terrain_definitions', function (Blueprint $table): void {
            $table->string('quantity_key')->nullable();
            $table->string('quantity_label')->nullable();
            $table->string('quantity_unit')->nullable();
            $table->unsignedBigInteger('initial_quantity')->nullable();
            $table->unsignedBigInteger('minimum_quantity')->nullable();
            $table->unsignedBigInteger('maximum_quantity')->nullable();
            $table->string('growth_rule_key')->nullable();
            $table->jsonb('metadata')->default('{}');
        });

        Schema::table('facility_definitions', function (Blueprint $table): void {
            $table->boolean('enabled')->default(true);
            $table->string('build_command_key')->nullable();
            $table->string('visibility_policy')->default('public');
            $table->string('disguise_terrain_key')->nullable();
            $table->string('disguise_asset_key')->nullable();
            $table->unsignedInteger('scale_unit_people')->nullable();
            $table->unsignedInteger('initial_scale')->nullable();
            $table->unsignedInteger('scale_increment')->nullable();
            $table->unsignedInteger('maximum_scale')->nullable();
            $table->unsignedInteger('workforce_per_scale_people')->nullable();
            $table->string('production_definition_key')->nullable();
            $table->jsonb('buildable_terrain_keys')->default('[]');
            $table->jsonb('metadata')->default('{}');
        });

        Schema::table('map_cells', function (Blueprint $table): void {
            $table->unsignedBigInteger('terrain_quantity')->nullable()->after('population');
            $table->unsignedInteger('facility_scale')->nullable()->after('terrain_quantity');
            $table->unsignedInteger('facility_experience')->nullable()->after('facility_scale');
            $table->string('facility_operational_state')->nullable()->after('facility_experience');
            $table->index(['facility_definition_id', 'facility_scale']);
            $table->index(['facility_definition_id', 'facility_experience']);
        });

        Schema::create('command_definitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ruleset_version_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('name');
            $table->text('description');
            $table->string('target_type');
            $table->jsonb('target_terrain_keys')->default('[]');
            $table->jsonb('target_facility_keys')->default('[]');
            $table->boolean('requires_empty_facility')->default(false);
            $table->unsignedBigInteger('cost_money')->default(0);
            $table->jsonb('required_resources')->default('{}');
            $table->string('execution_phase');
            $table->string('result_terrain_key')->nullable();
            $table->string('result_facility_key')->nullable();
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->jsonb('metadata')->default('{}');
            $table->timestamps();
            $table->unique(['ruleset_version_id', 'key']);
        });

        Schema::create('production_definitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ruleset_version_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->foreignId('facility_definition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('output_resource_definition_id')->constrained('resource_definitions')->cascadeOnDelete();
            $table->decimal('production_per_scale', 16, 4);
            $table->unsignedInteger('required_workforce_per_scale');
            $table->string('operating_condition');
            $table->string('price_reference');
            $table->boolean('enabled')->default(true);
            $table->jsonb('metadata')->default('{}');
            $table->timestamps();
            $table->unique(['ruleset_version_id', 'key']);
            $table->unique(['ruleset_version_id', 'facility_definition_id']);
        });

        Schema::create('nation_command_queues', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('nation_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('map_space_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamps();
        });

        Schema::create('nation_command_queue_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('nation_command_queue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('command_definition_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('queue_position')->nullable();
            $table->integer('target_q');
            $table->integer('target_r');
            $table->jsonb('parameters')->default('{}');
            $table->string('status')->default('queued');
            $table->foreignId('queued_by_membership_id')->constrained('nation_memberships')->restrictOnDelete();
            $table->uuid('request_key');
            $table->timestampTz('queued_at');
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampTz('execution_started_at')->nullable();
            $table->timestampTz('execution_completed_at')->nullable();
            $table->timestampTz('execution_failed_at')->nullable();
            $table->string('failure_code')->nullable();
            $table->jsonb('failure_metadata')->default('{}');
            $table->timestamps();
            $table->unique(['nation_command_queue_id', 'queue_position']);
            $table->unique(['nation_command_queue_id', 'request_key']);
            $table->index(['nation_command_queue_id', 'status', 'queue_position'], 'command_queue_active_order');
        });

        Schema::create('nation_resource_sale_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('nation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('resource_definition_id')->constrained()->cascadeOnDelete();
            $table->string('policy')->default('stockpile');
            $table->unsignedBigInteger('keep_amount')->nullable();
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['nation_id', 'resource_definition_id']);
        });

        $this->upgradeCatalogsAndRuleset();
        $this->backfillTypedCellState();
        $this->backfillNationResourcesAndPolicies();
    }

    public function down(): void
    {
        Schema::dropIfExists('nation_resource_sale_policies');
        Schema::dropIfExists('nation_command_queue_items');
        Schema::dropIfExists('nation_command_queues');
        Schema::dropIfExists('production_definitions');
        Schema::dropIfExists('command_definitions');

        Schema::table('map_cells', function (Blueprint $table): void {
            $table->dropIndex(['facility_definition_id', 'facility_scale']);
            $table->dropIndex(['facility_definition_id', 'facility_experience']);
            $table->dropColumn(['terrain_quantity', 'facility_scale', 'facility_experience', 'facility_operational_state']);
        });

        Schema::table('facility_definitions', function (Blueprint $table): void {
            $table->dropColumn([
                'enabled', 'build_command_key', 'visibility_policy', 'disguise_terrain_key', 'disguise_asset_key',
                'scale_unit_people', 'initial_scale', 'scale_increment', 'maximum_scale',
                'workforce_per_scale_people', 'production_definition_key', 'buildable_terrain_keys', 'metadata',
            ]);
        });

        Schema::table('terrain_definitions', function (Blueprint $table): void {
            $table->dropColumn([
                'quantity_key', 'quantity_label', 'quantity_unit', 'initial_quantity', 'minimum_quantity',
                'maximum_quantity', 'growth_rule_key', 'metadata',
            ]);
        });
    }

    private function upgradeCatalogsAndRuleset(): void
    {
        $now = now();
        $rules = config('hakoniwa.published_rulesets.roadmap-pr2-v1');
        if (! is_array($rules)) {
            throw new RuntimeException('The immutable roadmap-pr2-v1 ruleset snapshot is missing.');
        }
        $oldRulesetId = DB::table('ruleset_versions')->where('key', 'mvp-v1')->value('id');
        $rulesetId = DB::table('ruleset_versions')->where('key', $rules['key'])->value('id');

        if ($rulesetId === null && DB::table('worlds')->exists()) {
            $rulesetId = DB::table('ruleset_versions')->insertGetId([
                'key' => $rules['key'],
                'version' => $rules['version'],
                'settings' => json_encode($rules, JSON_THROW_ON_ERROR),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if ($rulesetId !== null && $oldRulesetId !== null) {
            DB::table('worlds')->where('ruleset_version_id', $oldRulesetId)->update([
                'ruleset_version_id' => $rulesetId,
                'updated_at' => $now,
            ]);
        }

        $forest = $rules['terrain_quantities']['forest'];
        DB::table('terrain_definitions')->where('key', 'forest')->update([
            'asset_key' => 'tile.forest',
            'quantity_key' => $forest['key'],
            'quantity_label' => $forest['label'],
            'quantity_unit' => $forest['unit'],
            'initial_quantity' => $forest['initial_quantity'],
            'minimum_quantity' => $forest['minimum_quantity'],
            'maximum_quantity' => $forest['maximum_quantity'],
            'growth_rule_key' => $forest['growth_rule_key'],
            'metadata' => json_encode(['legacy_quantity_unit' => 100, 'growth_increment' => $forest['growth_increment']], JSON_THROW_ON_ERROR),
            'updated_at' => $now,
        ]);

        foreach ($rules['facility_definitions'] as $key => $definition) {
            DB::table('facility_definitions')->updateOrInsert(
                ['key' => $key],
                [
                    'name' => $definition['name'],
                    'asset_key' => $definition['asset_key'],
                    'enabled' => true,
                    'build_command_key' => $definition['build_command_key'],
                    'visibility_policy' => $definition['visibility_policy'],
                    'disguise_terrain_key' => $definition['disguise_terrain_key'] ?? null,
                    'disguise_asset_key' => $definition['disguise_asset_key'] ?? null,
                    'scale_unit_people' => $definition['scale_unit_people'],
                    'initial_scale' => $definition['initial_scale'],
                    'scale_increment' => $definition['scale_increment'],
                    'maximum_scale' => $definition['maximum_scale'],
                    'workforce_per_scale_people' => $definition['workforce_per_scale_people'],
                    'production_definition_key' => $definition['production_definition_key'],
                    'buildable_terrain_keys' => json_encode($definition['buildable_terrain_keys'], JSON_THROW_ON_ERROR),
                    'metadata' => json_encode(array_filter([
                        'initial_experience' => $definition['initial_experience'] ?? null,
                        'maximum_experience' => $definition['maximum_experience'] ?? null,
                        'level_thresholds' => $definition['level_thresholds'] ?? null,
                        'launch_capacity_by_level' => $definition['launch_capacity_by_level'] ?? null,
                    ], static fn (mixed $value): bool => $value !== null), JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }

        foreach ($rules['resource_definitions'] as $definition) {
            DB::table('resource_definitions')->updateOrInsert(
                ['key' => $definition['key']],
                [...$definition, 'metadata' => json_encode($definition['metadata'], JSON_THROW_ON_ERROR), 'created_at' => $now, 'updated_at' => $now],
            );
        }

        if ($rulesetId === null) {
            return;
        }

        foreach ($rules['command_definitions'] as $definition) {
            DB::table('command_definitions')->updateOrInsert(
                ['ruleset_version_id' => $rulesetId, 'key' => $definition['key']],
                [
                    ...$definition,
                    'ruleset_version_id' => $rulesetId,
                    'target_terrain_keys' => json_encode($definition['target_terrain_keys'], JSON_THROW_ON_ERROR),
                    'target_facility_keys' => json_encode($definition['target_facility_keys'], JSON_THROW_ON_ERROR),
                    'required_resources' => json_encode($definition['required_resources'], JSON_THROW_ON_ERROR),
                    'metadata' => json_encode($definition['metadata'], JSON_THROW_ON_ERROR),
                    'enabled' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }

        foreach ($rules['production_definitions'] as $definition) {
            $facilityId = DB::table('facility_definitions')->where('key', $definition['facility_key'])->value('id');
            $resourceId = DB::table('resource_definitions')->where('key', $definition['output_resource_key'])->value('id');
            if ($facilityId === null || $resourceId === null) {
                continue;
            }
            DB::table('production_definitions')->updateOrInsert(
                ['ruleset_version_id' => $rulesetId, 'key' => $definition['key']],
                [
                    'facility_definition_id' => $facilityId,
                    'output_resource_definition_id' => $resourceId,
                    'production_per_scale' => $definition['production_per_scale'],
                    'required_workforce_per_scale' => $definition['required_workforce_per_scale'],
                    'operating_condition' => $definition['operating_condition'],
                    'price_reference' => $definition['price_reference'],
                    'enabled' => true,
                    'metadata' => json_encode($definition['metadata'], JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
    }

    private function backfillTypedCellState(): void
    {
        $forestId = DB::table('terrain_definitions')->where('key', 'forest')->value('id');
        if ($forestId !== null) {
            DB::table('map_cells')->where('terrain_definition_id', $forestId)->whereNull('terrain_quantity')->update([
                'terrain_quantity' => self::FOREST_INITIAL_TREES,
            ]);
        }

        $missileBaseId = DB::table('facility_definitions')->where('key', 'missile_base')->value('id');
        if ($missileBaseId !== null) {
            DB::table('map_cells')->where('facility_definition_id', $missileBaseId)->whereNull('facility_experience')->update([
                'facility_experience' => self::MISSILE_BASE_INITIAL_EXPERIENCE,
                'facility_scale' => null,
                'facility_operational_state' => 'operational',
            ]);
        }

        foreach (config('hakoniwa.published_rulesets.roadmap-pr2-v1.facility_definitions') as $key => $definition) {
            if ($definition['initial_scale'] === null) {
                continue;
            }

            $facilityId = DB::table('facility_definitions')->where('key', $key)->value('id');
            if ($facilityId !== null) {
                DB::table('map_cells')->where('facility_definition_id', $facilityId)->whereNull('facility_scale')->update([
                    'facility_scale' => $definition['initial_scale'],
                    'facility_experience' => null,
                    'facility_operational_state' => 'operational',
                ]);
            }
        }
    }

    private function backfillNationResourcesAndPolicies(): void
    {
        $now = now();
        $rules = config('hakoniwa.published_rulesets.roadmap-pr2-v1');
        $resources = DB::table('resource_definitions')->get(['id', 'key', 'tradable']);

        foreach (DB::table('nations')->pluck('id') as $nationId) {
            foreach ($resources as $resource) {
                DB::table('nation_resources')->insertOrIgnore([
                    'nation_id' => $nationId,
                    'resource_definition_id' => $resource->id,
                    'amount' => $rules['initial_resources'][$resource->key] ?? 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                if ((bool) $resource->tradable) {
                    DB::table('nation_resource_sale_policies')->insertOrIgnore([
                        'nation_id' => $nationId,
                        'resource_definition_id' => $resource->id,
                        'policy' => $rules['default_sale_policy'] ?? 'stockpile',
                        'keep_amount' => null,
                        'version' => 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }
    }
};
