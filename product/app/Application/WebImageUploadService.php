<?php

namespace App\Application;

use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

final class WebImageUploadService
{
    /** @return array{path: string, mime_type: string} */
    public function store(UploadedFile $image, string $disk): array
    {
        $mimeType = $image->getMimeType();
        $extension = match ($mimeType) {
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => throw new DomainException('画像のMIME typeが許可されていません。'),
        };
        $path = bin2hex(random_bytes(32)).'.'.$extension;
        if (Storage::disk($disk)->putFileAs('', $image, $path) !== $path) {
            throw new DomainException('画像を保存できませんでした。');
        }

        return ['path' => $path, 'mime_type' => $mimeType];
    }
}
