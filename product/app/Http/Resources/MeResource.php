<?php

namespace App\Http\Resources;

use App\Application\AnnouncementAdminAuthorizer;
use App\Application\ParadoxBalanceService;
use App\Models\AuthIdentity;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class MeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $canManage = app(AnnouncementAdminAuthorizer::class)->allows($this->resource);

        return [
            'id' => $this->id,
            'display_name' => $this->display_name,
            'paradox' => app(ParadoxBalanceService::class)->presentFor($this->id),
            'can_manage_announcements' => $canManage,
            'can_manage_inquiries' => $canManage,
            'can_manage_guide_topics' => $canManage,
            'providers' => $this->authIdentities->map(fn (AuthIdentity $identity): array => [
                'provider' => $identity->provider,
                'display_name' => $identity->display_name,
                'linked_at' => $identity->created_at?->toIso8601String(),
            ])->values(),
        ];
    }
}
