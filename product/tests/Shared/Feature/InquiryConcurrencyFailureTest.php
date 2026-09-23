<?php

namespace Tests\Shared\Feature;

use App\Application\NationCreationService;
use App\Domain\Secretary\SecretarySkillCatalog;
use App\Models\Inquiry;
use App\Models\TurnRun;
use App\Models\UndergroundParty;
use App\Models\UndergroundProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTestWorlds;
use Tests\Concerns\UsesForwardOnlyDatabaseMigrations;
use Tests\TestCase;

final class InquiryConcurrencyFailureTest extends TestCase
{
    use CreatesTestWorlds;
    use DatabaseMigrations, UsesForwardOnlyDatabaseMigrations {
        UsesForwardOnlyDatabaseMigrations::runDatabaseMigrations insteadof DatabaseMigrations;
        UsesForwardOnlyDatabaseMigrations::refreshTestDatabase insteadof DatabaseMigrations;
    }

    public function test_inquiry_and_party_foreign_keys_complete_while_turn_waits_for_secretary(): void
    {
        $this->requirePostgres();
        $world = $this->lightweightWorld();
        $leader = User::factory()->create();
        $borrower = User::factory()->create();
        app(NationCreationService::class)->create($leader, $world, 'PT island', 'PT leader');
        app(NationCreationService::class)->create($borrower, $world->fresh(), 'Inquiry island', 'Inquiry owner');
        $secretary = $leader->secretary()->firstOrFail();
        $borrowed = $borrower->secretary()->firstOrFail();
        $profile = UndergroundProfile::query()->firstOrCreate(['secretary_id' => $secretary->id]);
        $party = UndergroundParty::query()->create([
            'leader_user_id' => $leader->id, 'leader_secretary_id' => $secretary->id,
            'content_type' => 'exploration', 'content_key' => 'shallow_caves',
            'content_identity' => 'concurrency-fixture', 'party_size' => 2, 'leader_combat_level' => 1, 'snapshot' => [],
        ]);
        $party->members()->create([
            'source_type' => 'self', 'secretary_id' => $secretary->id, 'source_owner_user_id' => $leader->id,
            'combatant_id' => 'secretary:'.$secretary->id, 'original_level' => 1, 'effective_level' => 1, 'snapshot' => [],
        ]);
        $fixture = [
            'world_id' => $world->id, 'secretary_id' => $secretary->id, 'profile_id' => $profile->id,
            'borrowed_secretary_id' => $borrowed->id, 'user_id' => $borrower->id, 'party_id' => $party->id,
            'submission_key' => (string) Str::uuid(),
        ];
        $workers = [];
        try {
            $workers['turn'] = $this->startWorker('turn', $fixture);
            $this->assertTrue($this->readWorkerEvent($workers['turn'])['ready']);
            $workers['party'] = $this->startWorker('party', $fixture);
            $this->assertTrue($this->readWorkerEvent($workers['party'])['ready']);
            $workers['inquiry'] = $this->startWorker('inquiry', $fixture);
            $this->assertTrue($this->readWorkerEvent($workers['inquiry'])['ready']);

            $this->continueWorker($workers['inquiry']);
            $this->waitForBlock($workers['inquiry']['pid'], $workers['turn']['pid']);
            $this->continueWorker($workers['turn']);
            // The actual member FK can now finish without waiting for the inquiry's User lock.
            $this->continueWorker($workers['party']);
            $results = array_map(fn (array $worker): array => $this->readWorkerEvent($worker), $workers);
            $this->assertSame(['turn' => 'ok', 'party' => 'ok', 'inquiry' => 'ok'], array_map(fn (array $result): string => $result['status'], $results), json_encode($results, JSON_THROW_ON_ERROR));
            $this->assertSame($world->current_turn + 1, $world->fresh()->current_turn);
            $this->assertSame(2, $party->members()->count());
            $this->assertSame(1, Inquiry::query()->where('submission_key', $fixture['submission_key'])->count());
        } finally {
            $this->stopWorkers($workers);
        }
    }

