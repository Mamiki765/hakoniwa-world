<?php

use App\Application\InquirySubmissionService;
use App\Application\SecretaryItemGrantService;
use App\Application\SecretaryTurnService;
use App\Application\Underground\UndergroundLendingRewardService;
use App\Domain\Secretary\SecretaryItemCatalog;
use App\Domain\Turn\TurnContext;
use App\Domain\Turn\TurnRandomStreamFactory;
use App\Domain\Turn\TurnState;
use App\Models\Secretary;
use App\Models\SecretarySurfaceState;
use App\Models\TurnRun;
use App\Models\UndergroundPartyMember;
use App\Models\UndergroundProfile;
use App\Models\User;
use App\Models\World;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$script, $operation, $encoded] = $argv;
$fixture = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
$emit = static function (array $event): void {
    fwrite(STDOUT, json_encode($event, JSON_THROW_ON_ERROR)."\n");
    fflush(STDOUT);
};
$pause = static function () use ($emit): void {
    $emit(['ready' => true]);
    if (trim((string) fgets(STDIN)) !== 'continue') {
        throw new RuntimeException('Missing concurrency-test continuation.');
    }
};

try {
    DB::statement("SET statement_timeout = '15s'");
    $emit(['pid' => (int) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid]);
    if ($operation === 'turn') {
        DB::transaction(function () use ($fixture, $pause): void {
            DB::table('worlds')->where('id', $fixture['world_id'])->lockForUpdate()->first();
            DB::table('nations')->where('world_id', $fixture['world_id'])->orderBy('id')->lockForUpdate()->get();
            $pause();
            DB::table('secretary_surface_states')->where('secretary_id', $fixture['secretary_id'])->lockForUpdate()->get();
            DB::table('worlds')->where('id', $fixture['world_id'])->increment('current_turn');
        });
    } elseif ($operation === 'party') {
        DB::transaction(function () use ($fixture, $pause): void {
            DB::table('underground_profiles')->where('id', $fixture['profile_id'])->lockForUpdate()->first();
            $pause();
            UndergroundPartyMember::query()->create([
                'underground_party_id' => $fixture['party_id'], 'source_type' => 'borrowed_secretary',
                'secretary_id' => $fixture['borrowed_secretary_id'], 'source_owner_user_id' => $fixture['user_id'],
                'combatant_id' => 'borrowed:'.$fixture['borrowed_secretary_id'],
                'original_level' => 1, 'effective_level' => 1, 'snapshot' => [],
            ]);
        });
    } elseif ($operation === 'party_snapshot') {
        $partyId = DB::transaction(function () use ($fixture, $pause): int {
            $leaderSecretary = Secretary::query()
                ->whereKey($fixture['leader_secretary_id'])
                ->firstOrFail();
            UndergroundProfile::query()
                ->whereKey($fixture['leader_profile_id'])
                ->lockForUpdate()
                ->firstOrFail();
            $leader = User::query()->findOrFail($leaderSecretary->user_id);
            $pause();
            $party = app(UndergroundLendingRewardService::class)->createSnapshot(
                $leader,
                $leaderSecretary,
                'exploration',
                'shallow_caves',
                'turn-party-concurrency-fixture',
                1,
                [],
                [[
                    'source_type' => 'self',
                    'secretary_id' => $fixture['leader_secretary_id'],
                    'source_owner_user_id' => $leader->id,
                    'combatant_id' => 'secretary:'.$fixture['leader_secretary_id'],
                    'original_level' => 1,
                    'effective_level' => 1,
                    'snapshot' => [],
                ], [
                    'source_type' => 'borrowed_secretary',
                    'secretary_id' => $fixture['borrowed_secretary_id'],
                    'source_owner_user_id' => $fixture['borrowed_owner_user_id'],
                    'combatant_id' => 'borrowed:'.$fixture['borrowed_secretary_id'],
                    'original_level' => 1,
                    'effective_level' => 1,
                    'snapshot' => [],
                ]],
            );

            return (int) $party->id;
        });
        $emit(['status' => 'ok', 'party_id' => $partyId]);
        exit(0);
    } elseif ($operation === 'turn_flush') {
        $world = World::query()->findOrFail($fixture['world_id']);
        $run = TurnRun::query()->findOrFail($fixture['turn_run_id']);
        $ruleset = $run->rulesetVersion()->firstOrFail();
        $state = new TurnState;
        $state->setStableNationIds([$fixture['borrowed_nation_id'], $fixture['leader_nation_id']]);
        $state->setDevelopmentNationIds([$fixture['borrowed_nation_id'], $fixture['leader_nation_id']]);
        $context = new TurnContext(
            $world,
            $run,
            $ruleset,
            $run->target_turn,
            $run->random_seed,
            new TurnRandomStreamFactory($run->random_seed),
            $state,
        );
        $service = app(SecretaryTurnService::class);
        $service->loadAttemptSnapshots($context, [$fixture['borrowed_nation_id'], $fixture['leader_nation_id']]);
        foreach ([$fixture['borrowed_nation_id'], $fixture['leader_nation_id']] as $nationId) {
            $state->awardSecretaryExperience($nationId, $fixture['skill_key']);
            $state->awardSecretaryMonsterExperience($nationId, $fixture['turn_monster_award']);
        }
        $metrics = DB::transaction(function () use ($fixture, $service, $context, $pause): array {
            app(SecretaryItemGrantService::class)->grant(
                Secretary::query()->findOrFail($fixture['borrowed_secretary_id']),
                SecretaryItemCatalog::ELF_BOW, 1, null, 'turn-isolation:grant',
            );
            $metrics = $service->flushExperience($context);
            // Keep the early grant and flush locks until the FK snapshot and
            // competing surface writer have both been observed by the parent.
            $pause();

            return $metrics;
        });
        $emit(['status' => 'ok', 'metrics' => $metrics]);
        exit(0);
    } elseif ($operation === 'secretary_update') {
        DB::transaction(function () use ($fixture): void {
            $secretary = SecretarySurfaceState::query()
                ->whereKey($fixture['borrowed_secretary_id'])
                ->lockForUpdate()
                ->firstOrFail();
            $secretary->monster_experience += $fixture['concurrent_monster_award'];
            $secretary->save();
        });
    } elseif ($operation === 'inquiry') {
        // Pause after the real service acquires its User lock, before its INSERT.
        DB::listen(static function (QueryExecuted $query) use ($pause): void {
            if (str_contains($query->sql, 'from "users"') && str_contains($query->sql, 'for ')) {
                $pause();
            }
        });
        $attachment = null;
        if (isset($fixture['attachment_root'])) {
            config(['filesystems.disks.inquiry_attachments.root' => $fixture['attachment_root']]);
            Storage::forgetDisk('inquiry_attachments');
            $attachment = UploadedFile::fake()->createWithContent('concurrent.png', base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true,
            ));
        }
        $result = app(InquirySubmissionService::class)->submit(
            User::query()->findOrFail($fixture['user_id']), $fixture['submission_key'],
            'bug', 'Concurrent inquiry', 'Isolated PostgreSQL regression fixture.', $attachment,
        );
        $emit(['status' => 'ok', 'id' => $result['inquiry']->id, 'created' => $result['created']]);
        exit(0);
    } else {
        throw new RuntimeException('Unknown inquiry concurrency operation.');
    }
    $emit(['status' => 'ok']);
} catch (Throwable $exception) {
    $emit(['status' => 'error', 'code' => (string) $exception->getCode(), 'message' => $exception->getMessage()]);
    exit(1);
}
