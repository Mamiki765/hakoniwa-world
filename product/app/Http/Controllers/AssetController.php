<?php

namespace App\Http\Controllers;

use App\Services\AssetManifestResolver;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AssetController extends Controller
{
    public function __invoke(string $filename, AssetManifestResolver $assets, ?string $theme = null): BinaryFileResponse
    {
        if ($theme !== null) {
            [$theme, $filename] = [$filename, $theme];
        }
        $path = $assets->pathForFilename($filename, $theme);
        abort_if($path === null, 404);

        return response()->file($path, [
            'Content-Type' => $assets->contentTypeForFilename($filename),
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
