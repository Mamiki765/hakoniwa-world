<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

final class AssetManifestResolver
{
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
        'tile.missile_base' => 'land9.gif',
        'tile.seabed_oil_field' => 'land16.gif',
        'tile.defense' => 'land10.gif',
        'tile.seabed_base' => 'seabed-base.png',
        'tile.undersea_city' => 'undersea-city.gif',
        'tile.monument' => 'monument.png',
        'tile.decoy' => 'land10.gif',
        'tile.monument.peace' => 'monument-peace.png',
        'tile.monument.prosperity' => 'monument-prosperity.png',
        'tile.monument.victory' => 'monument-victory.png',
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

    public function secretaryFallbackUrl(string $fallback): ?string
    {
        $filename = self::SECRETARY_FALLBACKS[$fallback] ?? null;
        $directory = config('hakoniwa.assets.themes.peridot');
        if ($filename === null || ! is_string($directory)) {
            return null;
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

        $allowedMimes = ['image/gif', 'image/png', 'image/webp'];
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
}
