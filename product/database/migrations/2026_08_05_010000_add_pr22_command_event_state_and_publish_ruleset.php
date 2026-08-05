<?php

use App\Application\RulesetPublisher;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TARGET_KEY = 'roadmap-pr22-v1';

    public function up(): void
    {
        $published = config('hakoniwa.published_rulesets');
        $settings = is_array($published) ? ($published[self::TARGET_KEY] ?? null) : null;
        if (! is_array($settings)) {
            throw new RuntimeException('The immutable roadmap-pr22-v1 ruleset snapshot is missing.');
        }

        DB::transaction(function () use ($settings): void {
            $this->assertNoWorldTurnOperation();

            Schema::table('nations', function (Blueprint $table): void {
                $table->unsignedBigInteger('idle_counter')->default(0)->after('state');
            });

            Schema::table('facility_definitions', function (Blueprint $table): void {
                $table->string('disguise_ownership_policy')->nullable()->after('disguise_asset_key');
            });

            Schema::create('monument_definitions', function (Blueprint $table): void {
                $table->id();
                $table->string('key')->unique();
                $table->string('name');
                $table->string('asset_key')->unique();
                $table->text('description');
                $table->string('effect_key')->nullable();
                $table->boolean('enabled')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->jsonb('metadata')->default('{}');
                $table->timestamps();
            });

            Schema::table('map_cells', function (Blueprint $table): void {
                $table->foreignId('monument_definition_id')->nullable()
                    ->after('facility_definition_id')
                    ->constrained('monument_definitions')->restrictOnDelete();
            });

            Schema::table('audit_events', function (Blueprint $table): void {
                $table->foreignId('world_id')->nullable()->after('actor_user_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('turn')->nullable()->after('world_id');
                $table->foreignId('nation_id')->nullable()->after('turn')->constrained()->nullOnDelete();
                $table->integer('x')->nullable()->after('nation_id');
                $table->integer('y')->nullable()->after('x');
                $table->text('message')->nullable()->after('y');
                $table->string('visibility', 16)->default('admin')->after('message');
                $table->string('severity', 16)->default('info')->after('event_type');
                $table->index(['world_id', 'turn', 'id'], 'audit_events_world_turn');
                $table->index(['world_id', 'visibility', 'turn'], 'audit_events_visibility_turn');
                $table->index(['nation_id', 'turn', 'id'], 'audit_events_nation_turn');
            });

            DB::statement("ALTER TABLE audit_events ADD CONSTRAINT audit_events_visibility_check CHECK (visibility IN ('public', 'nation', 'private', 'admin'))");
            DB::statement("ALTER TABLE audit_events ADD CONSTRAINT audit_events_severity_check CHECK (severity IN ('info', 'warning', 'critical'))");
            DB::statement('ALTER TABLE nations ADD CONSTRAINT nations_idle_counter_check CHECK (idle_counter >= 0)');

            $this->ensureScorchedTerrainDefinition();
            $this->ensureNewFacilityDefinitions($settings);
            $now = now();
            $updatedMissileBase = DB::table('facility_definitions')->where('key', 'missile_base')->update([
                'build_command_key' => 'build_missile_base',
                'updated_at' => $now,
            ]);
            if ($updatedMissileBase !== 1) {
                throw new RuntimeException('The PR22 missile_base catalog migration expected exactly one row.');
            }
            DB::table('monument_definitions')->insert([
                [
                    'key' => 'peace', 'name' => '平和記念碑', 'asset_key' => 'tile.monument.peace',
                    'description' => '平和を願う記念碑です。', 'effect_key' => null,
                    'enabled' => true, 'sort_order' => 10, 'metadata' => '{}',
                    'created_at' => $now, 'updated_at' => $now,
                ],
                [
                    'key' => 'prosperity', 'name' => '繁栄記念碑', 'asset_key' => 'tile.monument.prosperity',
                    'description' => 'Nationの繁栄を記念する碑です。', 'effect_key' => null,
                    'enabled' => true, 'sort_order' => 20, 'metadata' => '{}',
                    'created_at' => $now, 'updated_at' => $now,
                ],
                [
                    'key' => 'victory', 'name' => '戦勝記念碑', 'asset_key' => 'tile.monument.victory',
                    'description' => '歴史的な勝利を記録する碑です。', 'effect_key' => null,
                    'enabled' => true, 'sort_order' => 30, 'metadata' => '{}',
                    'created_at' => $now, 'updated_at' => $now,
                ],
            ]);

            // Pre-release Worlds stay on their historical ruleset and are reset-required.
            // Publication never rewrites a World, command queue, or prior immutable payload.
            app(RulesetPublisher::class)->publish($settings);
        });
    }

    private function ensureScorchedTerrainDefinition(): void
    {
        $expected = [
            'key' => 'scorched',
            'name' => '焦土',
            'asset_key' => 'tile.scorched',
            'is_water' => false,
            'is_buildable' => true,
            'quantity_key' => null,
            'quantity_label' => null,
            'quantity_unit' => null,
            'initial_quantity' => null,
            'minimum_quantity' => null,
            'maximum_quantity' => null,
            'growth_rule_key' => null,
            'metadata' => ['created_by' => 'missile_impact'],
        ];
        $this->createOrAssertCatalog('terrain_definitions', 'scorched', $expected);
    }

    /** @param array<string, mixed> $settings */
    private function ensureNewFacilityDefinitions(array $settings): void
    {
        foreach (['defense', 'seabed_base', 'monument', 'decoy'] as $key) {
            $definition = $settings['facility_definitions'][$key] ?? null;
            if (! is_array($definition)) {
                throw new RuntimeException("The roadmap-pr22-v1 {$key} facility definition is missing.");
            }
            $metadata = array_filter([
                'initial_experience' => $definition['initial_experience'] ?? null,
                'maximum_experience' => $definition['maximum_experience'] ?? null,
                'level_thresholds' => $definition['level_thresholds'] ?? null,
                'launch_capacity_by_level' => $definition['launch_capacity_by_level'] ?? null,
                'display_as_facility_key' => $definition['display_as_facility_key'] ?? null,
            ], static fn (mixed $value): bool => $value !== null);
            $this->createOrAssertCatalog('facility_definitions', $key, [
                'key' => $key,
                'name' => $definition['name'],
                'asset_key' => $definition['asset_key'],
                'enabled' => true,
                'build_command_key' => $definition['build_command_key'],
                'visibility_policy' => $definition['visibility_policy'],
                'disguise_terrain_key' => $definition['disguise_terrain_key'] ?? null,
                'disguise_asset_key' => $definition['disguise_asset_key'] ?? null,
                'disguise_ownership_policy' => $definition['disguise_ownership_policy'] ?? null,
                'scale_unit_people' => $definition['scale_unit_people'],
                'initial_scale' => $definition['initial_scale'],
                'scale_increment' => $definition['scale_increment'],
                'maximum_scale' => $definition['maximum_scale'],
                'workforce_per_scale_people' => $definition['workforce_per_scale_people'],
                'production_definition_key' => $definition['production_definition_key'],
                'buildable_terrain_keys' => $definition['buildable_terrain_keys'],
                'metadata' => $metadata,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $expected
     */
    private function createOrAssertCatalog(string $table, string $key, array $expected): void
    {
        $existing = DB::table($table)->where('key', $key)->first();
        if ($existing === null) {
            $payload = [
                ...$expected,
                'metadata' => json_encode($expected['metadata'], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ];
            if (array_key_exists('buildable_terrain_keys', $expected)) {
                $payload['buildable_terrain_keys'] = json_encode(
                    $expected['buildable_terrain_keys'],
                    JSON_THROW_ON_ERROR,
                );
            }
            DB::table($table)->insert($payload);

            return;
        }

        foreach ($expected as $field => $value) {
            $stored = $existing->{$field};
            if (in_array($field, ['buildable_terrain_keys', 'metadata'], true)) {
                $stored = json_decode((string) $stored, true, 512, JSON_THROW_ON_ERROR);
            }
            if ($stored !== $value) {
                throw new RuntimeException("Existing {$table} catalog row {$key} differs at {$field}; refusing overwrite.");
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException(
            'The roadmap-pr22-v1 schema and ruleset publication are forward-only; restore from an explicit backup instead.',
        );
    }

    private function assertNoWorldTurnOperation(): void
    {
        $worlds = DB::table('worlds')->orderBy('id')->get(['id', 'key', 'current_turn']);
        foreach ($worlds as $world) {
            $lock = DB::selectOne(
                'SELECT pg_try_advisory_xact_lock(hashtextextended(?, 0)) AS acquired',
                ["hakoniwa.turn.world.{$world->id}"],
            );
            if (! in_array($lock?->acquired, [true, 1, '1', 't'], true)) {
                throw new RuntimeException(
                    "Refusing PR22 migration while World {$world->id} ({$world->key}) is running a turn operation.",
                );
            }
            $run = DB::table('turn_runs')->where('world_id', $world->id)
                ->where('target_turn', (int) $world->current_turn + 1)
                ->where('is_dry_run', false)
                ->orderBy('id')->first(['id', 'target_turn', 'status']);
            if ($run !== null) {
                throw new RuntimeException(
                    "Refusing PR22 migration with non-dry-run TurnRun {$run->id}, target_turn={$run->target_turn}, status={$run->status}.",
                );
            }
        }
    }
};
