<?php

namespace App\Application;

use App\Models\User;

final class AnnouncementAdminAuthorizer
{
    public function allows(?User $user): bool
    {
        $configuredId = trim((string) config('hakoniwa.admin.discord_user_id', ''));
        if ($user === null || $configuredId === '') {
            return false;
        }

        $identities = $user->relationLoaded('authIdentities')
            ? $user->authIdentities
            : $user->authIdentities()->get();

        return $identities->contains(
            static fn ($identity): bool => $identity->provider === 'discord'
                && hash_equals($configuredId, $identity->provider_user_id),
        );
    }
}
