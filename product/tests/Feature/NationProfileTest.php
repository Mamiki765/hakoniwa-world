<?php

namespace Tests\Feature;

use App\Application\AuthIdentityService;
use App\Application\ExternalIdentityData;
use App\Application\NationCreationService;
use App\Models\Nation;
use App\Models\RulesetVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTestWorlds;
use Tests\Concerns\UsesHistoricalRulesetDatabaseFixtures;
use Tests\TestCase;

class NationProfileTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;
    use UsesHistoricalRulesetDatabaseFixtures;

    public function test_registration_validates_and_saves_explicit_profile_text_without_oauth_fallback(): void
    {
        $world = $this->lightweightWorld();
        $user = app(AuthIdentityService::class)->authenticate(
            'discord',
            new ExternalIdentityData('provider-user-secret', 'OAuth表示名'),
        );
        $endpoint = '/api/v1/nations';

        $this->actingAs($user)->postJson($endpoint, [
            'request_key' => (string) Str::uuid(),
            'world_id' => $world->id, 'name' => '不足島',
        ])->assertUnprocessable()->assertJsonValidationErrors('owner_name');
        $this->assertSame(0, Nation::query()->count());

        foreach ([
            ['owner_name' => str_repeat('主', 31), 'comment' => '', 'error' => 'owner_name'],
            ['owner_name' => "島主\n名", 'comment' => '', 'error' => 'owner_name'],
            ['owner_name' => "島主\u{0001}", 'comment' => '', 'error' => 'owner_name'],
            ['owner_name' => "\u{200B}", 'comment' => '', 'error' => 'owner_name'],
            ['owner_name' => '島主', 'comment' => str_repeat('言', 101), 'error' => 'comment'],
            ['owner_name' => '島主', 'comment' => "改行\n不可", 'error' => 'comment'],
            ['owner_name' => '島主', 'comment' => "制御\u{0001}不可", 'error' => 'comment'],
            ['owner_name' => '島主', 'comment' => "表示\u{202E}反転", 'error' => 'comment'],
        ] as $index => $case) {
            $this->postJson($endpoint, [
                'request_key' => (string) Str::uuid(),
                'world_id' => $world->id,
                'name' => "検証島{$index}",
                'owner_name' => $case['owner_name'],
                'comment' => $case['comment'],
            ])->assertUnprocessable()->assertJsonValidationErrors($case['error']);
        }

        $first = $this->postJson($endpoint, [
            'request_key' => (string) Str::uuid(),
            'world_id' => $world->id,
            'name' => '一文字島',
            'owner_name' => '　主　',
            'comment' => '  <b>平和</b>  ',
        ])->assertCreated()
            ->assertJsonPath('data.owner_name', '主')
            ->assertJsonPath('data.comment', '<b>平和</b>')
            ->json('data');
        $this->assertSame('主', Nation::query()->findOrFail($first['id'])->owner_name);
        $this->assertSame('<b>平和</b>', Nation::query()->findOrFail($first['id'])->profile_comment);
        $this->assertNotSame('OAuth表示名', Nation::query()->findOrFail($first['id'])->owner_name);

        $secondUser = User::factory()->create();
        $this->actingAs($secondUser)->postJson($endpoint, [
            'request_key' => (string) Str::uuid(),
            'world_id' => $world->id,
            'name' => '上限島',
            'owner_name' => str_repeat('主', 30),
            'comment' => str_repeat('言', 100),
        ])->assertCreated()
            ->assertJsonPath('data.owner_name', str_repeat('主', 30))
            ->assertJsonPath('data.comment', str_repeat('言', 100));

        $thirdUser = User::factory()->create();
        $this->actingAs($thirdUser)->postJson($endpoint, [
            'request_key' => (string) Str::uuid(),
            'world_id' => $world->id,
            'name' => '無言島',
            'owner_name' => '無言島主',
            'comment' => '',
        ])->assertCreated()->assertJsonPath('data.comment', '');
    }

    public function test_owner_only_profile_update_is_audited_and_no_op_is_not_versioned(): void
    {
        $world = $this->lightweightWorld();
        $owner = app(AuthIdentityService::class)->authenticate(
            'discord',
            new ExternalIdentityData('profile-provider-secret', '秘密のOAuth名'),
        );
        $nation = app(NationCreationService::class)->create(
            $owner, $world, 'プロフィール島', '旧島主', '旧コメント',
        );
        $endpoint = "/api/v1/nations/{$nation->id}/profile";

        $this->actingAs($owner)->patchJson($endpoint, [
            'owner_name' => "島\u{200B}主",
        ])->assertUnprocessable()->assertJsonValidationErrors('owner_name');
        $this->patchJson($endpoint, [
            'comment' => "表示\u{202E}反転",
        ])->assertUnprocessable()->assertJsonValidationErrors('comment');
        $this->assertSame('旧島主', $nation->fresh()->owner_name);
        $this->assertSame('旧コメント', $nation->fresh()->profile_comment);
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'nation.profile_updated')->count());

        $this->actingAs($owner)->patchJson($endpoint, [
            'owner_name' => '　新島主　',
            'comment' => ' <script>alert(1)</script> ',
        ])->assertOk()
            ->assertJsonPath('data.owner_name', '新島主')
            ->assertJsonPath('data.comment', '<script>alert(1)</script>');
        $event = DB::table('audit_events')->where('event_type', 'nation.profile_updated')->sole();
        $metadata = json_decode((string) $event->metadata, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($owner->id, $event->actor_user_id);
        $this->assertSame($nation->id, $metadata['nation_id']);
        $this->assertSame($nation->nation_number, $metadata['nation_number']);
        $this->assertSame(['owner_name', 'profile_comment'], $metadata['changed_fields']);
        $this->assertSame([
            'owner_name' => '旧島主', 'profile_comment' => '旧コメント',
        ], $metadata['before']);
        $this->assertSame([
            'owner_name' => '新島主', 'profile_comment' => '<script>alert(1)</script>',
        ], $metadata['after']);
        $encoded = json_encode($metadata, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('profile-provider-secret', $encoded);
        $this->assertStringNotContainsString('秘密のOAuth名', $encoded);

        $this->patchJson($endpoint, [
            'owner_name' => '新島主',
            'comment' => '<script>alert(1)</script>',
        ])->assertOk();
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'nation.profile_updated')->count());

        $other = User::factory()->create();
        app(NationCreationService::class)->create($other, $world, '他島', '他島主');
        $this->actingAs($other)->patchJson($endpoint, [
            'owner_name' => '横取り',
        ])->assertForbidden();
        $this->assertSame('新島主', $nation->fresh()->owner_name);

        auth()->logout();
        $this->flushSession();
        $this->patchJson($endpoint, ['owner_name' => 'guest'])->assertUnauthorized();

        $this->actingAs($owner)->patchJson($endpoint, ['comment' => ''])
            ->assertOk()->assertJsonPath('data.comment', '');
        $this->assertSame('', $nation->fresh()->profile_comment);
        $this->assertSame(2, DB::table('audit_events')->where('event_type', 'nation.profile_updated')->count());

        $historical = RulesetVersion::query()->where('key', 'roadmap-pr18-v1')->firstOrFail();
        $world->update(['ruleset_version_id' => $historical->id]);
        $this->actingAs($owner)->patchJson($endpoint, [
            'owner_name' => '旧World変更',
        ])->assertConflict()->assertJsonPath('code', 'reset_required');
        $this->assertSame('新島主', $nation->fresh()->owner_name);
        $this->assertSame(2, DB::table('audit_events')->where('event_type', 'nation.profile_updated')->count());
    }

    public function test_profile_schema_uses_non_personal_empty_defaults_for_existing_rows(): void
    {
        $world = $this->lightweightWorld();
        $migration = require database_path('migrations/2026_08_04_010000_add_nation_profiles.php');
        $migration->down();
        $nationId = DB::table('nations')->insertGetId([
            'world_id' => $world->id,
            'nation_number' => 1,
            'name' => '既存島',
            'money' => 100,
            'state' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration->up();

        $row = DB::table('nations')->where('id', $nationId)->firstOrFail();
        $this->assertSame('', $row->owner_name);
        $this->assertSame('', $row->profile_comment);
    }
}
