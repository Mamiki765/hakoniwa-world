<?php

namespace App\Domain\Inquiry;

use DomainException;

final class InquiryCategoryCatalog
{
    /** @var array<string, string> */
    public const LABELS = [
        'bug' => 'バグ報告',
        'request' => '要望',
        'idea' => 'アイデア',
        'secretary_fan_art' => '秘書のファンアート',
        'other' => 'その他',
    ];

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys(self::LABELS);
    }

    public function label(string $key): string
    {
        return self::LABELS[$key] ?? throw new DomainException("Unknown inquiry category {$key}.");
    }
}
