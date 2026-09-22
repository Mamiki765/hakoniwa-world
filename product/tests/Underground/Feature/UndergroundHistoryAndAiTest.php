<?php

namespace Tests\Underground\Feature;

use App\Domain\Underground\Combat\UndergroundAwakening;
use App\Models\Secretary;
use App\Models\UndergroundBattle;
use App\Models\UndergroundIntroProgress;
use App\Models\UndergroundIntroRequest;
use App\Models\UndergroundProfile;
use App\Models\UndergroundTrialProgress;
use App\Models\UndergroundTrialRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTestWorlds;
use Tests\Support\UndergroundPlayerAccessTestCase;

final class UndergroundHistoryAndAiTest extends UndergroundPlayerAccessTestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    public function test_awakening_projection_and_plain_text_message_setting_require_first_clear(): void
    {
        Carbon::setTestNow('2026-08-30 13:30:00+09:00');
        [$user, $secretary] = $this->secretaryUser('設定秘書');
        $profile = $this->openEquipmentProfile($secretary);

        $this->actingAs($user)->getJson('/api/v1/me/underground/main')
            ->assertOk()
            ->assertJsonPath('data.awakening.unlocked', false)
            ->assertJsonPath('data.awakening.current', 0)
            ->assertJsonPath('data.awakening.technique', null);
        $this->actingAs($user)->putJson('/api/v1/me/underground/awakening/message', [
            'request_id' => (string) Str::uuid(),
            'message' => 'まだ使えない',
        ])->assertConflict()->assertJsonPath('code', 'underground_awakening_locked');
        $this->actingAs($user)->putJson('/api/v1/me/underground/awakening/technique', [
            'request_id' => (string) Str::uuid(),
            'technique_key' => 'shura_bloodline',
        ])->assertConflict()->assertJsonPath('code', 'underground_awakening_locked');

        UndergroundTrialProgress::query()->create([
            'underground_profile_id' => $profile->id,
            'trial_key' => 'trial_01',
            'unlocked_at' => Carbon::now()->subMinute(),
            'first_cleared_at' => Carbon::now(),
        ]);
        $profile->update(['awakening_gauge' => 384]);
        $this->actingAs($user)->getJson('/api/v1/me/underground/main')
            ->assertOk()
            ->assertJsonPath('data.awakening.unlocked', true)
            ->assertJsonPath('data.awakening.current', 384)
            ->assertJsonPath('data.awakening.maximum', UndergroundAwakening::GAUGE_MAX)
            ->assertJsonPath('data.awakening.default_message', UndergroundAwakening::DEFAULT_MESSAGE)
            ->assertJsonPath('data.awakening.technique.key', 'decisive_heavenrend')
            ->assertJsonPath('data.awakening.technique.name', '天断一閃')
            ->assertJsonPath('data.awakening.technique.consumes_action', true)
            ->assertJsonPath('data.awakening.selected_technique_key', 'decisive_heavenrend')
            ->assertJsonFragment(['key' => 'shura_bloodline', 'name' => '修羅の血脈']);

        $techniqueRequestId = (string) Str::uuid();
        $techniqueSaved = $this->actingAs($user)->putJson('/api/v1/me/underground/awakening/technique', [
            'request_id' => $techniqueRequestId,
            'technique_key' => 'shura_bloodline',
        ])->assertOk()
            ->assertJsonPath('data.awakening.technique.key', 'shura_bloodline')
            ->assertJsonPath('data.awakening.selected_technique_key', 'shura_bloodline');
        $this->actingAs($user)->putJson('/api/v1/me/underground/awakening/technique', [
            'request_id' => $techniqueRequestId,
            'technique_key' => 'shura_bloodline',
        ])->assertOk()->assertExactJson($techniqueSaved->json());
        $this->actingAs($user)->putJson('/api/v1/me/underground/awakening/technique', [
            'request_id' => $techniqueRequestId,
            'technique_key' => 'decisive_heavenrend',
        ])->assertConflict()->assertJsonPath('code', 'underground_request_conflict');
        $this->actingAs($user)->putJson('/api/v1/me/underground/awakening/technique', [
            'request_id' => (string) Str::uuid(),
            'technique_key' => 'formless_strike',
        ])->assertConflict()->assertJsonPath('code', 'underground_awakening_technique_invalid');
        UndergroundTrialRun::query()->create([
            'underground_profile_id' => $profile->id,
            'run_key' => (string) Str::uuid(),
            'trial_key' => 'trial_01',
            'trial_content_identity' => 'secretary-underground-trial-01-v2',
            'next_battle_index' => 2,
            'status' => UndergroundTrialRun::STATUS_ACTIVE,
            'started_at' => Carbon::now(),
        ]);
        $this->actingAs($user)->putJson('/api/v1/me/underground/awakening/technique', [
            'request_id' => (string) Str::uuid(),
            'technique_key' => 'decisive_heavenrend',
        ])->assertOk()->assertJsonPath('data.awakening.selected_technique_key', 'decisive_heavenrend');
        $this->assertSame('decisive_heavenrend', $profile->refresh()->awakening_technique_key);

        $requestId = (string) Str::uuid();
        $custom = '<script>{secretary_name}</script>が覚醒した。';
        $saved = $this->actingAs($user)->putJson('/api/v1/me/underground/awakening/message', [
            'request_id' => $requestId,
            'message' => $custom,
        ])->assertOk()->assertJsonPath('data.awakening.custom_message', $custom);
        $this->actingAs($user)->putJson('/api/v1/me/underground/awakening/message', [
            'request_id' => $requestId,
            'message' => $custom,
        ])->assertOk()->assertExactJson($saved->json());
        $this->actingAs($user)->putJson('/api/v1/me/underground/awakening/message', [
            'request_id' => $requestId,
            'message' => '別の意図',
        ])->assertConflict()->assertJsonPath('code', 'underground_request_conflict');
        $this->assertSame($custom, $profile->refresh()->awakening_message);
        $this->assertSame(
            '<script>設定秘書</script>が覚醒した。',
            app(UndergroundAwakening::class)->renderMessage($profile->awakening_message, $secretary->name),
        );

        $this->actingAs($user)->putJson('/api/v1/me/underground/awakening/message', [
            'request_id' => (string) Str::uuid(),
            'message' => '',
        ])->assertOk()
            ->assertJsonPath('data.awakening.custom_message', null)
            ->assertJsonPath('data.awakening.default_message', UndergroundAwakening::DEFAULT_MESSAGE);
        $this->assertNull($profile->refresh()->awakening_message);
        $this->actingAs($user)->putJson('/api/v1/me/underground/awakening/message', [
            'request_id' => (string) Str::uuid(),
            'message' => "invalid\nmessage",
        ])->assertUnprocessable()->assertJsonValidationErrors('message');
        $this->actingAs($user)->putJson('/api/v1/me/underground/awakening/message', [
            'request_id' => (string) Str::uuid(),
            'message' => str_repeat('界', 101),
        ])->assertUnprocessable()->assertJsonValidationErrors('message');
    }

    public function test_ai_configuration_uses_default_preset_and_saves_normalized_rules_idempotently(): void
    {
        [$user, $secretary] = $this->secretaryUser('AI設定秘書');
        $profile = $this->openEquipmentProfile($secretary);

        $default = $this->actingAs($user)->getJson('/api/v1/me/underground/main')
            ->assertOk()
            ->assertJsonPath('data.ai.schema_version', 2)
            ->assertJsonPath('data.ai.max_rules', 20)
            ->assertJsonPath('data.ai.max_conditions_per_rule', 2)
            ->assertJsonPath('data.ai.is_custom', false)
            ->assertJsonPath('data.ai.rules.0.conditions.0.type', 'own_hp_lte')
            ->assertJsonPath('data.ai.rules.0.conditions.0.percent', 20)
            ->assertJsonPath('data.ai.rules.0.action', 'awakening')
            ->assertJsonPath('data.ai.rules.2.action', 'defend')
            ->assertJsonPath('data.ai.rules.3.action', 'normal_attack')
            ->assertJsonPath('data.ai.catalog.targets.0.key', 'lowest_hp_ally')
            ->assertJsonPath('data.ai.catalog.targets.1.key', 'untaunted_enemy');
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $default->json('data.ai.hash'));
        $this->assertSame($default->json('data.ai.default_rules'), $default->json('data.ai.rules'));

        $requestId = (string) Str::uuid();
        $payload = [
            'request_id' => $requestId,
            'rules' => [
                ['conditions' => [], 'action' => 'skill:executioner_cut', 'target' => 'untaunted_enemy'],
                [
                    'conditions' => [['percent' => 50, 'type' => 'own_hp_lte']],
                    'action' => 'jump',
                    'jump_to' => 3,
                ],
                ['conditions' => [['type' => 'always']], 'action' => 'defend'],
                [
                    'conditions' => [
                        ['type' => 'skill_ready', 'skill' => 'mending_prayer'],
                        ['type' => 'own_hp_lte', 'percent' => 55],
                    ],
                    'action' => 'skill:mending_prayer',
                ],
            ],
        ];
        $payload['rules'] = array_pad($payload['rules'], 20, ['conditions' => [], 'action' => 'awakening_technique']);
        $saved = $this->actingAs($user)->putJson('/api/v1/me/underground/ai', $payload)
            ->assertOk()
            ->assertJsonPath('data.ai.is_custom', true)
            ->assertJsonPath('data.ai.rules.0.conditions.0.type', 'always')
            ->assertJsonPath('data.ai.rules.0.action', 'skill:executioner_cut')
            ->assertJsonPath('data.ai.rules.0.target', 'untaunted_enemy')
            ->assertJsonPath('data.ai.rules.1.jump_to', 3);
        $equivalentPayload = $payload;
        $equivalentPayload['rules'][3]['conditions'] = array_reverse(
            $equivalentPayload['rules'][3]['conditions'],
        );
        $this->actingAs($user)->putJson('/api/v1/me/underground/ai', $equivalentPayload)
            ->assertOk()->assertExactJson($saved->json());
        $this->assertEquals($saved->json('data.ai.rules'), $profile->refresh()->custom_ai_rules);
        $this->actingAs($user)->putJson('/api/v1/me/underground/ai', [
            'request_id' => (string) Str::uuid(),
            'rules' => [...$payload['rules'], ['conditions' => [], 'action' => 'defend']],
        ])->assertUnprocessable();
        $this->assertEquals($saved->json('data.ai.rules'), $profile->refresh()->custom_ai_rules);
        $this->assertDatabaseHas('underground_intro_requests', [
            'underground_profile_id' => $profile->id,
            'request_id' => $requestId,
            'operation' => 'ai_configuration',
        ]);

        $this->actingAs($user)->postJson('/api/v1/me/underground/respec', [
            'request_id' => (string) Str::uuid(),
            'growth_path_key' => 'guardianship_blue',
        ])->assertOk()
            ->assertJsonPath('data.ai.rules.0.action', 'skill:executioner_cut');
        $this->assertEquals($saved->json('data.ai.rules'), $profile->refresh()->custom_ai_rules);

        $this->actingAs($user)->putJson('/api/v1/me/underground/ai', [
            ...$payload,
            'rules' => [],
        ])->assertConflict()->assertJsonPath('code', 'underground_request_conflict');
        $this->actingAs($user)->putJson('/api/v1/me/underground/ai', [
            'request_id' => (string) Str::uuid(),
            'rules' => null,
        ])->assertOk()
            ->assertJsonPath('data.ai.is_custom', false)
            ->assertJsonPath('data.ai.rules.0.action', 'awakening');
        $this->assertNull($profile->refresh()->custom_ai_rules);
    }

    public function test_ai_configuration_rejects_invalid_rules_without_mutation(): void
    {
        [$user, $secretary] = $this->secretaryUser('AI検証秘書');
        $profile = $this->openEquipmentProfile($secretary);

        foreach ([
            [[
                'conditions' => [],
                'action' => 'jump',
                'jump_to' => 1,
            ]],
            [[
                'conditions' => [['type' => 'skill_ready', 'skill' => 'enemy_telegraph']],
                'action' => 'defend',
            ]],
        ] as $rules) {
            $this->actingAs($user)->putJson('/api/v1/me/underground/ai', [
                'request_id' => (string) Str::uuid(),
                'rules' => $rules,
            ])->assertConflict()->assertJsonPath('code', 'underground_ai_rules_invalid');
        }
        $this->actingAs($user)->putJson('/api/v1/me/underground/ai', [
            'request_id' => (string) Str::uuid(),
        ])->assertUnprocessable()->assertJsonValidationErrors('rules');

        $this->assertNull($profile->refresh()->custom_ai_rules);
        $this->assertSame(0, UndergroundIntroRequest::query()
            ->where('underground_profile_id', $profile->id)
            ->where('operation', 'ai_configuration')
            ->count());
    }

    public function test_refresh_resumes_meaningful_stage_and_intro_history_is_private_and_owner_scoped(): void
    {
        [$owner, $ownerSecretary] = $this->secretaryUser('History secretary');
        [$other] = $this->secretaryUser('Other history secretary');
        $this->actingAs($owner)->postJson('/api/v1/me/underground/entry', [
            'request_id' => (string) Str::uuid(),
        ])->assertOk();
        $this->advance($owner, 'initial_story_complete');
        $tutorialId = (string) Str::uuid();
        $this->actingAs($owner)->postJson('/api/v1/me/underground/tutorial', [
            'request_id' => $tutorialId,
        ])->assertOk();

        $this->actingAs($owner)->getJson('/api/v1/me/underground')
            ->assertOk()->assertJsonPath('data.stage', 'escape_pending');
        $this->actingAs($owner)->getJson('/api/v1/me/underground/battles')
            ->assertOk()
            ->assertJsonPath('data.0.id', $tutorialId)
            ->assertJsonMissingPath('data.0.snapshot')
            ->assertJsonMissingPath('data.0.private_seed');
        $this->actingAs($owner)->getJson("/api/v1/me/underground/battles/{$tutorialId}")
            ->assertOk()
            ->assertJsonPath('data.encounter_name', 'ジャイアントラット')
            ->assertJsonStructure(['data' => ['actions']])
            ->assertJsonMissingPath('data.snapshot');
        $battle = UndergroundBattle::query()->where('request_id', $tutorialId)->sole();
        $battle->log()->update(['expires_at' => Carbon::now()->subSecond()]);
        $this->artisan('underground:prune-battle-logs')->assertSuccessful();
        $this->actingAs($owner)->getJson('/api/v1/me/underground/battles')
            ->assertOk()
            ->assertJsonPath('data.0.id', $tutorialId)
            ->assertJsonPath('data.0.detail_available', false)
            ->assertJsonPath('data.0.actions', null);
        $this->actingAs($owner)->getJson("/api/v1/me/underground/battles/{$tutorialId}")
            ->assertOk()
            ->assertJsonPath('data.detail_available', false)
            ->assertJsonPath('data.detail_message', '詳細ログは保存期間を過ぎました。')
            ->assertJsonPath('data.actions', null);
        $this->actingAs($other)->getJson("/api/v1/me/underground/battles/{$tutorialId}")
            ->assertNotFound();
        $ownerSecretary->delete();
        $this->assertSame(0, UndergroundProfile::query()->count());
        $this->assertSame(0, UndergroundBattle::query()->count());
        $this->assertSame(0, UndergroundIntroRequest::query()->count());
    }

    public function test_recollections_require_trial_two_first_clear_and_are_sequential_idempotent_and_side_effect_free(): void
    {
        [$owner, $ownerSecretary] = $this->secretaryUser('Recollection secretary');
        $profile = $this->openEquipmentProfile($ownerSecretary);
        UndergroundTrialProgress::query()->create([
            'underground_profile_id' => $profile->id,
            'trial_key' => 'trial_02',
            'unlocked_at' => Carbon::now(),
            'first_cleared_at' => null,
        ]);

        $locked = $this->actingAs($owner)->getJson('/api/v1/me/underground')
            ->assertOk()
            ->json('data');
        $this->assertTrue($locked['recollections']['available']);
        $this->assertFalse($locked['recollections']['trial_02_first_cleared']);
        $this->assertFalse($locked['recollections']['past_available']);
        $this->assertFalse(collect($locked['recollections']['entries'])->contains('key', 'past_1'));
        $this->assertFalse(collect($locked['recollections']['entries'])->contains('key', 'true_name_before'));
        $this->assertFalse(collect($locked['recollections']['entries'])->contains('key', 'true_name_after'));
        $secretaryNaming = collect($locked['recollections']['entries'])->firstWhere('key', 'secretary_naming');
        $this->assertNull($secretaryNaming);
        $this->actingAs($owner)->postJson('/api/v1/me/underground/recollections/read', [
            'request_id' => (string) Str::uuid(),
            'chapter' => 1,
        ])->assertConflict()->assertJsonPath('code', 'underground_recollection_locked');
        $this->assertSame(0, UndergroundIntroRequest::query()->where('operation', 'recollection_read')->count());

        UndergroundTrialProgress::query()
            ->where('underground_profile_id', $profile->id)
            ->where('trial_key', 'trial_02')
            ->update(['first_cleared_at' => Carbon::now()]);
        $unread = $this->actingAs($owner)->getJson('/api/v1/me/underground')
            ->assertOk()
            ->json('data');
        $this->assertTrue($unread['recollections']['available']);
        $this->assertTrue($unread['recollections']['past_available']);
        $this->assertSame(0, $unread['recollections']['max_completed']);
        $this->assertNull($unread['recollections']['serious_talk']);
        $pastOne = collect($unread['recollections']['entries'])->firstWhere('key', 'past_1');
        $pastTwo = collect($unread['recollections']['entries'])->firstWhere('key', 'past_2');
        $this->assertFalse($pastOne['locked']);
        $this->assertFalse($pastOne['experienced']);
        $this->assertContains(
            '「ええ。かつての世界の全てを滅ぼし、深い海に沈め、永遠に解かれぬ封印をつけたのが犯人というのなら、私がそうですよ」',
            $pastOne['body'],
        );
        $this->assertTrue($pastTwo['locked']);
        $this->assertFalse($pastTwo['experienced']);
        $this->assertArrayNotHasKey('body', $pastTwo);

        $introProgress = UndergroundIntroProgress::query()
            ->where('underground_profile_id', $profile->id)
            ->sole();
        $before = [
            ...$profile->refresh()->only(['combat_level', 'combat_xp', 'shard_balance', 'current_hp']),
            'guide_recollection_max_completed' => $introProgress->guide_recollection_max_completed,
        ];
        $battleCount = UndergroundBattle::query()->count();
        $requestId = (string) Str::uuid();
        $this->actingAs($owner)->postJson('/api/v1/me/underground/recollections/read', [
            'request_id' => (string) Str::uuid(),
            'chapter' => 2,
        ])->assertConflict()->assertJsonPath('code', 'underground_recollection_sequence_conflict');
        $this->assertSame(0, $introProgress->refresh()->guide_recollection_max_completed);

        $first = $this->actingAs($owner)->postJson('/api/v1/me/underground/recollections/read', [
            'request_id' => $requestId,
            'chapter' => 1,
        ])->assertOk();
        $firstData = $first->json('data');
        $this->assertSame(1, $firstData['recollections']['max_completed']);
        $this->assertNull(collect($firstData['recollections']['entries'])->firstWhere('key', 'past_1'));
        $this->assertSame($battleCount, UndergroundBattle::query()->count());
        $afterFirst = [
            ...$profile->refresh()->only(['combat_level', 'combat_xp', 'shard_balance', 'current_hp']),
            'guide_recollection_max_completed' => $introProgress->refresh()->guide_recollection_max_completed,
        ];
        $this->assertSame($before['combat_level'], $afterFirst['combat_level']);
        $this->assertSame($before['combat_xp'], $afterFirst['combat_xp']);
        $this->assertSame($before['shard_balance'], $afterFirst['shard_balance']);
        $this->assertSame($before['current_hp'], $afterFirst['current_hp']);
        $this->assertSame(1, UndergroundIntroRequest::query()->where('operation', 'recollection_read')->count());

        $retry = $this->actingAs($owner)->postJson('/api/v1/me/underground/recollections/read', [
            'request_id' => $requestId,
            'chapter' => 1,
        ])->assertOk();
        $this->assertSame($first->json(), $retry->json());
        $this->assertSame(1, UndergroundIntroRequest::query()->where('operation', 'recollection_read')->count());

        $this->actingAs($owner)->postJson('/api/v1/me/underground/recollections/read', [
            'request_id' => $requestId,
            'chapter' => 2,
        ])->assertConflict()->assertJsonPath('code', 'underground_request_conflict');
        $this->actingAs($owner)->postJson('/api/v1/me/underground/recollections/read', [
            'request_id' => (string) Str::uuid(),
            'chapter' => 3,
        ])->assertConflict()->assertJsonPath('code', 'underground_recollection_sequence_conflict');

        for ($chapter = 2; $chapter <= 5; $chapter++) {
            $response = $this->actingAs($owner)->postJson('/api/v1/me/underground/recollections/read', [
                'request_id' => (string) Str::uuid(),
                'chapter' => $chapter,
            ])->assertOk();
            $this->assertSame($chapter, $response->json('data.recollections.max_completed'));
        }
        $complete = $this->actingAs($owner)->getJson('/api/v1/me/underground')
            ->assertOk()
            ->json('data');
        $this->assertSame(5, $complete['recollections']['max_completed']);
        $this->assertSame('案内人に真剣な話をする', $complete['recollections']['serious_talk']['title']);
        $talkScenes = $complete['recollections']['serious_talk']['scenes'];
        $this->assertSame('true_name', $talkScenes['root']['choices'][0]['next']);
        $this->assertSame([
            '「私は、自分のことが嫌いです」',
            '「……それに、最初に私に名前をつけたあなたの夢が覚めることになります」',
            '「私は、一応は夢魔と名付けられた魔族のハーフです。夢が覚めることはしたくありませんね」',
            '「どうか夢に浸ってください、私の唯一のお客様」',
        ], $talkScenes['true_name']['lines']);
        $this->assertSame([
            'それでも教えて欲しい',
            'あなたについて知ることが私の夢だと伝える',
            '彼女に自分がつけた名前を呼ぶ',
            '立ち去る',
        ], array_column($talkScenes['true_name']['choices'], 'label'));
        $this->assertSame([
            'true_name_branch',
            'true_name_reveal',
            'true_name_named',
            'root',
        ], array_column($talkScenes['true_name']['choices'], 'next'));
        $this->assertSame([
            '「……案内係」',
            '「ええ、はい。　偶然一致していたのです！　なんと奇跡的な一致でしょうね♪ いひひ♪」',
        ], $talkScenes['true_name_branch']['lines']);
        $this->assertSame([
            '立ち去る',
        ], array_column($talkScenes['true_name_branch']['choices'], 'label'));
        $this->assertSame('root', $talkScenes['true_name_branch']['choices'][0]['next']);
        $this->assertSame(['「………………」', '「リカ。」'], $talkScenes['true_name_reveal']['lines']);
        $this->assertSame(['leave', 'back'], array_column($talkScenes['true_name_reveal']['choices'], 'key'));
        $this->assertSame('true_name', $talkScenes['true_name_reveal']['choices'][1]['next']);
        $this->assertSame(['「そう。それでいい。」'], $talkScenes['true_name_named']['lines']);
        $this->assertSame('true_name', $talkScenes['true_name_named']['choices'][0]['next']);
        $this->assertSame(['はじめに戻る'], array_column($talkScenes['embrace_more']['choices'], 'label'));
        $this->assertStringNotContainsString('闘いを挑む', json_encode($complete, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        $scriptedLoss = $this->tutorialBattle($profile);
        $profile->update(['villa_purchased_at' => Carbon::now()]);
        UndergroundIntroProgress::query()
            ->where('underground_profile_id', $profile->id)
            ->sole()
            ->update([
                'branch_identity' => 'true_name',
                'shopkeeper_name' => 'リカ',
                'special_loss_required' => true,
                'scripted_loss_battle_id' => $scriptedLoss->id,
            ]);
        $trueNameData = $this->actingAs($owner)->getJson('/api/v1/me/underground')
            ->assertOk()
            ->json('data');
        $trueName = $trueNameData['recollections']['serious_talk']['scenes'];
        $trueNameEntries = collect($trueNameData['recollections']['entries']);
        $this->assertSame('案内人に例の名前をつけると…', $trueNameEntries->firstWhere('key', 'true_name_before')['title']);
        $this->assertSame('ボコられました。', $trueNameEntries->firstWhere('key', 'true_name_after')['title']);
        $this->assertSame([
            '「……揶揄ってるんですかね、知ってるくせに」',
            '「苗字のことなら、私にはありませんよ」',
            '「なぜなら、私は魔王の一族ですから。王族には名前しかありません」',
            '「リカという名前しか無いのです。　一時期、『自分に苗字がないのはおかしい』と本気で考えて雨宮なんてもん付け足したりしてましたけどね」',
            '「代わりに、種族の名をつけて呼ぶことはありましたけどね」',
            '「RIKA the Succubus……リカ＝サキュバス。おお、さむい、さむい」',
        ], $trueName['true_name_branch']['lines']);
    }

    public function test_recollection_only_projects_the_initial_growth_choice_and_never_invents_the_free_branch(): void
    {
        [$owner, $secretary] = $this->secretaryUser('Growth recollection secretary');
        $profile = $this->openEquipmentProfile($secretary);
        $profile->update(['villa_purchased_at' => Carbon::now()]);
        $profile->introProgress->update(['initial_growth_path_key' => 'martial_red']);

        $respecified = $this->actingAs($owner)->postJson('/api/v1/me/underground/respec', [
            'request_id' => (string) Str::uuid(),
            'growth_path_key' => 'free_black',
        ])->assertOk()->json('data');
        $this->assertSame('free_black', $profile->fresh()->growth_path_key);
        $body = collect($respecified['recollections']['entries'])->firstWhere('key', 'common_ending')['body'];
        $this->assertSame('「ふふ、とってもお似合いですよ、その能力」', $body[0]);
        $this->assertNotContains('「全部？　まぁ、別にあなたにしか必要のないものです。ええ、あげますよ、欲張りさん？」', $body);
        $this->assertStringNotContainsString('別の成長方針', implode("\n", $body));

        UndergroundIntroRequest::query()
            ->where('underground_profile_id', $profile->id)
            ->where('operation', 'growth_path')
            ->delete();
        $fallback = $this->actingAs($owner)->getJson('/api/v1/me/underground')
            ->assertOk()->json('data.recollections.entries');
        $fallbackBody = collect($fallback)->firstWhere('key', 'common_ending')['body'];
        $this->assertSame('「ふふ、とってもお似合いですよ、その能力」', $fallbackBody[0]);
        $this->assertNotContains('「全部？　まぁ、別にあなたにしか必要のないものです。ええ、あげますよ、欲張りさん？」', $fallbackBody);

        $profile->introProgress->update(['initial_growth_path_key' => 'free_black']);
        $free = $this->actingAs($owner)->getJson('/api/v1/me/underground')
            ->assertOk()->json('data.recollections.entries');
        $freeBody = collect($free)->firstWhere('key', 'common_ending')['body'];
        $this->assertSame('「全部？　まぁ、別にあなたにしか必要のないものです。ええ、あげますよ、欲張りさん？」', $freeBody[0]);
        $this->assertNotContains('「ふふ、とってもお似合いですよ、その能力」', $freeBody);
    }

    public function test_recollection_trial_stories_survive_receipt_removal(): void
    {
        [$owner, $secretary] = $this->secretaryUser('Recollection secretary');
        $profile = $this->openEquipmentProfile($secretary);
        $profile->update(['villa_purchased_at' => Carbon::now()]);
        UndergroundTrialProgress::query()->updateOrCreate([
            'underground_profile_id' => $profile->id, 'trial_key' => 'trial_02',
        ], [
            'unlocked_at' => Carbon::now(), 'first_cleared_at' => Carbon::now(),
            'first_challenged_at' => Carbon::now(), 'first_challenge_intro' => 'Original challenge',
            'first_clear_story' => ['title' => 'Original title', 'body' => 'Original story', 'system_messages' => ['Original reward']],
        ]);
        $intro = $profile->introProgress;
        $intro->update(['tutorial_encounter_key' => 'original_enemy']);
        UndergroundBattle::query()->where('underground_profile_id', $profile->id)->delete();
        UndergroundIntroRequest::query()->where('underground_profile_id', $profile->id)->delete();
        $entries = collect($this->actingAs($owner)->getJson('/api/v1/me/underground')
            ->assertOk()->json('data.recollections.entries'));
        $this->assertSame(['Original challenge'], $entries->firstWhere('key', 'trial_02_start')['body']);
        $this->assertSame(['Original story', 'Original reward'], $entries->firstWhere('key', 'trial_02_clear')['body']);
        $this->assertSame('デュラハンの撃破と案内人', $entries->firstWhere('key', 'trial_02_clear')['title']);
        $this->assertTrue($entries->firstWhere('key', 'tutorial')['experienced']);
        $this->assertSame(['original_enemyとのTutorial戦闘を経験しました。'], $entries->firstWhere('key', 'tutorial')['body']);
        $this->assertSame('underground_open', $intro->fresh()->stage);
    }

    /** @return array{User, Secretary} */
}
