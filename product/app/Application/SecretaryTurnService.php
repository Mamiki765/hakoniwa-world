<?php

namespace App\Application;

use App\Domain\Secretary\SecretarySkillCatalog;
use App\Domain\Secretary\SecretarySkillProgression;
use App\Domain\Turn\TurnContext;
use App\Models\NationMembership;
use App\Models\Secretary;
use App\Models\SecretarySkill;
use DomainException;

final class SecretaryTurnService
{
    public function __construct(
        private readonly SecretarySkillCatalog $catalog,
        private readonly SecretarySkillProgression $progression,
    ) {}

    /** @param list<int> $nationIds */
    public function loadAttemptSnapshots(TurnContext $context, array $nationIds): int
    {
        if (! isset($context->ruleset->settings['secretary']) || $nationIds === []) {
            return 0;
        }
        $memberships = NationMembership::query()
            ->whereIn('nation_id', $nationIds)
            ->where('world_id', $context->world->id)
            ->where('role', 'owner')
            ->with(['user.secretary.skills'])
            ->get()
            ->keyBy('nation_id');
        if ($memberships->count() !== count($nationIds)) {
            throw new DomainException('An active Nation is missing its owner membership during Secretary batch loading.');
        }
        $expected = $this->catalog->definitions($context->ruleset->settings);
        foreach ($nationIds as $nationId) {
            $secretary = $memberships->get($nationId)?->user?->secretary;
            if (! $secretary instanceof Secretary) {
                throw new DomainException("Active Nation {$nationId} is missing its User Secretary.");
            }
            $rows = $secretary->skills->keyBy('skill_key');
            $skills = [];
            foreach (array_keys($expected) as $skillKey) {
                $row = $rows->get($skillKey);
                if (! $row instanceof SecretarySkill) {
                    throw new DomainException("Secretary {$secretary->id} is missing skill {$skillKey}.");
                }
                $skills[$skillKey] = [
                    'level' => (int) $row->level,
                    'experience' => (int) $row->experience,
                ];
            }
            if ($rows->count() !== count($skills)) {
                throw new DomainException("Secretary {$secretary->id} has an unexpected skill outside Secretary v1.");
            }
            $context->state->setSecretarySnapshot(
                $nationId,
                (int) $secretary->id,
                $secretary->name,
                $skills,
            );
        }

        return count($nationIds);
    }

    /** @return array{experience_awarded: int, skills_changed: int, levels_gained: int} */
    public function flushExperience(TurnContext $context): array
    {
        $awards = $context->state->pendingSecretaryExperience();
        if ($awards === []) {
            $context->state->markSecretaryExperienceFlushed();

            return ['experience_awarded' => 0, 'skills_changed' => 0, 'levels_gained' => 0];
        }
        $secretaryIds = [];
        foreach (array_keys($awards) as $nationId) {
            $secretaryIds[] = $context->state->secretarySnapshot($nationId)['secretary_id'];
        }
        $rows = SecretarySkill::query()
            ->whereIn('secretary_id', array_values(array_unique($secretaryIds)))
            ->orderBy('secretary_id')
            ->orderBy('skill_key')
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (SecretarySkill $skill): string => "{$skill->secretary_id}:{$skill->skill_key}");
        $updates = [];
        $awarded = 0;
        $levelsGained = 0;
        $now = now();
        foreach ($awards as $nationId => $skillAwards) {
            $secretaryId = $context->state->secretarySnapshot($nationId)['secretary_id'];
            foreach ($skillAwards as $skillKey => $amount) {
                $row = $rows->get("{$secretaryId}:{$skillKey}");
                if (! $row instanceof SecretarySkill) {
                    throw new DomainException("Secretary {$secretaryId} is missing awarded skill {$skillKey}.");
                }
                $result = $this->progression->advance(
                    $this->catalog->definition($context->ruleset->settings, $skillKey),
                    (int) $row->level,
                    (int) $row->experience,
                    $amount,
                );
                $updates[] = [
                    'secretary_id' => $secretaryId,
                    'skill_key' => $skillKey,
                    'level' => $result['level'],
                    'experience' => $result['experience'],
                    'created_at' => $row->created_at,
                    'updated_at' => $now,
                ];
                $awarded += $amount;
                $levelsGained += $result['levels_gained'];
            }
        }
        SecretarySkill::query()->upsert(
            $updates,
            ['secretary_id', 'skill_key'],
            ['level', 'experience', 'updated_at'],
        );
        $context->state->markSecretaryExperienceFlushed();

        return [
            'experience_awarded' => $awarded,
            'skills_changed' => count($updates),
            'levels_gained' => $levelsGained,
        ];
    }
}