    public function test_turn_secretary_flush_and_party_snapshot_complete_without_lost_updates(): void
    {
        $this->requirePostgres();
        $world = $this->lightweightWorld();
        $borrowedOwner = User::factory()->create();
        $leader = User::factory()->create();
        $borrowedNation = app(NationCreationService::class)->create(
            $borrowedOwner,
            $world,
            'Borrowed island',
            'Borrowed owner',
        );
        $leaderNation = app(NationCreationService::class)->create(
            $leader,
            $world->fresh(),
            'Leader island',
            'Leader owner',
        );
        $borrowed = $borrowedOwner->secretary()->firstOrFail();
        $leaderSecretary = $leader->secretary()->firstOrFail();
        $this->assertLessThan($leaderSecretary->id, $borrowed->id);
        $leaderProfile = UndergroundProfile::query()->firstOrCreate(['secretary_id' => $leaderSecretary->id]);
        $ruleset = $world->rulesetVersion()->firstOrFail();
        $run = TurnRun::query()->create([
            'world_id' => $world->id,
            'target_turn' => $world->current_turn + 1,
            'ruleset_version_id' => $ruleset->id,
            'random_seed' => hash('sha256', 'turn-party-snapshot-concurrency'),
            'source' => 'manual',
            'is_dry_run' => true,
            'status' => TurnRun::STATUS_DRY_RUN,
            'attempt_count' => 1,
            'pipeline' => [],
            'phase_results' => [],
            'failure_context' => [],
        ]);
        $turnMonsterAward = 7;
        $concurrentMonsterAward = 11;
        $fixture = [
            'world_id' => $world->id,
            'turn_run_id' => $run->id,
            'borrowed_nation_id' => $borrowedNation->id,
            'leader_nation_id' => $leaderNation->id,
            'borrowed_secretary_id' => $borrowed->id,
            'borrowed_owner_user_id' => $borrowedOwner->id,
            'leader_secretary_id' => $leaderSecretary->id,
            'leader_profile_id' => $leaderProfile->id,
            'skill_key' => SecretarySkillCatalog::AGRICULTURAL_POLICY,
            'turn_monster_award' => $turnMonsterAward,
            'concurrent_monster_award' => $concurrentMonsterAward,
        ];
        $borrowedMonsterBefore = (int) $borrowed->surfaceState->monster_experience;
        $leaderMonsterBefore = (int) $leaderSecretary->surfaceState->monster_experience;
        $workers = [];
        try {
            $workers['party'] = $this->startWorker('party_snapshot', $fixture);
            $this->assertTrue($this->readWorkerEvent($workers['party'])['ready']);
            $workers['turn'] = $this->startWorker('turn_flush', $fixture);
            $this->assertTrue($this->readWorkerEvent($workers['turn'])['ready']);
            // Early item grant and flush completed while the underground
            // profile is still held; only another surface writer must wait.
            $workers['writer'] = $this->startWorker('secretary_update', $fixture);
            $this->waitForBlock($workers['writer']['pid'], $workers['turn']['pid']);
            $this->continueWorker($workers['party']);
            $partyResult = $this->readWorkerEvent($workers['party']);
            $this->continueWorker($workers['turn']);
            $results = ['party' => $partyResult, 'turn' => $this->readWorkerEvent($workers['turn']),
                'writer' => $this->readWorkerEvent($workers['writer'])];
            $this->assertSame(
                ['party' => 'ok', 'turn' => 'ok', 'writer' => 'ok'],
                array_map(fn (array $result): string => $result['status'], $results),
                json_encode($results, JSON_THROW_ON_ERROR),
            );
            $this->assertSame(2, $results['turn']['metrics']['skills_changed']);
            $this->assertSame(2, $results['turn']['metrics']['monster_experience_secretaries_changed']);
            $party = UndergroundParty::query()->findOrFail($results['party']['party_id']);
            $this->assertSame(
                [$leaderSecretary->id, $borrowed->id],
                $party->members()->orderBy('id')->pluck('secretary_id')->map(fn (mixed $id): int => (int) $id)->all(),
            );
            $this->assertSame(
                $borrowedMonsterBefore + $turnMonsterAward + $concurrentMonsterAward,
                (int) $borrowed->fresh()->surfaceState->monster_experience,
            );
            $this->assertSame(
                $leaderMonsterBefore + $turnMonsterAward,
                (int) $leaderSecretary->fresh()->surfaceState->monster_experience,
            );
        } finally {
            $this->stopWorkers($workers);
        }
    }

    public function test_same_submission_serializes_and_stores_only_one_attachment(): void
    {
        $this->requirePostgres();
        Storage::fake('inquiry_attachments');
        $this->lightweightWorld();
        $user = User::factory()->create();
        $fixture = ['user_id' => $user->id, 'submission_key' => (string) Str::uuid(),
            'attachment_root' => Storage::disk('inquiry_attachments')->path('')];
        $workers = [];
        try {
            $workers['first'] = $this->startWorker('inquiry', $fixture);
            $this->assertTrue($this->readWorkerEvent($workers['first'])['ready']);
            $workers['second'] = $this->startWorker('inquiry', $fixture);
            $this->waitForBlock($workers['second']['pid'], $workers['first']['pid']);
            $this->continueWorker($workers['first']);
            $first = $this->readWorkerEvent($workers['first']);
            $this->assertTrue($this->readWorkerEvent($workers['second'])['ready']);
            $this->continueWorker($workers['second']);
            $second = $this->readWorkerEvent($workers['second']);
            $this->assertSame('ok', $first['status'], json_encode($first, JSON_THROW_ON_ERROR));
            $this->assertSame('ok', $second['status'], json_encode($second, JSON_THROW_ON_ERROR));
            $this->assertTrue($first['created']);
            $this->assertFalse($second['created']);
            $this->assertSame($first['id'], $second['id']);
            $this->assertDatabaseCount('inquiries', 1);
            $this->assertCount(1, Storage::disk('inquiry_attachments')->allFiles());
        } finally {
            $this->stopWorkers($workers);
        }
    }

