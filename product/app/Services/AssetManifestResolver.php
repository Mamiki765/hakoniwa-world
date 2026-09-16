<?php

namespace App\Services;

use App\Domain\Secretary\SecretaryProfileContract;
use Illuminate\Support\Facades\Log;
use JsonException;

final class AssetManifestResolver
{
    /** @var array<string, mixed>|null */
    private ?array $sceneManifest = null;

    /** @var array<string, string> */
    private const MANIFEST = [
        'tile.sea' => 'land0.gif',
        'tile.shallow' => 'land14.gif',
        'tile.wasteland' => 'land1.gif',
        'tile.scorched' => 'land13.gif',
        'tile.plain' => 'land2.gif',
        'tile.forest' => 'land6.gif',
        'tile.mountain' => 'land11.gif',
        'tile.village' => 'land3.gif',
        'tile.town' => 'land4.gif',
        'tile.city' => 'land5.gif',
        'tile.capital' => 'capital.gif',
        'tile.farm' => 'land7.gif',
        'tile.factory' => 'land8.gif',
        'tile.mine' => 'land15.gif',
        'tile.large_farm' => 'Land702.gif',
        'tile.large_factory' => 'KLand47.gif',
        'tile.large_mine' => 'land19.gif',
        'tile.missile_base' => 'land9.gif',
        'tile.seabed_oil_field' => 'land16.gif',
        'tile.defense' => 'land10.gif',
        'tile.seabed_base' => 'seabed-base.png',
        'tile.undersea_city' => 'undersea-city.gif',
        'tile.port' => 'port.gif',
        'tile.central_bank' => 'central-bank.gif',
        'tile.central_granary' => 'central-granary.gif',
        'tile.monument' => 'monument0.gif',
        'tile.decoy' => 'land10.gif',
        'ship.fishing' => 'ship-fishing.gif',
        'ship.tourist' => 'ship-tourist.gif',
        'ship.exploration' => 'ship-exploration.gif',
        'ship.pirate' => 'ship-pirate.gif',
        'ship.treasure' => 'ship-treasure.gif',
        'ship.warship' => 'ship-warship.gif',
        'map.buried_treasure.sparkle' => 'buried-treasure-sparkle.gif',
        'tile.monument.peace' => 'monument0.gif',
        'tile.monument.prosperity' => 'monument0.gif',
        'tile.monument.victory' => 'monument0.gif',
        'overlay.ownership' => 'ownership.png',
        'overlay.border' => 'border.png',
        'overlay.command_target' => 'command-target.png',
        'overlay.selection' => 'selection.png',
        'overlay.damage' => 'damage.webp',
        'hakoniwa_original.monster.mecha_inora' => 'monster7.gif',
        'hakoniwa_original.monster.inora' => 'monster0.gif',
        'hakoniwa_original.monster.sanjira' => 'monster5.gif',
        'hakoniwa_original.monster.red_inora' => 'monster1.gif',
        'hakoniwa_original.monster.dark_inora' => 'monster2.gif',
        'hakoniwa_original.monster.inora_ghost' => 'monster8.gif',
        'hakoniwa_original.monster.kujira' => 'monster6.gif',
        'hakoniwa_original.monster.king_inora' => 'monster3.gif',
        'hakoniwa_original.monster.hardened' => 'monster4.gif',
        'hakoniwa_custom.monster.aoi_inora' => 'monster-aoi-inora.gif',
        'hakoniwa_custom.monster.mecha_inora_zero' => 'monster-mecha-inora-zero.gif',
        'hakoniwa_custom.monster.nyowamiya' => 'monsnyowa.gif',
        'award.turn' => 'prize0.gif',
        'award.prosperity' => 'prize1.gif',
        'award.prosperity_great' => 'prize2.gif',
        'award.prosperity_ultimate' => 'prize3.gif',
        'award.peace' => 'prize4.gif',
        'award.peace_great' => 'prize5.gif',
        'award.peace_ultimate' => 'prize6.gif',
        'award.calamity' => 'prize7.gif',
        'award.calamity_great' => 'prize8.gif',
        'award.calamity_ultimate' => 'prize9.gif',
        'award.monster_turn' => 'prize10.gif',
        // Compatibility aliases for worlds initialized by the MVP migration.
        'hakoniwa_original.sea' => 'land0.gif',
        'hakoniwa_original.shallow' => 'land14.gif',
        'hakoniwa_original.wasteland' => 'land1.gif',
        'hakoniwa_original.plain' => 'land2.gif',
        'hakoniwa_original.forest' => 'land6.gif',
        'hakoniwa_original.mountain' => 'land11.gif',
        'hakoniwa_original.village' => 'land3.gif',
        'hakoniwa_original.missile_base' => 'land9.gif',
    ];

