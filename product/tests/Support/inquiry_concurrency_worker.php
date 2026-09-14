<?php

use App\Application\InquirySubmissionService;
use App\Models\UndergroundPartyMember;
use App\Models\User;
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
            DB::table('secretaries')->where('id', $fixture['secretary_id'])->orderBy('id')->lockForUpdate()->get();
            DB::table('worlds')->where('id', $fixture['world_id'])->increment('current_turn');
        });
    } elseif ($operation === 'party') {
        DB::transaction(function () use ($fixture, $pause): void {
            DB::table('secretaries')->where('id', $fixture['secretary_id'])->lock('for no key update')->first();
            DB::table('underground_profiles')->where('id', $fixture['profile_id'])->lockForUpdate()->first();
            $pause();
            UndergroundPartyMember::query()->create([
                'underground_party_id' => $fixture['party_id'], 'source_type' => 'borrowed_secretary',
                'secretary_id' => $fixture['borrowed_secretary_id'], 'source_owner_user_id' => $fixture['user_id'],
                'combatant_id' => 'borrowed:'.$fixture['borrowed_secretary_id'],
                'original_level' => 1, 'effective_level' => 1, 'snapshot' => [],
            ]);
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
