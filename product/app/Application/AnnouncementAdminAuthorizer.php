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

        return $user->authIdentities()
            ->where('provider', 'discord')
            ->where('provider_user_id', $configuredId)
            ->exists();
    }
}