    public function test_database_concurrency_failure_after_file_write_is_not_automatically_retried(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL-specific transaction retry regression test.');
        }

        Storage::fake('inquiry_attachments');
        $this->lightweightWorld();
        $user = User::factory()->create();
        DB::statement('CREATE SEQUENCE inquiry_serialization_failure_probe');
        DB::statement(<<<'SQL'
CREATE FUNCTION fail_first_inquiry_insert() RETURNS trigger AS $$
BEGIN
    IF nextval('inquiry_serialization_failure_probe') = 1 THEN
        RAISE EXCEPTION 'forced serialization failure' USING ERRCODE = '40001';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql
SQL);
        DB::statement('CREATE TRIGGER inquiries_first_serialization_failure BEFORE INSERT ON inquiries FOR EACH ROW EXECUTE FUNCTION fail_first_inquiry_insert()');

        try {
            $this->actingAs($user)->post('/api/v1/inquiries', [
                'submission_key' => (string) Str::uuid(),
                'category' => 'bug',
                'subject' => 'DB concurrency failure',
                'body' => 'must fail once and clean up without retrying the file write',
                'attachment' => UploadedFile::fake()->createWithContent('retry.png', $this->png()),
            ], ['Accept' => 'application/json'])->assertServerError();
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS inquiries_first_serialization_failure ON inquiries');
            DB::statement('DROP FUNCTION IF EXISTS fail_first_inquiry_insert()');
            DB::statement('DROP SEQUENCE IF EXISTS inquiry_serialization_failure_probe');
        }

        $this->assertDatabaseCount('inquiries', 0);
        Storage::disk('inquiry_attachments')->assertDirectoryEmpty('/');
    }

    private function png(): string
    {
        return (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        );
    }

    private function requirePostgres(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL-specific foreign key concurrency test.');
        }
    }

    /** @param array<string, mixed> $fixture
     * @return array{process: resource, pipes: array<int, resource>, pid: int}
     */
    private function startWorker(string $operation, array $fixture): array
    {
        $process = proc_open([PHP_BINARY, base_path('tests/Support/inquiry_concurrency_worker.php'), $operation,
            json_encode($fixture, JSON_THROW_ON_ERROR)], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
        $this->assertIsResource($process);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $worker = ['process' => $process, 'pipes' => $pipes];
        $worker['pid'] = (int) $this->readWorkerEvent($worker)['pid'];

        return $worker;
    }

    /** @param array<string, mixed> $worker
     * @return array<string, mixed>
     */
    private function readWorkerEvent(array $worker): array
    {
        $deadline = microtime(true) + 20;
        do {
            $line = fgets($worker['pipes'][1]);
            if (is_string($line)) {
                return json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            }
            if (feof($worker['pipes'][1]) || microtime(true) >= $deadline) {
                $this->fail('Inquiry worker did not emit its event: '.stream_get_contents($worker['pipes'][2]));
            }
            usleep(10_000);
        } while (true);
    }

    /** @param array<string, mixed> $worker */
    private function continueWorker(array $worker): void
    {
        fwrite($worker['pipes'][0], "continue\n");
        fflush($worker['pipes'][0]);
    }

    private function waitForBlock(int $waiter, int $holder): void
    {
        $deadline = microtime(true) + 10;
        do {
            $row = DB::selectOne('SELECT ? = ANY(pg_blocking_pids(?)) AS blocked', [$holder, $waiter]);
            if ($row->blocked) {
                return;
            }
            if (microtime(true) >= $deadline) {
                $this->fail("Expected backend {$waiter} to wait for {$holder}.");
            }
            usleep(10_000);
        } while (true);
    }

    /** @param array<string, array<string, mixed>> $workers */
    private function stopWorkers(array $workers): void
    {
        foreach ($workers as $worker) {
            if (proc_get_status($worker['process'])['running']) {
                proc_terminate($worker['process']);
            }
            foreach ($worker['pipes'] as $pipe) {
                fclose($pipe);
            }
            proc_close($worker['process']);
        }
    }
}
