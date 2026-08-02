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
        'tile.plain' => 'land2.gif',
        'tile.forest' => 'land6.gif',
        'tile.mountain' => 'land11.gif',
        'tile.village' => 'land3.gif',
        'tile.capital' => 'capital.png',
        'tile.farm' => 'land7.gif',
        'tile.factory' => 'land8.gif',
        'tile.mine' => 'land15.gif',
        'tile.missile_base' => 'land9.gif',
        'tile.seabed_oil_field' => 'land16.gif',
        'overlay.ownership' => 'ownership.png',
        'overlay.border' => 'border.png',
        'overlay.command_target' => 'command-target.png',
        'overlay.selection' => 'selection.png',
        'overlay.damage' => 'damage.webp',
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

    /** @var array<string, true> */
    private array $loggedFailures = [];

    /** @return array{key: string, url: ?string, available: bool, fallback_label: string, fallback_style: string} */
    public function resolve(string $assetKey, string $fallbackLabel): array
    {
        $filename = self::MANIFEST[$assetKey] ?? null;
        $path = $filename === null ? null : $this->validatedPath($filename);

        if ($filename !== null && $path === null && ! isset($this->loggedFailures[$assetKey])) {
            $this->loggedFailures[$assetKey] = true;
            Log::warning('Tile asset rejected; CSS fallback will be used.', ['asset_key' => $assetKey, 'filename' => $filename]);
        }

        return [
            'key' => $assetKey,
            'url' => $path === null ? null : $this->versionedUrl($filename, $path),
            'available' => $path !== null,
            'fallback_label' => $fallbackLabel,
            'fallback_style' => str_replace(['.', '_'], '-', $assetKey),
        ];
    }

    /**
     * @param  array<int, string>  $overlayAssetKeys
     * @return array{completed: array{key: string, url: ?string, available: bool, fallback_label: string, fallback_style: string}, overlays: array<int, array{key: string, url: ?string, available: bool, fallback_label: string, fallback_style: string}>}
     */
    public function resolveLayers(string $completedAssetKey, string $fallbackLabel, array $overlayAssetKeys = []): array
    {
        return [
            'completed' => $this->resolve($completedAssetKey, $fallbackLabel),
            'overlays' => array_values(array_map(
                fn (string $key): array => $this->resolve($key, ''),
                $overlayAssetKeys,
            )),
        ];
    }

    public function pathForFilename(string $filename): ?string
    {
        if (! in_array($filename, self::MANIFEST, true)) {
            return null;
        }

        return $this->validatedPath($filename);
    }

    public function contentTypeForFilename(string $filename): string
    {
        return match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'image/gif',
        };
    }

    private function validatedPath(string $filename): ?string
    {
        if (basename($filename) !== $filename || str_contains($filename, '..')) {
            return null;
        }

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $allowed = config('hakoniwa.assets.allowed_extensions', ['gif']);
        if (! is_array($allowed) || ! in_array($extension, $allowed, true)) {
            return null;
        }

        $path = rtrim((string) config('hakoniwa.assets.path'), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$filename;
        if (! is_readable($path) || ! is_file($path)) {
            return null;
        }

        if (@filemtime($path) === false || @filesize($path) === false) {
            return null;
        }

        $image = @getimagesize($path);
        if ($image === false || $image[0] !== $image[1]) {
            return null;
        }

        $allowedMimes = ['image/gif', 'image/png', 'image/webp'];
        if (! in_array($image['mime'], $allowedMimes, true)) {
            return null;
        }

        return $path;
    }

    private function versionedUrl(string $filename, string $path): string
    {
        $version = (string) filemtime($path).'-'.(string) filesize($path);

        return rtrim((string) config('hakoniwa.assets.base_url'), '/').'/'.$filename.'?v='.rawurlencode($version);
    }
}