    /** @var array<string, string> */
    private const SNOW_OVERRIDES = [
        'tile.wasteland' => 'land1.gif', 'tile.plain' => 'land2.gif',
        'tile.village' => 'land3.gif', 'tile.town' => 'land4.gif', 'tile.city' => 'land5.gif',
        'tile.forest' => 'land6.gif', 'tile.farm' => 'land7.gif', 'tile.factory' => 'land8.gif',
        'tile.missile_base' => 'land9.gif', 'tile.defense' => 'land10.gif', 'tile.decoy' => 'land10.gif',
        'tile.mountain' => 'land11.gif', 'tile.scorched' => 'land13.gif', 'tile.mine' => 'land15.gif',
        'tile.monument' => 'monument0.gif',
    ];

    /** @var array<string, string> */
    private const UNDERGROUND_ASSETS = [
        'underground.soil' => 'Ug.gif',
        'underground.entrance' => 'Ug_dokan.gif',
        'underground.ladder' => 'Ug_hasigo.gif',
        'underground.road' => 'Ug_road.gif',
        'underground.city' => 'Ug_tosi.gif',
        'underground.farm' => 'Ug_farm.gif',
        'underground.factory' => 'Ug_fact.gif',
        'underground.missile_base' => 'Ug_kiti.gif',
        'underground.oil' => 'Ug_oil.gif',
    ];

    /** @var array<string, string> */
    private const SECRETARY_FALLBACKS = [
        'peridot' => 'peridot.png',
        'peridot_full_body' => 'peridot-full-body.png',
        'silhouette' => 'silhouette.png',
    ];

    /** @var array<string, true> */
    private array $loggedFailures = [];

    /** @return array{key: string, url: ?string, available: bool, fallback_label: string, fallback_style: string} */
    public function resolve(string $assetKey, string $fallbackLabel, ?string $theme = null): array
    {
        $filename = self::MANIFEST[$assetKey] ?? null;
        $themeFilename = match ($theme) {
            'snow' => self::SNOW_OVERRIDES[$assetKey] ?? null,
            'underground' => self::UNDERGROUND_ASSETS[$assetKey] ?? null,
            default => null,
        };
        $themeDirectory = $theme === null ? null : config("hakoniwa.assets.themes.{$theme}");
        $themePath = is_string($themeFilename) && is_string($themeDirectory)
            ? $this->validatedPath($themeFilename, $themeDirectory)
            : null;
        $path = $themePath ?? ($filename === null ? null : $this->validatedPath($filename));
        $resolvedFilename = $themePath === null ? $filename : $themeDirectory.'/'.$themeFilename;

        if (($filename !== null || $themeFilename !== null)
            && $path === null && ! isset($this->loggedFailures[$assetKey])) {
            $this->loggedFailures[$assetKey] = true;
            Log::warning('Tile asset rejected; CSS fallback will be used.', [
                'asset_key' => $assetKey,
                'filename' => $themeFilename ?? $filename,
            ]);
        }

        return [
            'key' => $assetKey,
            'url' => $path === null || $resolvedFilename === null ? null : $this->versionedUrl($resolvedFilename, $path),
            'available' => $path !== null,
            'fallback_label' => $fallbackLabel,
            'fallback_style' => str_replace(['.', '_'], '-', $assetKey),
        ];
    }

    /**
     * @param  array<int, string>  $overlayAssetKeys
     * @return array{completed: array{key: string, url: ?string, available: bool, fallback_label: string, fallback_style: string}, overlays: array<int, array{key: string, url: ?string, available: bool, fallback_label: string, fallback_style: string}>}
     */
    public function resolveLayers(
        string $completedAssetKey,
        string $fallbackLabel,
        array $overlayAssetKeys = [],
        ?string $theme = null,
    ): array {
        return [
            'completed' => $this->resolve($completedAssetKey, $fallbackLabel, $theme),
            'overlays' => array_values(array_map(
                fn (string $key): array => $this->resolve($key, ''),
                $overlayAssetKeys,
            )),
        ];
    }

