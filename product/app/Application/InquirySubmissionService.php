<?php

namespace App\Application;

use App\Models\Inquiry;
use App\Models\Nation;
use App\Models\NationMembership;
use App\Models\User;
use App\Models\World;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class InquirySubmissionService
{
    /** @return array{inquiry: Inquiry, created: bool} */
    public function submit(
        User $user,
        string $submissionKey,
        string $category,
        string $subject,
        string $body,
        ?UploadedFile $attachment,
    ): array {
        $storedPath = null;

        try {
            return DB::transaction(function () use (
                $user,
                $submissionKey,
                $category,
                $subject,
                $body,
                $attachment,
                &$storedPath,
            ): array {
                $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                $existing = Inquiry::query()
                    ->where('user_id', $lockedUser->id)
                    ->where('submission_key', $submissionKey)
                    ->first();
                if ($existing instanceof Inquiry) {
                    return ['inquiry' => $existing, 'created' => false];
                }

                [$world, $nation] = $this->currentContext($lockedUser);
                $token = null;
                if ($attachment instanceof UploadedFile) {
                    $token = bin2hex(random_bytes(32));
                    $storedPath = $token.'.'.$this->extension($attachment);
                    $written = Storage::disk('inquiry_attachments')->putFileAs('', $attachment, $storedPath);
                    if ($written !== $storedPath) {
                        throw new DomainException('問い合わせ画像を保存できませんでした。');
                    }
                }

                $inquiry = Inquiry::query()->create([
                    'submission_key' => $submissionKey,
                    'user_id' => $lockedUser->id,
                    'world_id' => $world->id,
                    'nation_id' => $nation?->id,
                    'submitted_turn' => $world->current_turn,
                    'application_version' => (string) config('hakoniwa.application_version'),
                    'category' => $category,
                    'subject' => $subject,
                    'body' => $body,
                    'attachment_token' => $token,
                    'attachment_path' => $storedPath,
                ]);

                return ['inquiry' => $inquiry, 'created' => true];
                // A filesystem write cannot be rolled back, so this closure must never be replayed automatically.
            });
        } catch (Throwable $exception) {
            if ($storedPath !== null) {
                Storage::disk('inquiry_attachments')->delete($storedPath);
            }

            throw $exception;
        }
    }

    /** @return array{World, Nation|null} */
    private function currentContext(User $user): array
    {
        $membership = NationMembership::query()
            ->where('user_id', $user->id)
            ->whereHas('nation', fn ($query) => $query->where('state', 'active'))
            ->with('nation.world')
            ->orderBy('id')
            ->first();
        if ($membership instanceof NationMembership) {
            return [$membership->nation->world, $membership->nation];
        }

        $world = World::query()->where('key', config('hakoniwa.world.key'))->firstOrFail();

        return [$world, null];
    }

    private function extension(UploadedFile $attachment): string
    {
        return match ($attachment->getMimeType()) {
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => throw new DomainException('問い合わせ画像のMIME typeが許可されていません。'),
        };
    }
}
