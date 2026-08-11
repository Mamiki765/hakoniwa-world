<?php

namespace App\Application;

use App\Models\AuthIdentity;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class VisitorCodeAllocator
{
    public function __construct(private readonly VisitorCodeGenerator $generator) {}

    public function allocate(User $user): string
    {
        return DB::transaction(function () use ($user): string {
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            if (is_string($lockedUser->visitor_code) && $lockedUser->visitor_code !== '') {
                return $lockedUser->visitor_code;
            }

            $identity = AuthIdentity::query()
                ->where('user_id', $lockedUser->id)
                ->whereIn('provider', ['discord', 'google'])
                ->orderByRaw("CASE provider WHEN 'discord' THEN 0 WHEN 'google' THEN 1 ELSE 2 END")
                ->orderBy('id')
                ->first();
            if ($identity === null || $identity->provider_user_id === '') {
                throw new RuntimeException('Visitor code allocation requires a stable Discord or Google identity.');
            }

            for ($counter = 0; $counter < 10_000; $counter++) {
                $candidate = $this->generator->candidate(
                    $identity->provider,
                    $identity->provider_user_id,
                    $counter,
                );
                if (User::query()->where('visitor_code', $candidate)->whereKeyNot($lockedUser->id)->exists()) {
                    continue;
                }

                try {
                    DB::transaction(function () use ($lockedUser, $candidate): void {
                        $lockedUser->forceFill(['visitor_code' => $candidate])->save();
                    });

                    return $candidate;
                } catch (QueryException $exception) {
                    $lockedUser->forceFill(['visitor_code' => null]);
                    if ($exception->getCode() !== '23505'
                        || ! str_contains($exception->getMessage(), 'users_visitor_code_unique')) {
                        throw $exception;
                    }
                }
            }

            throw new RuntimeException('Visitor code collision retry limit was exhausted.');
        }, 3);
    }
}
