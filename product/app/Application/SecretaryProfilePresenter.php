<?php

namespace App\Application;

use App\Domain\Secretary\SecretaryProfileContract;
use App\Domain\Secretary\SecretarySkillCatalog;
use App\Models\Secretary;
use App\Models\User;
use DomainException;

final readonly class SecretaryProfilePresenter
{
    public function __construct(private SecretaryItemPresenter $items) {}

    /** @return array<string, mixed> */
    public function present(
        Secretary $secretary,
        ?User $viewer,
        ?SecretaryItemEffectProjection $projection = null,
    ): array {
        $secretary->loadMissing(['skills', 'itemInstances']);
        $skillRows = $secretary->skills->keyBy('skill_key');
        $actualKeys = $skillRows->keys()->sort()->values()->all();
        $expectedKeys = collect(SecretarySkillCatalog::KEYS)->sort()->values()->all();
        if ($actualKeys !== $expectedKeys) {
            throw new DomainException("Secretary {$secretary->id} has an invalid passive skill catalog.");
        }
        $level = (int) $skillRows->sum('level');
        $isOwner = $viewer instanceof User && (int) $viewer->id === (int) $secretary->user_id;
        $preferencesConfigured = $viewer instanceof User
            && $viewer->show_ai_generated_secretary_images !== null
            && $viewer->secretary_image_fallback !== null;
        $image = $this->image($secretary, $viewer, $preferencesConfigured);
        $equipment = $this->items->present($secretary, $projection)['equipment'];

        return [
            'id' => $secretary->id,
            'name' => $secretary->name,
            'is_owner' => $isOwner,
            'secretary_level' => $level,
            'passive_level_total' => $level,
            'capacity_bonus_percent' => $level,
            'biography' => $secretary->profile_biography,
            'main_image' => $image,
            'editable_image_metadata' => $isOwner && $secretary->main_image_path !== null ? [
                'creation_method' => $secretary->main_image_creation_method,
                'credit' => $secretary->main_image_credit,
            ] : null,
            'viewer_preferences' => [
                'configured' => $preferencesConfigured,
                'show_ai_generated_images' => $preferencesConfigured
                    ? $viewer->show_ai_generated_secretary_images
                    : null,
                'fallback' => $preferencesConfigured ? $viewer->secretary_image_fallback : null,
                'can_update' => $viewer instanceof User,
            ],
            'equipment' => $equipment,
        ];
    }

    /** @return array<string, mixed> */
    private function image(Secretary $secretary, ?User $viewer, bool $preferencesConfigured): array
    {
        if ($secretary->main_image_path === null) {
            return $preferencesConfigured
                ? $this->fallbackImage((string) $viewer?->secretary_image_fallback)
                : $this->noImage();
        }
        if ($secretary->main_image_creation_method === 'ai_generated'
            && (! $preferencesConfigured || $viewer?->show_ai_generated_secretary_images !== true)) {
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
        return match ($fallback) {
            'silhouette' => [
                'display' => 'silhouette',
                'url' => '/assets/secretary/silhouette.svg',
                'creation_method' => null,
                'creation_method_label' => null,
                'credit' => null,
            ],
            'peridot' => [
                'display' => 'peridot',
                'url' => '/assets/secretary/peridot.svg',
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
