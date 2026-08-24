<?php

namespace App\Application;

use App\Domain\Secretary\SecretaryNotFoundException;
use App\Domain\Secretary\SecretaryProfileContract;
use App\Models\Secretary;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

final readonly class SecretaryProfileService
{
    private const IMAGE_DISK = 'secretary_images';

    public function __construct(
        private SecretaryProfileContract $contract,
        private WebImageUploadService $images,
    ) {}

    public function updateBiography(User $user, string $biography): Secretary
    {
        $biography = $this->contract->biography($biography);

        return DB::transaction(function () use ($user, $biography): Secretary {
            $secretary = $this->lockSecretary($user);
            $secretary->update(['profile_biography' => $biography]);
            $this->audit($user, $secretary, 'secretary.profile_updated', [
                'biography_length' => mb_strlen($biography),
            ]);

            return $secretary->load(['skills', 'itemInstances']);
        }, 3);
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
            Storage::disk(self::IMAGE_DISK)->delete($stored['path']);

            throw $exception;
        }

        if (is_string($oldPath)) {
            Storage::disk(self::IMAGE_DISK)->delete($oldPath);
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
