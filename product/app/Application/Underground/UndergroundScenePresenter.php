<?php

namespace App\Application\Underground;

use App\Application\SecretaryProfilePresenter;
use App\Models\Secretary;
use App\Models\UndergroundProfile;
use App\Models\UndergroundTrialProgress;
use App\Models\User;
use App\Services\AssetManifestResolver;

final readonly class UndergroundScenePresenter
{
    public function __construct(
        private AssetManifestResolver $assets,
        private SecretaryProfilePresenter $portraits,
        private UndergroundAlphaV1PlayerCatalog $catalog,
    ) {}

    /** @return list<array<string, mixed>> */
    public function homeBackgrounds(UndergroundProfile $profile, bool $showAi): array
    {
        $options = [['key' => 'placeholder', 'name' => '水晶の洞窟', 'asset' => null]];
        $clearedTrials = UndergroundTrialProgress::query()->where('underground_profile_id', $profile->id)
            ->whereNotNull('first_cleared_at')->pluck('trial_key')->all();
        $areas = [];
        foreach ($this->catalog->explorationHuntingGrounds() as $ground) {
            if ($ground['required_trial_key'] === null || in_array($ground['required_trial_key'], $clearedTrials, true)) {
                $areas['hunting_ground.'.$ground['key']] = $ground['name'];
            }
        }
        foreach ($areas as $key => $name) {
            $background = $this->assets->scene($key, $showAi, false)['background'];
            if ($background !== null) {
                $options[] = ['key' => $key, 'name' => $name, 'asset' => $background];
            }
        }
        if (in_array('trial_03', $clearedTrials, true)) {
            $background = $this->assets->scene('trial_03.twilight_castle', $showAi, false)['background'];
            if ($background !== null) {
                $options[] = ['key' => 'trial_03.twilight_castle', 'name' => '黄昏の王城', 'asset' => $background];
            }
        }

        return $options;
    }

    /** @return array<string, mixed> */
    public function forProfile(Secretary $secretary, UndergroundProfile $profile): array
    {
        $viewer = $secretary->user;
        $showAi = ($this->portraits->viewerPreferences($viewer)['show_ai_generated_images'] ?? false) === true;
        $options = $this->homeBackgrounds($profile, $showAi);
        $selected = $profile->home_background_key ?? 'hunting_ground.'.$this->catalog->explorationHuntingGroundKey();
        $background = null;
        foreach ($options as $option) {
            if ($option['key'] === $selected) {
                $background = $option['asset'];
            }
        }
        $scenes = [];
        foreach (['adventure', 'character', 'shop', 'exchange', 'villa', 'exchange-intro', 'mirror'] as $key) {
            $visible = $key !== 'shop' || $profile->mirror_purchased_at !== null;
            $scenes[$key] = $key === 'mirror' && $profile->mirror_purchased_at === null
                ? ['background' => null, 'actors' => []]
                : $this->assets->scene($key, $showAi, $visible);
        }
        $scenes['home'] = ['background' => $background, 'actors' => []];
        $scenes['otherworld-intro'] = [
            'background' => $this->assets->scene('hunting_ground.shining_kingdom', $showAi, false)['background'],
            'actors' => $scenes['shop']['actors'],
        ];
        $scenes['yunagi-harbor-intro'] = [
            'background' => $this->assets->scene('hunting_ground.yunagi_harbor', $showAi, false)['background'],
            'actors' => $scenes['shop']['actors'],
        ];
        $scenes['yunagi-harbor-mad-moon'] = $this->assets->scene('yunagi-harbor-mad-moon', $showAi);
        $scenes['yunagi-harbor-mad-moon']['background'] = $scenes['yunagi-harbor-intro']['background'];
        $scenes['yunagi-harbor-mad-moon']['actors'] = [...$scenes['shop']['actors'], ...$scenes['yunagi-harbor-mad-moon']['actors']];
        foreach ($scenes['yunagi-harbor-mad-moon']['actors'] as $index => &$actor) {
            $actor['key'] = (string) $index;
        }
        unset($actor);
        $scenes['yunagi-harbor-mad-moon-victory'] = $scenes['yunagi-harbor-intro'];

        return [
            'display_name' => $this->portraits->battleDisplayName($secretary),
            'icon_url' => $this->portraits->resolveCompactImage($secretary, $viewer)['url'] ?? null,
            'show_ai' => $showAi,
            'scenes' => $scenes,
            'home_backgrounds' => $options,
            'home_background_key' => $selected,
            'portrait' => $this->portrait($secretary, $viewer, false),
            'awakened_portrait' => $this->portrait($secretary, $viewer, true),
        ];
    }

    /** @return array<string, mixed> */
    public function madMoonIntroduction(Secretary $secretary, UndergroundProfile $profile): array
    {
        $visuals = $this->forProfile($secretary, $profile);

        return $visuals['scenes']['yunagi-harbor-mad-moon'];
    }

    /** @return array<string, mixed>|null */
    private function portrait(Secretary $secretary, User $viewer, bool $awakened): ?array
    {
        $image = $this->portraits->resolveLargeImage($secretary, $viewer, $awakened);
        if (! is_string($image['url'] ?? null)) {
            return null;
        }

        return [
            'id' => $awakened ? 'self-awakened' : 'self',
            'url' => $image['url'],
            'creation_method' => $image['creation_method'],
            'show_credit' => false,
        ];
    }
}
