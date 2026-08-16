<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, int> */
    private const INITIAL_LEVELS = [
        'agricultural_policy' => 0,
        'specialty_development' => 0,
        'gold_vein_survey' => 0,
        'final_defense_line' => 1,
    ];

    public function up(): void
    {
        $hasSecretaries = Schema::hasTable('secretaries');
        $hasSkills = Schema::hasTable('secretary_skills');
        if ($hasSecretaries !== $hasSkills) {
            throw new RuntimeException('Secretary schema is only partially present; refusing an implicit repair.');
        }
        if (! $hasSecretaries) {
            $this->createTables();
        }

        $this->backfillNationHistoryUsers();
    }

    private function createTables(): void
    {
        Schema::create('secretaries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->string('name', 30)->nullable();
            $table->timestampTz('named_at')->nullable();
            $table->timestampsTz();
        });
        DB::statement(<<<'SQL'
ALTER TABLE secretaries
ADD CONSTRAINT secretaries_name_state_check CHECK (
    (name IS NULL AND named_at IS NULL)
    OR (name IS NOT NULL AND named_at IS NOT NULL)
)
SQL);

        Schema::create('secretary_skills', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('secretary_id')->constrained()->cascadeOnDelete();
            $table->string('skill_key');
            $table->unsignedInteger('level');
            $table->unsignedBigInteger('experience');
            $table->timestampsTz();
            $table->unique(['secretary_id', 'skill_key']);
        });
        DB::statement(<<<'SQL'
ALTER TABLE secretary_skills
ADD CONSTRAINT secretary_skills_key_check CHECK (
    skill_key IN (
        'agricultural_policy',
        'specialty_development',
        'gold_vein_survey',
        'final_defense_line'
    )
),
ADD CONSTRAINT secretary_skills_level_check CHECK (level >= 0),
ADD CONSTRAINT secretary_skills_experience_check CHECK (experience >= 0)
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException(
            'The Secretary production backfill is forward-only; restore through an explicit reviewed conversion.',
        );
    }

    private function backfillNationHistoryUsers(): void
    {
        $membershipUserIds = DB::table('nation_memberships')
            ->where('role', 'owner')
            ->pluck('user_id');
        $completedRequestUserIds = DB::table('nation_creation_requests')
            ->where('status', 'completed')
            ->whereNotNull('nation_id')
            ->pluck('user_id');
        $auditUserIds = DB::table('audit_events')
            ->whereNotNull('actor_user_id')
            ->whereIn('event_type', ['nation.created', 'nation.abandoned'])
            ->pluck('actor_user_id');
        $userIds = $membershipUserIds
            ->merge($completedRequestUserIds)
            ->merge($auditUserIds)
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->sort()
            ->values();
        if ($userIds->isEmpty()) {
            if (DB::table('secretaries')->exists()) {
                throw new RuntimeException(
                    'Secretary backfill user_id set does not exactly match the empty Nation-history User set.',
                );
            }

            return;
        }
        $existingUserCount = DB::table('users')->whereIn('id', $userIds)->count();
        if ($existingUserCount !== $userIds->count()) {
            throw new RuntimeException('Secretary backfill references a missing historical Nation owner User.');
        }

        $now = now();
        DB::table('secretaries')->insertOrIgnore($userIds->map(static fn (int $userId): array => [
            'user_id' => $userId,
            'name' => null,
            'named_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all());
        $secretaries = DB::table('secretaries')->orderBy('user_id')->get(['id', 'user_id']);
        $actualUserIds = $secretaries
            ->map(static fn (object $secretary): int => (int) $secretary->user_id)
            ->values()
            ->all();
        if ($actualUserIds !== $userIds->all()) {
            throw new RuntimeException(
                'Secretary backfill user_id set does not exactly match the Nation-history User set.',
            );
        }

        $skillRows = [];
        foreach ($secretaries as $secretary) {
            foreach (self::INITIAL_LEVELS as $skillKey => $level) {
                $skillRows[] = [
                    'secretary_id' => (int) $secretary->id,
                    'skill_key' => $skillKey,
                    'level' => $level,
                    'experience' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        DB::table('secretary_skills')->insertOrIgnore($skillRows);
        $skillCount = DB::table('secretary_skills')
            ->whereIn('secretary_id', $secretaries->pluck('id'))
            ->whereIn('skill_key', array_keys(self::INITIAL_LEVELS))
            ->count();
        if ($skillCount !== $secretaries->count() * count(self::INITIAL_LEVELS)) {
            throw new RuntimeException('Secretary backfill did not create the exact four-skill state for every target User.');
        }
    }
};
