<?php

namespace App\Application;

use App\Domain\Secretary\SecretaryItemCatalog;
use App\Domain\Secretary\SecretaryItemGameplayContract;
use App\Domain\Secretary\SecretarySkillCatalog;
use App\Domain\Secretary\SecretarySkillProgression;
use App\Domain\Turn\TurnContext;
use App\Models\Nation;
use App\Models\NationMembership;
use App\Models\RulesetVersion;
use App\Models\Secretary;
use App\Models\SecretarySkill;
use DomainException;

final class SecretaryTurnService
{
    public function __construct(
        private readonly SecretarySkillCatalog $catalog,
        private readonly SecretarySkillProgression $progression,
        private readonly SecretaryItemCatalog $items,
        private readonly SecretaryItemGameplayContract $itemGameplay,
    ) {}

    public function itemEffectsEnabled(TurnContext $context): bool
    {
        return $this->itemGameplay->exists($context->ruleset->settings);
    }

    /** @param list<int> $nationIds */
    public function loadAttemptSnapshots(TurnContext $context, array $nationIds): int
    {
        if (! isset($context->ruleset->settings['secretary'])) {
            return 0;
        }
        $itemEffectsEnabled = $this->itemEffectsEnabled($context);
        $effectCatalog = $itemEffectsEnabled
            ? $this->itemGameplay->validatedEffectCatalog($context->ruleset->settings)
            : [];
        if ($nationIds === []) {
            return 0;
        }
        $relations = ['user.secretary.skills'];
        if ($itemEffectsEnabled) {
            $relations['user.secretary.itemInstances'] = static fn ($query) => $query
                ->whereNotNull('equipped_slot')
                ->where('is_escrowed', false)
                ->orderBy('secretary_id')
                ->orderBy('equipped_slot')
                ->orderBy('id');
        }
        $memberships = NationMembership::query()
            ->whereIn('nation_id', $nationIds)
            ->where('world_id', $context->world->id)
            ->where('role', 'owner')
            ->with($relations)
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
            $skills = $this->validatedSkillStates($secretary, $expected);
            $context->state->setSecretarySnapshot(
                $nationId,
                (int) $secretary->id,
                $secretary->name,
                (int) $secretary->monster_experience,
                $skills,
            );
            if ($itemEffectsEnabled) {
                $context->state->setSecretaryItemEffectSnapshot(
                    $nationId,
                    (int) $secretary->id,
                    (int) $secretary->equipment_version,
                    $this->resolvedItemSnapshots($secretary, $effectCatalog),
                );
            }
        }

