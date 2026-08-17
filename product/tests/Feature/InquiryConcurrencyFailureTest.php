<?php

namespace Tests\Feature;

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
}
