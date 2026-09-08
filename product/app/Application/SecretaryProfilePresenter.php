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
        $targetOwner = $secretary->user;
        $viewerPreferencesConfigured = $viewer instanceof User
            && $viewer->show_ai_generated_secretary_images !== null
            && $viewer->secretary_image_fallback !== null;
        $image = $this->image(
            $secretary,
            $viewer,
            $viewerPreferencesConfigured,
            $targetOwner->secretary_image_fallback,
        );
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
            'images' => $this->images($secretary, $viewer, $viewerPreferencesConfigured),
            'editable_image_metadata' => $isOwner && $secretary->main_image_path !== null ? [
                'creation_method' => $secretary->main_image_creation_method,
                'credit' => $secretary->main_image_credit,
            ] : null,
            'viewer_preferences' => [
                'configured' => $viewerPreferencesConfigured,
                'show_ai_generated_images' => $viewerPreferencesConfigured
                    ? $viewer->show_ai_generated_secretary_images
                    : null,
                'own_secretary_fallback' => $viewerPreferencesConfigured
                    ? $viewer->secretary_image_fallback
                    : null,
                'fallback' => $viewerPreferencesConfigured ? $viewer->secretary_image_fallback : null,
                'can_update' => $viewer instanceof User,
            ],
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
    public function resolveCompactImage(Secretary $secretary, ?User $viewer = null, bool $awakening = false): array
    {
        $configured = $viewer instanceof User && $viewer->show_ai_generated_secretary_images !== null;
        $images = $this->images($secretary, $viewer, $configured);
        foreach (($awakening ? ['awakening_icon', 'icon'] : ['icon']) as $slot) {
            if (($images[$slot]['display'] ?? 'none') === 'uploaded') {
                return $images[$slot];
            }
        }

        return $this->fallbackImage((string) ($secretary->user->secretary_image_fallback ?? 'silhouette'));
    }

    /** @return array<string, mixed> */
    public function resolveLargeImage(Secretary $secretary, ?User $viewer = null, bool $awakening = false): array
    {
        $configured = $viewer instanceof User && $viewer->show_ai_generated_secretary_images !== null;
        $images = $this->images($secretary, $viewer, $configured);
        $preferred = $secretary->portrait_preference === 'bust' ? 'bust' : 'full_body';
        $other = $preferred === 'bust' ? 'full_body' : 'bust';
        $slots = $awakening ? ["awakening_{$preferred}", "awakening_{$other}", $preferred, $other] : [$preferred, $other];
        foreach ($slots as $slot) {
            if (($images[$slot]['display'] ?? 'none') === 'uploaded') {
                return $images[$slot];
            }
        }

        return $this->fallbackImage((string) ($secretary->user->secretary_image_fallback ?? 'silhouette'));
    }

    /** @return array<string, array<string, mixed>> */
    private function images(Secretary $secretary, ?User $viewer, bool $configured): array
    {
        $rows = $secretary->images->keyBy('slot');
        $result = [];
        foreach (SecretaryProfileContract::IMAGE_SLOTS as $slot) {
            $row = $rows->get($slot);
            if (! $row instanceof SecretaryImage && $slot === 'full_body' && $secretary->main_image_path !== null) {
                $result[$slot] = [
                    'slot' => $slot,
                    ...$this->image($secretary, $viewer, $configured, $secretary->user->secretary_image_fallback),
                ];

                continue;
            }
            $image = $row instanceof SecretaryImage
                ? $this->slotImage($row, $viewer, $configured)
                : $this->noImage();
            $result[$slot] = ['slot' => $slot, ...$image];
        }

        return $result;
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
    private function image(
        Secretary $secretary,
        ?User $viewer,
        bool $viewerPreferencesConfigured,
        ?string $targetOwnerFallback,
    ): array {
        if ($secretary->main_image_path === null) {
            return $viewerPreferencesConfigured && $viewer?->show_ai_generated_secretary_images === true
                ? $this->fallbackImage((string) $targetOwnerFallback)
                : $this->noImage();
        }
        if ($secretary->main_image_creation_method === 'ai_generated'
            && (! $viewerPreferencesConfigured || $viewer?->show_ai_generated_secretary_images !== true)) {
            return $this->noImage();
        }

        $baseUrl = rtrim((string) config('hakoniwa.secretary_profile.image_base_url'), '/');

        return [
            'display' => 'uploaded',
            'url' => $baseUrl.'/'.rawurlencode($secretary->main_image_path),
            'creation_method' => $secretary->main_image_creation_method,
            'creation_method_label' => SecretaryProfileContract::CREATION_METHODS[$secretary->main_image_creation_method] ?? null,
            'credit' => $secretary->main_image_credit,
        ];
    }

    /** @return array<string, mixed> */
    private function fallbackImage(string $fallback): array
    {
        $url = $this->assets->secretaryFallbackUrl($fallback);
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
