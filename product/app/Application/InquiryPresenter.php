<?php

namespace App\Application;

use App\Domain\Inquiry\InquiryCategoryCatalog;
use App\Models\Inquiry;

final class InquiryPresenter
{
    public function __construct(private readonly InquiryCategoryCatalog $categories) {}

    /** @return array<string, mixed> */
    public function submission(Inquiry $inquiry): array
    {
        return [
            'management_id' => $inquiry->managementId(),
            'category' => $inquiry->category,
            'category_label' => $this->categories->label($inquiry->category),
            'subject' => $inquiry->subject,
            'created_at' => $inquiry->created_at->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function summary(Inquiry $inquiry): array
    {
        return [
            ...$this->submission($inquiry),
            'user' => ['id' => $inquiry->user->id, 'display_name' => $inquiry->user->display_name],
            'nation' => $inquiry->nation === null ? null : [
                'id' => $inquiry->nation->id,
                'nation_number' => $inquiry->nation->nation_number,
                'name' => $inquiry->nation->name,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function detail(Inquiry $inquiry): array
    {
        return [
            ...$this->summary($inquiry),
            'body' => $inquiry->body,
            'world' => ['id' => $inquiry->world_id, 'submitted_turn' => $inquiry->submitted_turn],
            'application_version' => $inquiry->application_version,
            'attachment_url' => $this->attachmentUrl($inquiry),
        ];
    }

    private function attachmentUrl(Inquiry $inquiry): ?string
    {
        if ($inquiry->attachment_path === null) {
            return null;
        }

        return rtrim((string) config('hakoniwa.inquiries.attachment_base_url'), '/')
            .'/'.$inquiry->attachment_path;
    }
}