        return count($nationIds);
    }

    /** @return array<string, int> */
    public function currentSkillLevels(Nation $nation, RulesetVersion $ruleset): array
    {
        if ((int) $nation->world()->value('ruleset_version_id') !== (int) $ruleset->id) {
            throw new DomainException('Secretary projection ruleset does not match the Nation World snapshot.');
        }
        if (! isset($ruleset->settings['secretary'])) {
            return [];
        }
        $membership = NationMembership::query()
            ->where('nation_id', $nation->id)
            ->where('world_id', $nation->world_id)
            ->where('role', 'owner')
            ->with('user.secretary.skills')
            ->first();
        $secretary = $membership?->user?->secretary;
        if (! $secretary instanceof Secretary) {
            throw new DomainException("Nation {$nation->id} is missing its User Secretary.");
        }
        $states = $this->validatedSkillStates(
            $secretary,
            $this->catalog->definitions($ruleset->settings),
        );

        return array_map(static fn (array $state): int => $state['level'], $states);
    }

    /**
     * @param  array<string, array<string, mixed>>  $expected
     * @return array<string, array{level: int, experience: int}>
     */
    private function validatedSkillStates(Secretary $secretary, array $expected): array
    {
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
            throw new DomainException("Secretary {$secretary->id} has an unexpected skill outside the active catalog.");
        }

        return $skills;
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $effectCatalog
     * @return list<array<string, mixed>>
     */
    private function resolvedItemSnapshots(Secretary $secretary, array $effectCatalog): array
    {
        if ((int) $secretary->equipment_version < 1) {
            throw new DomainException("Secretary {$secretary->id} has an invalid equipment version.");
        }
        $rows = $secretary->itemInstances;
        if ($rows->count() > 5) {
            throw new DomainException("Secretary {$secretary->id} exceeds the five equipment slots.");
        }
        $slots = [];
        $categoryCounts = [];
        $itemCounts = [];
        $snapshots = [];
        foreach ($rows as $row) {
            $definition = $this->items->definition($row->item_key);
            $slot = (int) $row->equipped_slot;
            $level = (int) $row->level;
            if ($slot < 1 || $slot > 5 || isset($slots[$slot])) {
                throw new DomainException("Secretary {$secretary->id} has invalid or duplicate equipped slots.");
            }
            if ($level < 1 || $level > $definition['max_level']) {
                throw new DomainException("Secretary Item {$row->id} has a level outside the global catalog.");
            }
            $slots[$slot] = true;
            $category = $definition['category'];
            $categoryCounts[$category] = ($categoryCounts[$category] ?? 0) + 1;
            $itemCounts[$row->item_key] = ($itemCounts[$row->item_key] ?? 0) + 1;
            if ($categoryCounts[$category] > $this->items->maximumEquipped($category)
                || $itemCounts[$row->item_key] > $this->items->sameItemMaximum($row->item_key)) {
                throw new DomainException("Secretary {$secretary->id} equipped Item limits are invalid.");
            }
            $snapshots[] = [
                'item_instance_id' => (int) $row->id,
                'item_key' => $row->item_key,
                'category' => $category,
                'level' => $level,
                'equipped_slot' => $slot,
                'effects' => $effectCatalog[$row->item_key]
                    ?? throw new DomainException("Ruleset Secretary item {$row->item_key} is missing."),
            ];
        }

        return $snapshots;
    }

    /** @return array{experience_awarded: int, skills_changed: int, levels_gained: int, monster_experience_awarded: int, monster_experience_secretaries_changed: int} */
    public function flushExperience(TurnContext $context): array
    {
        $skillAwards = $context->state->pendingSecretaryExperience();
        $monsterAwards = $context->state->pendingSecretaryMonsterExperience();
        if ($skillAwards === [] && $monsterAwards === []) {
            $context->state->markSecretaryExperienceFlushed();

            return [
                'experience_awarded' => 0,
                'skills_changed' => 0,
                'levels_gained' => 0,
                'monster_experience_awarded' => 0,
                'monster_experience_secretaries_changed' => 0,
            ];
        }
        $secretaryIds = [];
        foreach (array_unique([...array_keys($skillAwards), ...array_keys($monsterAwards)]) as $nationId) {
            $secretaryIds[] = $context->state->secretarySnapshot($nationId)['secretary_id'];
        }
        $secretaryIds = array_values(array_unique($secretaryIds));
        $secretaries = Secretary::query()->whereIn('id', $secretaryIds)
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        if ($secretaries->count() !== count($secretaryIds)) {
            throw new DomainException('A snapshotted Secretary disappeared before the final experience flush.');
        }
        $rows = collect();
        if ($skillAwards !== []) {
            $rows = SecretarySkill::query()
                ->whereIn('secretary_id', $secretaryIds)
                ->orderBy('secretary_id')
                ->orderBy('skill_key')
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (SecretarySkill $skill): string => "{$skill->secretary_id}:{$skill->skill_key}");
        }
        $updates = [];
        $awarded = 0;
        $levelsGained = 0;
        $now = now();
        foreach ($skillAwards as $nationId => $nationSkillAwards) {
            $secretaryId = $context->state->secretarySnapshot($nationId)['secretary_id'];
            foreach ($nationSkillAwards as $skillKey => $amount) {
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
        if ($updates !== []) {
            SecretarySkill::query()->upsert(
                $updates,
                ['secretary_id', 'skill_key'],
                ['level', 'experience', 'updated_at'],
            );
        }
        $monsterAwarded = 0;
        foreach ($monsterAwards as $nationId => $amount) {
            $secretaryId = $context->state->secretarySnapshot($nationId)['secretary_id'];
            $secretary = $secretaries->get($secretaryId);
            if (! $secretary instanceof Secretary) {
                throw new DomainException("Secretary {$secretaryId} is missing its monster experience row.");
            }
            $before = (int) $secretary->monster_experience;
            if ($before > PHP_INT_MAX - $amount) {
                throw new DomainException('Secretary monster experience exceeds the supported integer range.');
            }
            $secretary->monster_experience = $before + $amount;
            $secretary->save();
            $monsterAwarded += $amount;
        }
        $context->state->markSecretaryExperienceFlushed();

        return [
            'experience_awarded' => $awarded,
            'skills_changed' => count($updates),
            'levels_gained' => $levelsGained,
            'monster_experience_awarded' => $monsterAwarded,
            'monster_experience_secretaries_changed' => count($monsterAwards),
        ];
    }
}
