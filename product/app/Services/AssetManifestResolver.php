<?php

namespace App\Services;

final class AssetManifestResolver
{
    /** @var array<string, string> */
    private const MANIFEST = [
        'hakoniwa_original.sea' => 'land0.gif',
        'hakoniwa_original.shallow' => 'land14.gif',
        'hakoniwa_original.wasteland' => 'land1.gif',
        'hakoniwa_original.plain' => 'land2.gif',
        'hakoniwa_original.forest' => 'land6.gif',
        'hakoniwa_original.mountain' => 'land11.gif',
        'hakoniwa_original.village' => 'land3.gif',
        'hakoniwa_original.missile_base' => 'land9.gif',
    ];

    /** @return array{key: string, url: ?string, available: bool, fallback_label: string, fallback_style: string} */
    public function resolve(string $assetKey, string $fallbackLabel): array
    {
        $filename = self::MANIFEST[$assetKey] ?? null;
        $available = $filename !== null && $this->isValidGif($filename);

        return [
            'key' => $assetKey,
            'url' => $available ? rtrim((string) config('hakoniwa.assets.base_url'), '/').'/'.$filename : null,
            'available' => $available,
            'fallback_label' => $fallbackLabel,
            'fallback_style' => str_replace(['.', '_'], '-', $assetKey),
        ];
    }

    public function pathForFilename(string $filename): ?string
    {
        if (! in_array($filename, self::MANIFEST, true) || ! $this->isValidGif($filename)) {
            return null;
        }

        return rtrim((string) config('hakoniwa.assets.path'), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$filename;
    }

    private function isValidGif(string $filename): bool
    {
        if (basename($filename) !== $filename || ! str_ends_with(strtolower($filename), '.gif')) {
            return false;
        }

        $path = rtrim((string) config('hakoniwa.assets.path'), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$filename;

        if (! is_readable($path) || ! is_file($path)) {
            return false;
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $signature = fread($handle, 6);
        fclose($handle);

        return $signature === 'GIF87a' || $signature === 'GIF89a';
    }
}
