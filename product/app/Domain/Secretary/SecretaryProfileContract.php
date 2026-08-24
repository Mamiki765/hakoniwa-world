<?php

namespace App\Domain\Secretary;

use DomainException;

final class SecretaryProfileContract
{
    public const MAX_BIOGRAPHY_LENGTH = 1000;

    public const MAX_CREDIT_LENGTH = 160;

    /** @var array<string, string> */
    public const CREATION_METHODS = [
        'self_made' => '自作',
        'ai_generated' => 'AI生成',
        'commissioned_or_permitted' => '依頼・使用許諾済み',
        'other' => 'その他',
    ];

    /** @var array<string, string> */
    public const FALLBACKS = [
        'silhouette' => 'シルエット',
        'peridot' => 'デフォルト秘書「ペリドット」',
    ];

    public function biography(string $value): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $value);
        $this->assertPlainText($normalized, true);
        if (mb_strlen($normalized) > self::MAX_BIOGRAPHY_LENGTH) {
            throw new DomainException('経歴は1000文字以内で入力してください。');
        }

        return $normalized;
    }

    public function creationMethod(string $value): string
    {
        if (! array_key_exists($value, self::CREATION_METHODS)) {
            throw new DomainException('画像の制作方法を確認してください。');
        }

        return $value;
    }

    public function credit(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $this->assertPlainText($value, false);
        if (mb_strlen($value) > self::MAX_CREDIT_LENGTH) {
            throw new DomainException('作者・権利表記は160文字以内で入力してください。');
        }

        return $value;
    }

    public function fallback(string $value): string
    {
        if (! array_key_exists($value, self::FALLBACKS)) {
            throw new DomainException('秘書画像のfallback設定を確認してください。');
        }

        return $value;
    }

    private function assertPlainText(string $value, bool $allowNewlines): void
    {
        $controlPattern = $allowNewlines
            ? '/[\p{Cf}\p{Cs}\p{Zl}\p{Zp}\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/u'
            : '/[\p{Cc}\p{Cf}\p{Cs}\p{Zl}\p{Zp}]/u';
        if (preg_match($controlPattern, $value) === 1
            || preg_match('/<\s*\/?\s*[A-Za-z][^>]*>/u', $value) === 1) {
            throw new DomainException('HTMLを含まないplain textで入力してください。');
        }
    }
}