    public function pathForFilename(string $filename, ?string $theme = null): ?string
    {
        if (in_array($theme, ['background', 'npc', 'event'], true)) {
            foreach ($this->sceneAssets() as $asset) {
                if (($asset['file'] ?? null) === $theme.'/'.$filename) {
                    return $this->validatedPath($filename, $theme, false);
                }
            }

            return null;
        }
        if ($theme !== null) {
            $directory = config("hakoniwa.assets.themes.{$theme}");
            $allowedFilenames = match ($theme) {
                'snow' => self::SNOW_OVERRIDES,
                'underground' => self::UNDERGROUND_ASSETS,
                'peridot' => self::SECRETARY_FALLBACKS,
                default => [],
            };
            if (! is_string($directory) || ! in_array($filename, $allowedFilenames, true)) {
                return null;
            }

            return $theme === 'peridot'
                ? $this->validatedSecretaryFallbackPath($filename, $directory)
                : $this->validatedPath($filename, $directory);
        }
        if (! in_array($filename, self::MANIFEST, true)) {
            return null;
        }

        return $this->validatedPath($filename);
    }

    public function secretaryFallbackUrl(string $fallback, bool $large = false): ?string
    {
        $filename = self::SECRETARY_FALLBACKS[$fallback] ?? null;
        $directory = config('hakoniwa.assets.themes.peridot');
        if ($filename === null || ! is_string($directory)) {
            return null;
        }

        if ($large && $fallback === 'peridot') {
            $portraitFilename = self::SECRETARY_FALLBACKS['peridot_full_body'];
            $portraitPath = $this->validatedSecretaryFallbackPath($portraitFilename, $directory);
            if ($portraitPath !== null) {
                return $this->versionedUrl($directory.'/'.$portraitFilename, $portraitPath);
            }
        }

        $path = $this->validatedSecretaryFallbackPath($filename, $directory);
        if ($path === null) {
            return null;
        }

        return $this->versionedUrl($directory.'/'.$filename, $path);
    }

    public function filenameForAssetKey(string $assetKey): ?string
    {
        return self::MANIFEST[$assetKey] ?? null;
    }

