<?php

namespace App\Application;

use App\Domain\Secretary\SecretaryNotFoundException;
use App\Domain\Secretary\SecretaryProfileContract;
use App\Models\Secretary;
use App\Models\SecretaryImage;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

final readonly class SecretaryProfileService
{
    private const IMAGE_DISK = 'secretary_images';

    public function __construct(
        private SecretaryProfileContract $contract,
        private WebImageUploadService $images,
    ) {}

    public function updateBiography(User $user, string $biography, ?string $nickname = null, bool $nicknameProvided = false): Secretary
    {
        $biography = $this->contract->biography($biography);
        $nickname = $this->contract->nickname($nickname);

        return DB::transaction(function () use ($user, $biography, $nickname, $nicknameProvided): Secretary {
            $secretary = $this->lockSecretary($user);
            $values = ['profile_biography' => $biography];
            if ($nicknameProvided) {
                $values['nickname'] = $nickname;
            }
            $secretary->update($values);
            $this->audit($user, $secretary, 'secretary.profile_updated', [
                'biography_length' => mb_strlen($biography),
                'nickname_updated' => $nicknameProvided,
                'nickname_length' => $nicknameProvided && $nickname !== null ? mb_strlen($nickname) : null,
            ]);

            return $secretary->load(['skills', 'itemInstances']);
        }, 3);
    }

    public function replaceImage(User $user, string $slot, UploadedFile $image, string $creationMethod, ?string $credit): Secretary
    {
        $this->assertImageSlot($slot, $image);
        $creationMethod = $this->contract->creationMethod($creationMethod);
        $credit = $this->contract->credit($credit);
        if ($credit === null) {
            throw new \DomainException('画像ごとに作者・権利表記を入力してください。');
        }
        $stored = $this->images->store($image, self::IMAGE_DISK);
        $oldPath = null;
        try {
            $secretary = DB::transaction(function () use ($user, $slot, $creationMethod, $credit, $stored, &$oldPath): Secretary {
                $secretary = $this->lockSecretary($user);
                $existing = $secretary->images()->where('slot', $slot)->lockForUpdate()->first();
                $oldPath = $existing?->path;
                SecretaryImage::query()->updateOrCreate(
                    ['secretary_id' => $secretary->id, 'slot' => $slot],
                    [...$stored, 'creation_method' => $creationMethod, 'credit' => $credit, 'updated_at' => now()],
                );
                $this->audit($user, $secretary, 'secretary.image_slot_replaced', [
                    'slot' => $slot, 'creation_method' => $creationMethod, 'has_credit' => true,
                ]);

                return $secretary->load(['skills', 'itemInstances', 'images']);
            }, 3);
        } catch (Throwable $exception) {
            $this->deleteImageIfUnreferenced($stored['path']);
            throw $exception;
        }
        if (is_string($oldPath) && $oldPath !== $stored['path']) {
            $this->deleteImageIfUnreferenced($oldPath, $secretary, $stored['path'], $slot);
        }

        return $secretary;
    }

    public function updatePortraitPreference(User $user, string $preference): Secretary
    {
        $preference = $this->contract->portraitPreference($preference);

        return DB::transaction(function () use ($user, $preference): Secretary {
            $secretary = $this->lockSecretary($user);
            $secretary->update(['portrait_preference' => $preference]);
            $this->audit($user, $secretary, 'secretary.portrait_preference_updated', [
                'portrait_preference' => $preference,
            ]);

            return $secretary->load(['skills', 'itemInstances', 'images']);
        }, 3);
    }

    private function assertImageSlot(string $slot, UploadedFile $image): void
    {
        if (! in_array($slot, SecretaryProfileContract::IMAGE_SLOTS, true)) {
            throw new \DomainException('画像slotを確認してください。');
        }
        $size = @getimagesize($image->getRealPath());
        $width = is_array($size) ? $size[0] : 0;
        $height = is_array($size) ? $size[1] : 0;
        $square = in_array($slot, ['icon', 'awakening_icon'], true);
        if (($square && $width !== $height) || (! $square && $width * 4 !== $height * 3)) {
            throw new \DomainException($square ? 'icon画像は1:1で指定してください。' : 'portrait画像は3:4で指定してください。');
        }
    }

    public function replaceMainImage(
        User $user,
        UploadedFile $image,
        string $creationMethod,
        ?string $credit,
    ): Secretary {
        $creationMethod = $this->contract->creationMethod($creationMethod);
        $credit = $this->contract->credit($credit);
        $stored = $this->images->store($image, self::IMAGE_DISK);
        $oldPath = null;

        try {
            $secretary = DB::transaction(function () use (
                $user,
                $creationMethod,
                $credit,
                $stored,
                &$oldPath,
            ): Secretary {
                $secretary = $this->lockSecretary($user);
                $oldPath = $secretary->main_image_path;
                $secretary->update([
                    'main_image_path' => $stored['path'],
                    'main_image_mime_type' => $stored['mime_type'],
                    'main_image_creation_method' => $creationMethod,
                    'main_image_credit' => $credit,
                    'main_image_updated_at' => now(),
                ]);
                $this->audit($user, $secretary, 'secretary.main_image_replaced', [
                    'creation_method' => $creationMethod,
                    'has_credit' => $credit !== null,
                    'replaced_existing' => $oldPath !== null,
                ]);

                return $secretary->load(['skills', 'itemInstances']);
            }, 3);
        } catch (Throwable $exception) {
            $this->deleteImageIfUnreferenced($stored['path']);

            throw $exception;
        }

        if (is_string($oldPath) && $oldPath !== $stored['path']) {
            $this->deleteImageIfUnreferenced($oldPath, $secretary, $stored['path']);
        }

        return $secretary;
    }

    public function updateMainImageMetadata(
        User $user,
        string $creationMethod,
        ?string $credit,
    ): Secretary {
        $creationMethod = $this->contract->creationMethod($creationMethod);
        $credit = $this->contract->credit($credit);

        return DB::transaction(function () use ($user, $creationMethod, $credit): Secretary {
            $secretary = $this->lockSecretary($user);
            if ($secretary->main_image_path === null) {
                throw new SecretaryNotFoundException('metadataを更新できるメイン画像がありません。');
            }
            $secretary->update([
                'main_image_creation_method' => $creationMethod,
                'main_image_credit' => $credit,
            ]);
            $this->audit($user, $secretary, 'secretary.main_image_metadata_updated', [
                'creation_method' => $creationMethod,
                'has_credit' => $credit !== null,
            ]);

            return $secretary->load(['skills', 'itemInstances']);
        }, 3);
    }

    public function updateImagePreferences(User $user, bool $showAiImages, string $fallback): User
    {
        $fallback = $this->contract->fallback($fallback);

        return DB::transaction(function () use ($user, $showAiImages, $fallback): User {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $locked->forceFill([
                'show_ai_generated_secretary_images' => $showAiImages,
                'secretary_image_fallback' => $fallback,
            ])->save();
            $occurredAt = now();
            DB::table('audit_events')->insert([
                'actor_user_id' => $locked->id,
                'event_type' => 'user.secretary_image_preferences_updated',
                'severity' => 'info',
                'visibility' => 'private',
                'subject_type' => User::class,
                'subject_id' => $locked->id,
                'metadata' => json_encode([
                    'show_ai_generated_images' => $showAiImages,
                    'own_secretary_fallback' => $fallback,
                    'fallback' => $fallback,
                ], JSON_THROW_ON_ERROR),
                'occurred_at' => $occurredAt,
                'created_at' => $occurredAt,
                'updated_at' => $occurredAt,
            ]);

            return $locked;
        }, 3);
    }

    private function lockSecretary(User $user): Secretary
    {
        $secretary = Secretary::query()->where('user_id', $user->id)->lockForUpdate()->first();
        if (! $secretary instanceof Secretary) {
            throw new SecretaryNotFoundException('Secretaryが見つかりません。');
        }

        return $secretary;
    }

    private function deleteImageIfUnreferenced(
        string $path,
        ?Secretary $secretary = null,
        ?string $currentPath = null,
        ?string $slot = null,
    ): void {
        if (Secretary::query()->where('main_image_path', $path)->exists()
            || SecretaryImage::query()->where('path', $path)->exists()) {
            return;
        }
        try {
            if (Storage::disk(self::IMAGE_DISK)->delete($path)) {
                return;
            }
        } catch (Throwable $exception) {
            $message = $slot === null && $secretary !== null
                ? 'Secretary main image replacement left an orphaned previous file.'
                : 'Secretary image replacement left an orphaned previous file.';
            Log::error($message, [
                'secretary_id' => $secretary?->id,
                'old_path' => $path,
                'current_path' => $currentPath,
                'slot' => $slot,
                'exception_class' => $exception::class,
            ]);

            return;
        }

        $message = $slot === null && $secretary !== null
            ? 'Secretary main image replacement left an orphaned previous file.'
            : 'Secretary image replacement left an orphaned previous file.';
        Log::error($message, [
            'secretary_id' => $secretary?->id,
            'old_path' => $path,
            'current_path' => $currentPath,
            'slot' => $slot,
            'exception_class' => null,
        ]);
    }

    /** @param array<string, mixed> $metadata */
    private function audit(User $user, Secretary $secretary, string $eventType, array $metadata): void
    {
        $occurredAt = now();
        DB::table('audit_events')->insert([
            'actor_user_id' => $user->id,
            'event_type' => $eventType,
            'severity' => 'info',
            'visibility' => 'private',
            'subject_type' => Secretary::class,
            'subject_id' => $secretary->id,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'occurred_at' => $occurredAt,
            'created_at' => $occurredAt,
            'updated_at' => $occurredAt,
        ]);
    }
}
