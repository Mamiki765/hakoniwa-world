<?php

namespace App\Application;

use App\Domain\Secretary\SecretarySkillCatalog;
use App\Models\Secretary;
use App\Models\SecretarySkill;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\Schema;

final class SecretaryService
{
    public function __construct(
        private readonly SecretarySkillCatalog $catalog,
        private readonly SecretaryItemGrantService $items,
    ) {}

    public function ensureForUser(User $user): Secretary
    {
        $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
        $secretaryCatalog = config('hakoniwa.current_catalogs.secretary');
        if (! is_array($secretaryCatalog)) {
            throw new DomainException('The current immutable Secretary ruleset contract is missing.');
        }
        $initialStates = $this->catalog->initialStates(['secretary' => $secretaryCatalog]);
        $secretary = Secretary::query()->firstOrCreate(
            ['user_id' => $lockedUser->id],
            ['name' => null, 'named_at' => null],
        );
        $now = now();
        $rows = [];
        foreach ($initialStates as $skillKey => $state) {
            $rows[] = [
                'secretary_id' => $secretary->id,
                'skill_key' => $skillKey,
                'level' => $state['level'],
                'experience' => $state['experience'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        SecretarySkill::query()->insertOrIgnore($rows);
        $skills = SecretarySkill::query()->where('secretary_id', $secretary->id)->get()->keyBy('skill_key');
        if ($skills->keys()->sort()->values()->all() !== collect(SecretarySkillCatalog::KEYS)->sort()->values()->all()) {
            throw new DomainException('Secretary skill initialization did not produce the exact Secretary v1 catalog.');
        }

        if (Schema::hasTable('secretary_item_instances')) {
            $item = $this->items->grantStarterOldBow($secretary);
            if ($item === null) {
                throw new DomainException('Secretary starter item could not be granted because inventory is full.');
            }
        }

        return $secretary->load(['skills', 'itemInstances']);
    }
}