    public function contentTypeForFilename(string $filename): string
    {
        return match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            'jpg', 'jpeg' => 'image/jpeg',
            default => 'image/gif',
        };
    }

    private function validatedPath(
        string $filename,
        ?string $directory = null,
        bool $requiresSquareDimensions = true,
    ): ?string {
        if (basename($filename) !== $filename || str_contains($filename, '..')) {
            return null;
        }
        if ($directory !== null && (basename($directory) !== $directory || str_contains($directory, '..'))) {
            return null;
        }

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $allowed = config('hakoniwa.assets.allowed_extensions', ['gif']);
        if (! is_array($allowed) || ! in_array($extension, $allowed, true)) {
            return null;
        }

        $relative = $directory === null ? $filename : $directory.DIRECTORY_SEPARATOR.$filename;
        $path = rtrim((string) config('hakoniwa.assets.path'), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$relative;
        if (! is_readable($path) || ! is_file($path)) {
            return null;
        }

        if (@filemtime($path) === false || @filesize($path) === false) {
            return null;
        }

        $image = @getimagesize($path);
        if ($image === false || ($requiresSquareDimensions && $image[0] !== $image[1])) {
            return null;
        }

        $allowedMimes = ['image/gif', 'image/png', 'image/webp', 'image/jpeg'];
        if (! in_array($image['mime'], $allowedMimes, true)) {
            return null;
        }

        return $path;
    }

    private function validatedSecretaryFallbackPath(string $filename, string $directory): ?string
    {
        $path = $this->validatedPath($filename, $directory, false);
        if ($path === null) {
            return null;
        }
        $image = @getimagesize($path);

        return $image !== false && $image['mime'] === 'image/png' ? $path : null;
    }

    private function versionedUrl(string $filename, string $path): string
    {
        $version = (string) filemtime($path).'-'.(string) filesize($path);

        return rtrim((string) config('hakoniwa.assets.base_url'), '/').'/'.$filename.'?v='.rawurlencode($version);
    }

    /** @return array<string, mixed> */
    private function readSceneManifest(): array
    {
        if ($this->sceneManifest !== null) {
            return $this->sceneManifest;
        }
        $path = rtrim((string) config('hakoniwa.assets.path'), '/\\').DIRECTORY_SEPARATOR.'scene-assets.json';
        if (! is_file($path) || ! is_readable($path) || filesize($path) > 1048576) {
            return $this->sceneManifest = [];
        }
        try {
            $contents = file_get_contents($path);
            $manifest = is_string($contents) ? json_decode($contents, true, 64, JSON_THROW_ON_ERROR) : null;
        } catch (JsonException) {
            $manifest = null;
        }

        return $this->sceneManifest = is_array($manifest) ? $manifest : [];
    }

    /** @return array<string, array<string, mixed>> */
    private function sceneAssets(): array
    {
        $registered = $this->readSceneManifest()['assets'] ?? [];
        $assets = [];
        if (! is_array($registered)) {
            return [];
        }
        foreach ($registered as $id => $asset) {
            if (! is_string($id) || ! is_array($asset) || ! is_string($asset['file'] ?? null)
                || preg_match('/\A(background|npc|event)\/[A-Za-z0-9_-]+\.(?:png|webp|jpg|jpeg)\z/', $asset['file']) !== 1
                || ! is_string($asset['creation_method'] ?? null)
                || ! array_key_exists($asset['creation_method'], SecretaryProfileContract::CREATION_METHODS)) {
                continue;
            }
            $assets[$id] = $asset;
        }

        return $assets;
    }

    /** @return array<string, mixed>|null */
    private function sceneAsset(mixed $id, bool $showAi): ?array
    {
        if (! is_string($id)) {
            return null;
        }
        $asset = $this->sceneAssets()[$id] ?? null;
        if ($asset === null || (! $showAi && $asset['creation_method'] === 'ai_generated')) {
            return null;
        }
        [$directory, $filename] = explode('/', $asset['file'], 2);
        $path = $this->validatedPath($filename, $directory, false);
        if ($path === null) {
            return null;
        }
        $link = $asset['credit_url'] ?? null;
        $link = is_string($link) && filter_var($link, FILTER_VALIDATE_URL)
            && in_array(parse_url($link, PHP_URL_SCHEME), ['http', 'https'], true) ? $link : null;

        return [
            'id' => $id,
            'url' => $this->versionedUrl($asset['file'], $path),
            'creation_method' => $asset['creation_method'],
            'credit' => is_string($asset['credit'] ?? null) ? $asset['credit'] : null,
            'credit_url' => $link,
            'show_credit' => ($asset['show_credit'] ?? false) === true,
        ];
    }

    /** @return array{x: float, y: float, height: float, pivot_x: float, pivot_y: float, layer: float} */
    private function scenePlacement(mixed $value): array
    {
        $input = is_array($value) ? $value : [];
        $result = [];
        foreach (['x' => 65, 'y' => 100, 'height' => 95, 'pivot_x' => 50, 'pivot_y' => 100, 'layer' => 1] as $key => $default) {
            $number = $input[$key] ?? $default;
            $result[$key] = is_numeric($number) && is_finite((float) $number)
                ? max($key === 'height' ? 1.0 : -200.0, min(300.0, (float) $number)) : (float) $default;
        }
        $result['layer'] = max(1.0, min(5.0, $result['layer']));

        return $result;
    }

    /** @return array{background: array<string, mixed>|null, still: array<string, mixed>|null, actors: list<array<string, mixed>>} */
    public function scene(string $key, bool $showAi, bool $showActors = true): array
    {
        $manifest = $this->readSceneManifest();
        $scenes = $manifest['scenes'] ?? [];
        $scene = is_array($scenes) && is_array($scenes[$key] ?? null) ? $scenes[$key] : [];
        $actors = [];
        if ($showActors && is_array($scene['actors'] ?? null)) {
            foreach ($scene['actors'] as $index => $actor) {
                if (! is_array($actor)) {
                    continue;
                }
                $asset = $this->sceneAsset($actor['asset'] ?? null, $showAi);
                if ($asset === null) {
                    continue;
                }
                $placement = $this->scenePlacement($actor['placement'] ?? null);
                $actors[] = [
                    'key' => (string) $index,
                    'name' => is_string($actor['name'] ?? null) ? $actor['name'] : '',
                    'asset' => $asset,
                    'placement' => $placement,
                    'mobile' => $this->scenePlacement([...$placement, ...(is_array($actor['mobile'] ?? null) ? $actor['mobile'] : [])]),
                ];
            }
        }

        return [
            'background' => $this->sceneAsset($scene['background'] ?? null, $showAi),
            'still' => $this->sceneAsset($scene['still'] ?? null, $showAi),
            'actors' => $actors,
        ];
    }
}
