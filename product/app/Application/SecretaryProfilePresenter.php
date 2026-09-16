<?php

namespace App\Application;

use App\Domain\Secretary\SecretaryProfileContract;
use App\Domain\Secretary\SecretarySkillCatalog;
use App\Models\Secretary;
use App\Models\SecretaryImage;
use App\Models\User;
use App\Services\AssetManifestResolver;
use DomainException;

final readonly class SecretaryProfilePresenter
{
    public function __construct(
        private SecretaryItemPresenter $items,
        private AssetManifestResolver $assets,
        private SecretarySkillCatalog $catalog,
    ) {}

    /** @return array<string, mixed> */
    public function present(
        Secretary $secretary,
        ?User $viewer,
        ?SecretaryItemEffectProjection $projection = null,
    ): array {
        $secretary->loadMissing(['skills', 'itemInstances', 'user', 'undergroundProfile', 'images']);
        $skillRows = $secretary->skills->keyBy('skill_key');
        $actualKeys = $skillRows->keys()->sort()->values()->all();
        $ruleset = config('hakoniwa.ruleset');
        if (! is_array($ruleset)) {
            throw new DomainException('The current immutable Secretary ruleset contract is missing.');
        }
        $expectedKeys = collect(array_keys($this->catalog->definitions($ruleset)))->sort()->values()->all();
        if ($actualKeys !== $expectedKeys) {
            throw new DomainException("Secretary {$secretary->id} has an invalid passive skill catalog.");
        }
        $level = (int) $skillRows->sum('level');
        $isOwner = $viewer instanceof User && (int) $viewer->id === (int) $secretary->user_id;
        $viewerPreferencesConfigured = $this->viewerPreferencesConfigured($viewer);
        $image = $this->resolveLargeImage($secretary, $viewer);
        $equipment = $this->items->present($secretary, $projection)['equipment'];

        return [
            'id' => $secretary->id,
            'name' => $secretary->name,
            'nickname' => $secretary->nickname,
            'battle_display_name' => $this->battleDisplayName($secretary),
            'portrait_preference' => $secretary->portrait_preference ?? 'full_body',
            'is_owner' => $isOwner,
            'domestic_level' => $level,
            'secretary_level' => $level,
            'passive_level_total' => $level,
            'capacity_bonus_percent' => $level,
            'monster_experience' => (int) $secretary->monster_experience,
            'combat_level' => $secretary->undergroundProfile?->combat_level,
            'biography' => $secretary->profile_biography,
            'main_image' => $image,
            'images' => $this->images($secretary, $viewer, $viewerPreferencesConfigured, $isOwner),
            'editable_image_metadata' => null,
            'viewer_preferences' => $this->viewerPreferences($viewer),
            'equipment' => $equipment,
        ];
    }

    public function battleDisplayName(Secretary $secretary): string
    {
        if (is_string($secretary->nickname) && $secretary->nickname !== '') {
            return $secretary->nickname;
        }
        $name = $secretary->name ?? '？？？';

        return mb_strlen($name) <= 6 ? $name : mb_substr($name, 0, 5).'…';
    }

    /** @return array<string, mixed> */
    public function viewerPreferences(?User $viewer): array
    {
        $configured = $this->viewerPreferencesConfigured($viewer);

        return [
            'configured' => $configured,
            'show_ai_generated_images' => $configured ? $viewer->show_ai_generated_secretary_images : null,
            'own_secretary_fallback' => $configured ? $viewer->secretary_image_fallback : null,
            'fallback' => $configured ? $viewer->secretary_image_fallback : null,
            'can_update' => $viewer instanceof User,
        ];
    }

    /** @return array<string, mixed> */
    public function resolveCompactImage(Secretary $secretary, ?User $viewer = null, bool $awakening = false): array
    {
        $configured = $this->viewerPreferencesConfigured($viewer);
        $images = $this->images($secretary, $viewer, $configured, false, true);
        foreach (($awakening ? ['awakening_icon', 'icon'] : ['icon']) as $slot) {
            if (($images[$slot]['display'] ?? 'none') === 'uploaded') {
                return $images[$slot];
            }
        }

        if (! $this->viewerMaySeeFallback($viewer)) {
            return $this->noImage();
        }

        return $this->fallbackImage((string) ($secretary->user->secretary_image_fallback ?? 'silhouette'));
    }

    /** @return array<string, mixed> */
    public function resolveLargeImage(Secretary $secretary, ?User $viewer = null, bool $awakening = false): array
    {
        $configured = $this->viewerPreferencesConfigured($viewer);
        $images = $this->images($secretary, $viewer, $configured, false, true);
        $preferred = $secretary->portrait_preference === 'bust' ? 'bust' : 'full_body';
        $other = $preferred === 'bust' ? 'full_body' : 'bust';
        $slots = $awakening ? ["awakening_{$preferred}", "awakening_{$other}", $preferred, $other] : [$preferred, $other];
        foreach ($slots as $slot) {
            if (($images[$slot]['display'] ?? 'none') === 'uploaded') {
                return $images[$slot];
            }
        }

        if (! $this->viewerMaySeeFallback($viewer)) {
            return $this->noImage();
        }

        return $this->fallbackImage((string) ($secretary->user->secretary_image_fallback ?? 'silhouette'), true);
    }

    /**
     * Apply the current viewer's image policy to a saved battle reference.
     * Historical metadata remains intact when the image is allowed; hidden
     * references are replaced by the same empty shape used by profile views.
     *
     * @param  array<string, mixed>  $reference
     * @return array<string, mixed>
     */
    public function filterSavedImageReference(array $reference, ?User $viewer = null): array
    {
        $creationMethod = $reference['creation_method'] ?? null;
        if ($creationMethod === 'ai_generated' && ! $this->viewerMaySeeAiImages($viewer)) {
            return $this->noImage();
        }
        if (in_array($reference['display'] ?? null, ['silhouette', 'peridot'], true)
            && ! $this->viewerMaySeeFallback($viewer)) {
            return $this->noImage();
        }

        unset($reference['path']);

        return $reference;
    }

    /**
     * Filter the explicitly stored image-reference containers in a battle
     * snapshot. This is intentionally shallow: combat state and old log data
     * are never inferred from the current Secretary profile.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public function filterSavedBattleImages(array $snapshot, ?User $viewer = null): array
    {
        foreach (['player_image_references', 'image_references'] as $key) {
            if (is_array($snapshot[$key] ?? null)) {
                $snapshot[$key] = $this->filterSavedImageReferences($snapshot[$key], $viewer);
            }
        }
        if (is_array($snapshot['party'] ?? null)) {
            $party = $snapshot['party'];
            if (is_array($party['members'] ?? null)) {
                foreach ($party['members'] as $id => $member) {
                    if (is_array($member) && is_array($member['image_references'] ?? null)) {
                        $member['image_references'] = $this->filterSavedImageReferences($member['image_references'], $viewer);
                        $party['members'][$id] = $member;
                    }
                }
            }
            $snapshot['party'] = $party;
        }
        if (is_array($snapshot['portrait_events'] ?? null)) {
            foreach ($snapshot['portrait_events'] as $index => $event) {
                if (! is_array($event)) {
                    continue;
                }
                if (is_array($event['image_refs'] ?? null)) {
                    $event['image_refs'] = $this->filterSavedImageReferences($event['image_refs'], $viewer);
                }
                if (is_array($event['image_ref'] ?? null)) {
                    $event['image_ref'] = $this->filterSavedImageReference($event['image_ref'], $viewer);
                }
                $snapshot['portrait_events'][$index] = $event;
            }
        }

        return $snapshot;
    }

    /**
     * @param  array<string, mixed>  $references
     * @return array<string, mixed>
     */
    private function filterSavedImageReferences(array $references, ?User $viewer): array
    {
        foreach ($references as $key => $reference) {
            if (is_array($reference)) {
                $references[$key] = $this->filterSavedImageReference($reference, $viewer);
            }
        }

        return $references;
    }

    private function viewerMaySeeAiImages(?User $viewer): bool
    {
        return $this->viewerPreferencesConfigured($viewer)
            && $viewer->show_ai_generated_secretary_images;
    }

    private function viewerMaySeeFallback(?User $viewer): bool
    {
        return $this->viewerMaySeeAiImages($viewer);
    }

    private function viewerPreferencesConfigured(?User $viewer): bool
    {
        return $viewer instanceof User
            && $viewer->show_ai_generated_secretary_images !== null
            && $viewer->secretary_image_fallback !== null;
    }

    /** @return array<string, array<string, mixed>> */
    private function images(
        Secretary $secretary,
        ?User $viewer,
        bool $configured,
        bool $isOwner = false,
        bool $includeInternalPath = false,
    ): array {
        $rows = $secretary->images->keyBy('slot');
        $result = [];
        foreach (SecretaryProfileContract::IMAGE_SLOTS as $slot) {
            $row = $rows->get($slot);
            $image = $row instanceof SecretaryImage
                ? $this->slotImage($row, $viewer, $configured)
                : $this->noImage();
            $entry = ['slot' => $slot, ...$image];
            if ($includeInternalPath && $row instanceof SecretaryImage && ($entry['display'] ?? null) === 'uploaded') {
                $entry['path'] = $row->path;
            }
            if ($isOwner && $row instanceof SecretaryImage) {
                $entry['source'] = 'slot';
                $entry['editable_metadata'] = $this->editableImageMetadata($row->creation_method, $row->credit);
            }
            $result[$slot] = $entry;
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function editableImageMetadata(?string $creationMethod, ?string $credit): array
    {
        return [
            'creation_method' => $creationMethod,
            'creation_method_label' => $creationMethod === null
                ? null
                : (SecretaryProfileContract::CREATION_METHODS[$creationMethod] ?? null),
            'credit' => $credit,
        ];
    }

    /** @return array<string, mixed> */
    private function slotImage(SecretaryImage $row, ?User $viewer, bool $configured): array
    {
        if ($row->creation_method === 'ai_generated'
            && (! $configured || $viewer?->show_ai_generated_secretary_images !== true)) {
            return $this->noImage();
        }
        $baseUrl = rtrim((string) config('hakoniwa.secretary_profile.image_base_url'), '/');

        return [
            'display' => 'uploaded', 'url' => $baseUrl.'/'.rawurlencode($row->path),
            'creation_method' => $row->creation_method,
            'creation_method_label' => SecretaryProfileContract::CREATION_METHODS[$row->creation_method] ?? null,
            'credit' => $row->credit,
        ];
    }

    /** @return array<string, mixed> */
    private function fallbackImage(string $fallback, bool $large = false): array
    {
        $url = $this->assets->secretaryFallbackUrl($fallback, $large);
        if ($url === null) {
            return $this->noImage();
        }

        return match ($fallback) {
            'silhouette' => [
                'display' => 'silhouette',
                'url' => $url,
                'creation_method' => null,
                'creation_method_label' => null,
                'credit' => null,
            ],
            'peridot' => [
                'display' => 'peridot',
                'url' => $url,
                'creation_method' => null,
                'creation_method_label' => null,
                'credit' => null,
            ],
            default => $this->noImage(),
        };
    }

    /** @return array<string, mixed> */
    private function noImage(): array
    {
        return [
            'display' => 'none',
            'url' => null,
            'creation_method' => null,
            'creation_method_label' => null,
            'credit' => null,
        ];
    }
}
