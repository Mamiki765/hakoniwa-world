<?php

use App\Application\RulesetPublisher;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TARGET_KEY = 'hakoniwa-2s-plus-v1';

    public function up(): void
    {
        $settings = config('hakoniwa.published_rulesets.'.self::TARGET_KEY);
        if (! is_array($settings)) {
            throw new RuntimeException('The immutable first production ruleset snapshot is missing.');
        }
        $this->assertNoWorldTurnOperation();

        Schema::table('nations', function (Blueprint $table): void {
            $table->unsignedBigInteger('registered_turn')->default(1)->after('nation_number');
        });
        DB::statement('ALTER TABLE nations ALTER COLUMN idle_counter SET DEFAULT 100');
        DB::statement('ALTER TABLE nations ADD CONSTRAINT nations_registered_turn_check CHECK (registered_turn >= 1)');

        Schema::create('moderation_records', function (Blueprint $table): void {
            $table->id();
            $table->string('operator_identifier', 191);
            $table->string('category', 64);
            $table->string('target_type', 16);
            $table->unsignedBigInteger('target_id');
            $table->text('summary');
            $table->jsonb('metadata')->default('{}');
            $table->timestampTz('occurred_at');
            $table->timestamps();
            $table->index(['target_type', 'target_id', 'id'], 'moderation_records_target');
            $table->index(['occurred_at', 'id'], 'moderation_records_occurred');
        });
        DB::statement("ALTER TABLE moderation_records ADD CONSTRAINT moderation_records_target_type_check CHECK (target_type IN ('nation', 'user'))");

        app(RulesetPublisher::class)->publish($settings);
    }

    public function down(): void
    {
        throw new RuntimeException(
            'The first production-release schema boundary is forward-only; restore from an explicit backup instead.',
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
                    "Refusing production baseline migration while World {$world->id} ({$world->key}) is running a turn operation.",
                );
            }
            $run = DB::table('turn_runs')->where('world_id', $world->id)
                ->where('target_turn', (int) $world->current_turn + 1)
                ->where('is_dry_run', false)
                ->orderBy('id')->first(['id', 'target_turn', 'status']);
            if ($run !== null) {
                throw new RuntimeException(
                    "Refusing production baseline migration with non-dry-run TurnRun {$run->id}, target_turn={$run->target_turn}, status={$run->status}.",
                );
            }
        }
    }
};
