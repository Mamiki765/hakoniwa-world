<?php

namespace App\Http\Controllers;

use App\Services\AssetManifestResolver;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AssetController extends Controller
{
    public function __invoke(string $filename, AssetManifestResolver $assets): BinaryFileResponse
    {
        $path = $assets->pathForFilename($filename);
        abort_if($path === null, 404);

        return response()->file($path, [
            'Content-Type' => $assets->contentTypeForFilename($filename),
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
