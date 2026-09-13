<?php

namespace App\Application;

use App\Application\Underground\UndergroundAlphaV1PlayerCatalog;
use App\Application\Underground\UndergroundEquipmentLoadoutResolver;
use App\Application\Underground\UndergroundRuntimeCatalog;
use App\Domain\Underground\Combat\UndergroundAwakening;
use App\Models\Secretary;
use App\Models\SecretaryLendingSetting;
use App\Models\UndergroundProfile;
use App\Models\UndergroundTrialProgress;
use App\Models\User;
use App\Models\UserSkipTicketBalance;
use Illuminate\Support\Facades\DB;

final readonly class SecretaryLendingService
{
    private const CANDIDATE_PAGE_SIZE = 20;

    public function __construct(
        private SecretaryProfilePresenter $presenter,
        private UndergroundEquipmentLoadoutResolver $equipmentLoadout,
        private UndergroundRuntimeCatalog $runtimeCatalog,
        private UndergroundAlphaV1PlayerCatalog $playerCatalog,
        private VisitorCodeAllocator $visitorCodes,
    ) {}

    public function settings(User $user): SecretaryLendingSetting
    {
        $secretary = Secretary::query()->where('user_id', $user->id)->firstOrFail();

        return SecretaryLendingSetting::query()->where('secretary_id', $secretary->id)->first()
            ?? new SecretaryLendingSetting(['secretary_id' => $secretary->id, 'is_public' => false, 'is_available' => true]);
    }

    public function update(User $user, bool $isLendable): SecretaryLendingSetting
    {
        return DB::transaction(function () use ($user, $isLendable): SecretaryLendingSetting {
            $secretary = Secretary::query()->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            $this->visitorCodes->allocate($user);
            DB::table('secretary_lending_settings')->insertOrIgnore([
                'secretary_id' => $secretary->id, 'is_public' => false, 'is_available' => true,
            ]);
            $setting = SecretaryLendingSetting::query()->where('secretary_id', $secretary->id)->lockForUpdate()->firstOrFail();
            $setting->update(['is_public' => $isLendable, 'is_available' => $isLendable]);

            return $setting;
        }, 3);
    }

    /** @return list<array<string,mixed>> */
    public function publicCandidates(User $viewer, ?int $excludeSecretaryId = null, int $afterId = 0): array
    {
        return $this->publicCandidatePage($viewer, $excludeSecretaryId, $afterId)['candidates'];
    }

    /**
     * @return array{candidates:list<array<string,mixed>>, next_after_id:int|null, has_more:bool}
     */
    public function publicCandidatePage(User $viewer, ?int $excludeSecretaryId = null, int $afterId = 0): array
    {
        $query = Secretary::query()->with([
            'user',
            'images',
            'undergroundProfile.skillAllocations',
            'undergroundProfile.ownedEquipment' => fn ($query) => $query->whereNotNull('equipped_slot'),
            'undergroundProfile.trialProgresses',
        ])
            ->join('secretary_lending_settings', 'secretaries.id', '=', 'secretary_lending_settings.secretary_id')
            ->where('secretary_lending_settings.is_public', true)
            ->where('secretary_lending_settings.is_available', true)
            ->whereNotNull('secretaries.name')
            ->whereNotNull('secretaries.named_at')
            ->whereHas('user', fn ($query) => $query->whereNotNull('visitor_code')->where('visitor_code', '<>', ''))
            ->where('secretaries.id', '>', $afterId)
            ->whereHas('undergroundProfile', fn ($query) => $query
                ->whereNotNull('growth_path_key')->whereNotNull('underground_contract_completed_at')
                ->where('skill_rebuild_required', false))
            ->select('secretaries.*');
        if ($excludeSecretaryId !== null) {
            $query->where('secretaries.id', '<>', $excludeSecretaryId);
        }

        $sourceRows = $query->orderBy('secretaries.id')->limit(self::CANDIDATE_PAGE_SIZE + 1)->get();
        $hasMore = $sourceRows->count() > self::CANDIDATE_PAGE_SIZE;
        $scannedRows = $sourceRows->take(self::CANDIDATE_PAGE_SIZE);
        $lastScanned = $scannedRows->last();
        $candidates = [];
        foreach ($scannedRows as $secretary) {
            $profile = $secretary->undergroundProfile;
            if (! $profile instanceof UndergroundProfile
                || ! is_string($profile->growth_path_key)
                || $profile->underground_contract_completed_at === null
                || ! is_string($secretary->user->visitor_code)) {
                continue;
            }
            try {
                $equipment = $profile->ownedEquipment->map(
                    fn ($item): array => $this->equipmentLoadout->projectOwned($item),
                )->all();
            } catch (\RuntimeException) {
                continue;
            }
            $equipped = [];
            foreach ($equipment as $item) {
                $equipped[] = implode(' ', array_filter([
                    is_string($item['name'] ?? null) ? $item['name'] : null,
                    is_int($item['item_level'] ?? null) ? 'IL'.$item['item_level'] : null,
                ]));
            }
            $awakeningUnlocked = $profile->trialProgresses->contains(
                fn (UndergroundTrialProgress $progress): bool => $progress->trial_key === $this->runtimeCatalog->firstTrialKey()
                    && $progress->first_cleared_at !== null,
            );
            $icon = $this->presenter->resolveCompactImage($secretary, $viewer);
            $candidates[] = [
                'secretary_id' => $secretary->id,
                'source' => 'borrowed_secretary',
                'owner_game_id' => $secretary->user->visitor_code,
                'display_name' => $this->presenter->battleDisplayName($secretary),
                'formal_name' => $secretary->name,
                'icon_url' => is_string($icon['url'] ?? null) ? $icon['url'] : null,
                'combat_level' => $profile->combat_level,
                'growth_path_key' => $profile->growth_path_key,
                'growth_path' => $this->playerCatalog->growthPath($profile->growth_path_key)['label'],
                'build' => ['skills' => array_keys($profile->skillAllocationMap())],
                'equipment' => $equipped,
                'equipment_summary' => $equipped === [] ? null : implode(' / ', $equipped),
                'awakening' => $awakeningUnlocked
                    ? ($profile->awakening_gauge >= UndergroundAwakening::GAUGE_MAX ? 'Ready' : '解禁済み')
                    : '未解禁',
                'available' => true,
            ];
        }

        return [
            'candidates' => $candidates,
            'next_after_id' => $hasMore && $lastScanned instanceof Secretary ? (int) $lastScanned->id : null,
            'has_more' => $hasMore,
        ];
    }

    public function ticketBalance(User $user): int
    {
        return (int) UserSkipTicketBalance::query()->where('user_id', $user->id)->value('balance');
    }

    /** @return array<string, mixed> */
    public function state(User $user, Secretary $secretary): array
    {
        $settings = $this->settings($user);
        $isLendable = (bool) $settings->is_public && (bool) $settings->is_available;

        return [
            'settings' => [
                'is_lendable' => $isLendable,
                'is_public' => $isLendable,
                'is_available' => $isLendable,
            ],
            'candidates' => [],
            'ticket_balance' => $this->ticketBalance($user),
        ];
    }
}
