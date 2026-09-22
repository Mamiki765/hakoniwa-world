<?php

namespace App\Application\Underground;

use App\Domain\Underground\Intro\UndergroundIntroStage;
use App\Models\GuideConversationTopic;
use App\Models\Secretary;
use App\Models\SecretaryGuideConversationTotal;
use App\Models\UndergroundIntroProgress;
use App\Models\UndergroundProfile;
use App\Models\UndergroundTrialProgress;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class GuideConversationService
{
    private const PUNCH_LINES = [
        '「いぎゃっ！？」',
        '「いったぁーい！？」',
        '「何すんのよぉっ！」',
        '「100万回やる気！？」',
        '「折れるじゃない！？　えっと……頭蓋骨が！」',
        '「すぐ治るからってやっていいことと悪いことあるんですからね！？」',
        '「んぎっ！？」',
        '「……っ！」',
        '「ひ、ひどいですっ！」',
        '「なんも企んでませんよぉ！？」',
        '「死なないと痛くないは別ですってぇ！」',
        '「私が何したって言うんですかあ！」',
    ];

    public function __construct(private readonly GuideConversationUnlockCatalog $unlocks) {}

    /** @return array{topic_id: int, initial_line: string, choices: list<array{position: int, text: string}>} */
    public function start(User $user): array
    {
        return DB::transaction(function () use ($user): array {
            [$secretary, $profile] = $this->lockedOpenState($user);
            $topic = GuideConversationTopic::query()
                ->where('enabled', true)
                ->whereIn('unlock_key', $this->eligibleUnlockKeys($profile))
                ->inRandomOrder()
                ->first();
            if (! $topic instanceof GuideConversationTopic) {
                throw new UndergroundRuntimeException(
                    'guide_conversation_unavailable',
                    '話題がまだ登録されていません。',
                );
            }

            $this->increment($secretary, 'topics_started');

            return $this->projectTopic($topic);
        }, 3);
    }

    /** @return array{topic_id: int, position: int, reply_line: string} */
    public function reply(User $user, int $topicId, int $position): array
    {
        return DB::transaction(function () use ($user, $topicId, $position): array {
            [$secretary, $profile] = $this->lockedOpenState($user);
            $topic = GuideConversationTopic::query()
                ->whereKey($topicId)
                ->where('enabled', true)
                ->whereIn('unlock_key', $this->eligibleUnlockKeys($profile))
                ->first();
            $reply = $topic?->getAttribute('reply_'.$position);
            $choice = $topic?->getAttribute('choice_'.$position);
            if (! $topic instanceof GuideConversationTopic || ! is_string($choice) || ! is_string($reply)) {
                throw new UndergroundRuntimeException(
                    'guide_conversation_choice_unavailable',
                    'その返答は現在選べません。',
                );
            }

            $this->increment($secretary, 'normal_replies');

            return ['topic_id' => $topic->id, 'position' => $position, 'reply_line' => $reply];
        }, 3);
    }

    /** @return array{punch_line: string} */
    public function punch(User $user): array
    {
        return DB::transaction(function () use ($user): array {
            [$secretary] = $this->lockedOpenState($user);
            $this->increment($secretary, 'punch_count');

            return ['punch_line' => self::PUNCH_LINES[array_rand(self::PUNCH_LINES)]];
        }, 3);
    }

    /** @return array{Secretary, UndergroundProfile} */
    private function lockedOpenState(User $user): array
    {
        $secretary = Secretary::query()
            ->where('user_id', $user->id)
            ->lockForUpdate()
            ->first();
        if (! $secretary instanceof Secretary || $secretary->name === null) {
            throw new UndergroundRuntimeException('underground_secretary_missing', '名前のある秘書が必要です。');
        }
        $profile = UndergroundProfile::query()->where('secretary_id', $secretary->id)->first();
        $intro = $profile instanceof UndergroundProfile
            ? UndergroundIntroProgress::query()->where('underground_profile_id', $profile->id)->first()
            : null;
        if (! $profile instanceof UndergroundProfile
            || ! $intro instanceof UndergroundIntroProgress
            || $intro->stage !== UndergroundIntroStage::UNDERGROUND_OPEN
            || $profile->underground_contract_completed_at === null
            || $profile->growth_path_key === null) {
            throw new UndergroundRuntimeException(
                'guide_conversation_locked',
                '案内人の部屋は地下の案内が完了すると利用できます。',
            );
        }

        app(UndergroundRequestAdmission::class)->assertLockedProfile($profile);

        return [$secretary, $profile];
    }

    /** @return list<string> */
    private function eligibleUnlockKeys(UndergroundProfile $profile): array
    {
        $keys = ['always'];
        $trialKeys = UndergroundTrialProgress::query()
            ->where('underground_profile_id', $profile->id)
            ->whereNotNull('first_cleared_at')
            ->pluck('trial_key');
        foreach ($trialKeys as $trialKey) {
            if (is_string($trialKey)) {
                $keys[] = $this->unlocks->trialFirstClearKey($trialKey);
            }
        }

        return $keys;
    }

    private function increment(Secretary $secretary, string $field): void
    {
        $totals = SecretaryGuideConversationTotal::query()->firstOrCreate([
            'secretary_id' => $secretary->id,
        ]);
        $totals->increment($field);
    }

    /** @return array{topic_id: int, initial_line: string, choices: list<array{position: int, text: string}>} */
    private function projectTopic(GuideConversationTopic $topic): array
    {
        $choices = [];
        foreach ([1, 2, 3] as $position) {
            $text = $topic->getAttribute('choice_'.$position);
            if (is_string($text)) {
                $choices[] = ['position' => $position, 'text' => $text];
            }
        }

        return [
            'topic_id' => $topic->id,
            'initial_line' => $topic->initial_line,
            'choices' => $choices,
        ];
    }
}
