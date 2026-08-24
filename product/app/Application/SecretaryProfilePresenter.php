<?php

namespace App\Application;

use App\Domain\Secretary\SecretaryProfileContract;
use App\Domain\Secretary\SecretarySkillCatalog;
use App\Models\Secretary;
use App\Models\User;
use App\Services\AssetManifestResolver;
use DomainException;

final readonly class SecretaryProfilePresenter
{
    public function __construct(
        private SecretaryItemPresenter $items,
        private AssetManifestResolver $assets,
    ) {}

    /** @return array<string, mixed> */
    public function present(
        Secretary $secretary,
        ?User $viewer,
        ?SecretaryItemEffectProjection $projection = null,
    ): array {
        $secretary->loadMissing(['skills', 'itemInstances', 'user']);
        $skillRows = $secretary->skills->keyBy('skill_key');
        $actualKeys = $skillRows->keys()->sort()->values()->all();
        $expectedKeys = collect(SecretarySkillCatalog::KEYS)->sort()->values()->all();
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
            'is_owner' => $isOwner,
            'domestic_level' => $level,
            'secretary_level' => $level,
            'passive_level_total' => $level,
            'capacity_bonus_percent' => $level,
            'monster_experience' => (int) $secretary->monster_experience,
            'biography' => $secretary->profile_biography,
            'main_image' => $image,
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

    /** @return array<string, mixed> */
    private function image(
        Secretary $secretary,
        ?User $viewer,
        bool $viewerPreferencesConfigured,
        ?string $targetOwnerFallback,
    ): array {
        if (! $viewerPreferencesConfigured) {
            return $this->noImage();
        }
        if ($secretary->main_image_path === null) {
            return $viewer?->show_ai_generated_secretary_images === true
                ? $this->fallbackImage((string) $targetOwnerFallback)
                : $this->noImage();
        }
        if ($secretary->main_image_creation_method === 'ai_generated'
            && $viewer?->show_ai_generated_secretary_images !== true) {
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
