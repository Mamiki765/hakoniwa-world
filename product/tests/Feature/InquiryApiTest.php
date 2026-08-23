<?php

namespace Tests\Feature;

use App\Application\NationCreationService;
use App\Domain\Inquiry\InquiryCategoryCatalog;
use App\Models\AuthIdentity;
use App\Models\Inquiry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

final class InquiryApiTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    public function test_category_catalog_contains_exactly_the_five_owner_approved_categories(): void
    {
        $this->assertSame([
            'bug' => 'バグ報告',
            'request' => '要望',
            'idea' => 'アイデア',
            'secretary_fan_art' => '秘書のファンアート',
            'other' => 'その他',
        ], InquiryCategoryCatalog::LABELS);

        $this->lightweightWorld();
        $this->actingAs(User::factory()->create())->postJson('/api/v1/inquiries', [
            'submission_key' => (string) Str::uuid(),
            'category' => 'not_approved',
            'subject' => 'unknown category',
            'body' => 'must be rejected',
        ])->assertUnprocessable()->assertJsonValidationErrors('category');
    }

    public function test_authenticated_user_can_submit_without_attachment_and_server_metadata_cannot_be_spoofed(): void
    {
        $world = $this->lightweightWorld();
        $world->update(['current_turn' => 42]);
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world->fresh(), '問い合わせ島', '問い合わせ島主');

        $response = $this->actingAs($user)->post('/api/v1/inquiries', [
            'submission_key' => (string) Str::uuid(),
            'category' => 'bug',
            'subject' => '表示がおかしい',
            'body' => "一行目\n二行目",
            'user_id' => 999999,
            'nation_id' => 999999,
            'submitted_turn' => 999999,
            'application_version' => 'spoofed',
        ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('data.management_id', 'INQ-000001')
            ->assertJsonMissingPath('data.attachment_url');
        $this->assertDatabaseHas('inquiries', [
            'user_id' => $user->id,
            'nation_id' => $nation->id,
            'world_id' => $world->id,
            'submitted_turn' => 42,
            'application_version' => '2.4.0-beta',
            'category' => 'bug',
            'attachment_token' => null,
            'attachment_path' => null,
        ]);
    }

    public function test_valid_image_uses_a_random_256_bit_token_and_never_exposes_original_filename(): void
    {
        Storage::fake('inquiry_attachments');
        $this->lightweightWorld();
        $user = User::factory()->create();
        $originalName = 'personal-original-name.png';

        $response = $this->actingAs($user)->post('/api/v1/inquiries', [
            'submission_key' => (string) Str::uuid(),
            'category' => 'secretary_fan_art',
            'subject' => 'ファンアートです',
            'body' => '添付します。',
            'attachment' => UploadedFile::fake()->createWithContent($originalName, $this->png()),
        ], ['Accept' => 'application/json'])->assertCreated();

        $inquiry = Inquiry::query()->firstOrFail();
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $inquiry->attachment_token);
        $this->assertSame($inquiry->attachment_token.'.png', $inquiry->attachment_path);
        $this->assertStringNotContainsString('INQ-', $inquiry->attachment_path);
        $this->assertStringNotContainsString($originalName, $inquiry->attachment_path);
        $this->assertStringNotContainsString($originalName, $response->getContent());
        Storage::disk('inquiry_attachments')->assertExists($inquiry->attachment_path);
    }

    public function test_invalid_mime_svg_and_files_over_ten_megabytes_are_rejected(): void
    {
        Storage::fake('inquiry_attachments');
        $this->lightweightWorld();
        $user = User::factory()->create();
        $base = [
            'submission_key' => (string) Str::uuid(),
            'category' => 'other',
            'subject' => '添付検証',
            'body' => '添付検証です。',
        ];

        $this->actingAs($user)->post('/api/v1/inquiries', [
            ...$base,
            'attachment' => UploadedFile::fake()->createWithContent('vector.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('attachment');

        $this->actingAs($user)->post('/api/v1/inquiries', [
            ...$base,
            'submission_key' => (string) Str::uuid(),
            'attachment' => UploadedFile::fake()->createWithContent('text.png', 'not an image'),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('attachment');

        $this->actingAs($user)->post('/api/v1/inquiries', [
            ...$base,
            'submission_key' => (string) Str::uuid(),
            'attachment' => UploadedFile::fake()->create('large.png', 10_241, 'image/png'),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('attachment');

        $this->assertDatabaseCount('inquiries', 0);
        Storage::disk('inquiry_attachments')->assertDirectoryEmpty('/');
    }

    public function test_oversized_or_invalid_dimensions_do_not_write_or_consume_the_submission_key(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        Storage::fake('inquiry_attachments');
        $this->lightweightWorld();
        $user = User::factory()->create();
        $submissionKey = (string) Str::uuid();
        $base = [
            'submission_key' => $submissionKey,
            'category' => 'bug',
            'subject' => 'dimension check',
            'body' => 'dimension check body',
        ];

        foreach ([[12_001, 1], [1, 12_001], [10_000, 5_000]] as [$width, $height]) {
            $this->actingAs($user)->post('/api/v1/inquiries', [
                ...$base,
                'attachment' => UploadedFile::fake()->createWithContent(
                    'oversized.png',
                    $this->pngWithDimensions($width, $height),
                ),
            ], ['Accept' => 'application/json'])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('attachment');
        }

        $this->assertDatabaseCount('inquiries', 0);
        Storage::disk('inquiry_attachments')->assertDirectoryEmpty('/');

        $this->actingAs($user)->post('/api/v1/inquiries', [
            ...$base,
            'attachment' => UploadedFile::fake()->createWithContent('valid.png', $this->png()),
        ], ['Accept' => 'application/json'])->assertCreated();
        $this->assertDatabaseCount('inquiries', 1);
    }

    public function test_admin_latest_five_index_and_detail_are_fail_closed_for_everyone_else(): void
    {
        config(['hakoniwa.admin.discord_user_id' => 'inquiry-admin']);
        $world = $this->lightweightWorld();
        $submitter = User::factory()->create(['display_name' => 'Reporter']);
        $latestInquiryId = null;
        foreach (range(1, 12) as $number) {
            $inquiry = Inquiry::query()->create([
                'submission_key' => (string) Str::uuid(),
                'user_id' => $submitter->id,
                'world_id' => $world->id,
                'nation_id' => null,
                'submitted_turn' => $world->current_turn,
                'application_version' => '2.2.0',
                'category' => 'idea',
                'subject' => "Idea {$number}",
                'body' => "Body {$number}",
            ]);
            $at = Carbon::parse('2026-08-17 12:00:00+09:00')->addSecond($number);
            $inquiry->forceFill(['created_at' => $at, 'updated_at' => $at])->saveQuietly();
            if ($number === 12) {
                $latestInquiryId = $inquiry->id;
            }
        }
        $admin = $this->admin('inquiry-admin');
        $nonAdmin = User::factory()->create();

        $this->getJson('/api/v1/admin/inquiries/latest')->assertForbidden();
        $this->actingAs($nonAdmin)->getJson('/api/v1/admin/inquiries')->assertForbidden();
        $this->actingAs($nonAdmin)->getJson('/api/v1/admin/inquiries/1')->assertForbidden();

        $this->actingAs($admin)->getJson('/api/v1/admin/inquiries/latest')
            ->assertOk()->assertJsonCount(5, 'data')
            ->assertJsonPath('data.0.subject', 'Idea 12')
            ->assertJsonPath('data.4.subject', 'Idea 8');
        $this->actingAs($admin)->getJson('/api/v1/admin/inquiries')
            ->assertOk()->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.total', 12)
            ->assertJsonPath('data.0.subject', 'Idea 12');
        $this->actingAs($admin)->getJson('/api/v1/admin/inquiries?page=2')
            ->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('data.0.subject', 'Idea 2')
            ->assertJsonPath('data.1.subject', 'Idea 1');
        $this->assertIsInt($latestInquiryId);
        $this->actingAs($admin)->getJson("/api/v1/admin/inquiries/{$latestInquiryId}")
            ->assertOk()
            ->assertJsonPath('data.management_id', sprintf('INQ-%06d', $latestInquiryId))
            ->assertJsonPath('data.user.display_name', 'Reporter')
            ->assertJsonPath('data.body', 'Body 12');
    }

    public function test_database_failure_after_file_write_removes_the_orphan_file(): void
    {
        Storage::fake('inquiry_attachments');
        $this->lightweightWorld();
        $user = User::factory()->create();
        DB::statement(<<<'SQL'
CREATE FUNCTION fail_inquiry_insert() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'forced inquiry insert failure';
END;
$$ LANGUAGE plpgsql
SQL);
        DB::statement('CREATE TRIGGER inquiries_forced_failure BEFORE INSERT ON inquiries FOR EACH ROW EXECUTE FUNCTION fail_inquiry_insert()');

        try {
            $this->actingAs($user)->post('/api/v1/inquiries', [
                'submission_key' => (string) Str::uuid(),
                'category' => 'bug',
                'subject' => 'DB failure',
                'body' => 'cleanup check',
                'attachment' => UploadedFile::fake()->createWithContent('cleanup.png', $this->png()),
            ], ['Accept' => 'application/json'])->assertServerError();
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS inquiries_forced_failure ON inquiries');
            DB::statement('DROP FUNCTION IF EXISTS fail_inquiry_insert()');
        }

        $this->assertDatabaseCount('inquiries', 0);
        Storage::disk('inquiry_attachments')->assertDirectoryEmpty('/');
    }

    public function test_submission_is_authenticated_idempotent_and_rate_limited(): void
    {
        $this->lightweightWorld();
        $payload = [
            'submission_key' => (string) Str::uuid(),
            'category' => 'request',
            'subject' => '要望',
            'body' => '要望本文',
        ];
        $this->postJson('/api/v1/inquiries', $payload)->assertUnauthorized();

        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/v1/inquiries', $payload)->assertCreated();
        $this->actingAs($user)->postJson('/api/v1/inquiries', $payload)->assertOk();
        $this->assertDatabaseCount('inquiries', 1);

        $this->actingAs($user)->postJson('/api/v1/inquiries', [
            ...$payload,
            'submission_key' => (string) Str::uuid(),
            'subject' => '要望 1',
        ])->assertCreated();
        $this->actingAs($user)->postJson('/api/v1/inquiries', [
            ...$payload,
            'submission_key' => (string) Str::uuid(),
            'subject' => 'rate limited',
        ])->assertTooManyRequests();
    }

    private function admin(string $providerUserId): User
    {
        $user = User::factory()->create(['display_name' => 'Admin']);
        AuthIdentity::query()->create([
            'user_id' => $user->id,
            'provider' => 'discord',
            'provider_user_id' => $providerUserId,
            'display_name' => 'Admin',
        ]);

        return $user;
    }

    private function png(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true) ?: '';
    }

    private function pngWithDimensions(int $width, int $height): string
    {
        $png = $this->png();
        $png = substr_replace($png, pack('N', $width), 16, 4);
        $png = substr_replace($png, pack('N', $height), 20, 4);

        return substr_replace($png, hash('crc32b', substr($png, 12, 17), true), 29, 4);
    }
}
