<?php

namespace App\Application;

use App\Models\User;
use App\Models\UserMonumentDesign;
use App\Services\AssetManifestResolver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

final readonly class UserMonumentDesignService
{
    private const IMAGE_DISK = 'monument_images';

    public function __construct(
        private WebImageUploadService $uploads,
        private AssetManifestResolver $assets,
    ) {}

    /** @return array{name: string, image_url: ?string, template_url: ?string} */
    public function present(User $user): array
    {
        $design = UserMonumentDesign::query()->where('user_id', $user->id)->first();

        return [
            'name' => $design->name ?? 'オリジナル記念碑',
            'image_url' => $design === null ? null : $this->imageUrl($design->image_path),
            'template_url' => $this->assets->resolve('tile.plain', '平地')['url'],
        ];
    }

    public function imageUrl(?string $path): ?string
    {
        if ($path === null || preg_match('/\A[0-9a-f]{64}\.gif\z/', $path) !== 1) {
            return null;
        }

        return rtrim((string) config('hakoniwa.monument_design.image_base_url'), '/').'/'.$path;
    }

    public function save(User $user, string $name, ?UploadedFile $image): UserMonumentDesign
    {
        $stored = $image === null ? null : $this->uploads->store($image, self::IMAGE_DISK);
        $oldPath = null;
        try {
            $design = DB::transaction(function () use ($user, $name, $stored, &$oldPath): UserMonumentDesign {
                User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                $design = UserMonumentDesign::query()->where('user_id', $user->id)->lockForUpdate()->first();
                if ($design === null) {
                    $design = UserMonumentDesign::query()->create(['user_id' => $user->id, 'name' => $name]);
                }
                $oldPath = $design->image_path;
                $design->name = $name;
                if ($stored !== null) {
                    $design->image_path = $stored['path'];
                }
                $design->save();

                return $design;
            }, 3);
        } catch (Throwable $exception) {
            if ($stored !== null) {
                try {
                    $this->deleteIfUnreferenced($stored['path']);
                } catch (Throwable $cleanupException) {
                    Log::error('A failed monument update left an unreferenced image.', [
                        'path' => $stored['path'],
                        'exception_class' => $cleanupException::class,
                    ]);
                }
            }
            throw $exception;
        }
        if ($stored !== null && $oldPath !== null && $oldPath !== $stored['path']) {
            $this->deleteIfUnreferenced($oldPath);
        }

        return $design;
    }

    private function deleteIfUnreferenced(string $path): void
    {
        if (preg_match('/\A[0-9a-f]{64}\.gif\z/', $path) !== 1
            || UserMonumentDesign::query()->where('image_path', $path)->exists()) {
            return;
        }
        try {
            if (! Storage::disk(self::IMAGE_DISK)->delete($path)) {
                Log::error('An unreferenced monument image could not be deleted.', ['path' => $path]);
            }
        } catch (Throwable $exception) {
            Log::error('An unreferenced monument image could not be deleted.', [
                'path' => $path,
                'exception_class' => $exception::class,
            ]);
        }
    }
}
