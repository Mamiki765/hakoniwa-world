<?php

namespace App\Http\Resources;

use App\Application\AnnouncementBodyRenderer;
use App\Models\Announcement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Announcement */
final class AnnouncementResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'body_format' => $this->body_format,
            'body_html' => $this->body_format === Announcement::FORMAT_MARKDOWN
                ? app(AnnouncementBodyRenderer::class)->render($this->body)
                : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
