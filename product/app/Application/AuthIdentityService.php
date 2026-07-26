<?php

namespace App\Application;

use App\Models\AuthIdentity;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

final class AuthIdentityService
{
    public function authenticate(string $provider, ExternalIdentityData $external, ?User $linkTo = null): User
    {
        if (! in_array($provider, ['discord', 'google'], true)) {
            throw new DomainException('対応していない認証providerです。');
        }

        return DB::transaction(function () use ($provider, $external, $linkTo): User {
            $identity = AuthIdentity::query()
                ->where('provider', $provider)
                ->where('provider_user_id', $external->providerUserId)
                ->lockForUpdate()
                ->first();

            if ($linkTo !== null) {
                if ($identity !== null && $identity->user_id !== $linkTo->id) {
                    throw new DomainException('この外部アカウントは別のUserへ連携済みです。');
                }

                if ($identity === null) {
                    $identity = AuthIdentity::query()->create([
                        'user_id' => $linkTo->id,
                        'provider' => $provider,
                        'provider_user_id' => $external->providerUserId,
                        'display_name' => $external->displayName,
                    ]);
                    $this->audit($linkTo, 'auth.identity_linked', $identity, $provider);
                }

                return $linkTo;
            }

            if ($identity !== null) {
                return $identity->user()->firstOrFail();
            }

            $user = User::query()->create(['display_name' => $external->displayName]);
            $identity = AuthIdentity::query()->create([
                'user_id' => $user->id,
                'provider' => $provider,
                'provider_user_id' => $external->providerUserId,
                'display_name' => $external->displayName,
            ]);
            $this->audit($user, 'auth.identity_registered', $identity, $provider);

            return $user;
        }, 3);
    }

    private function audit(User $user, string $eventType, AuthIdentity $identity, string $provider): void
    {
        DB::table('audit_events')->insert([
            'actor_user_id' => $user->id,
            'event_type' => $eventType,
            'subject_type' => AuthIdentity::class,
            'subject_id' => $identity->id,
            'metadata' => json_encode(['provider' => $provider], JSON_THROW_ON_ERROR),
            'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
