<?php

namespace App\Domain\Nation;

use DomainException;

final class NationProfileText
{
    public const OWNER_NAME_MAX_LENGTH = 30;

    public const COMMENT_MAX_LENGTH = 100;

    public const SINGLE_LINE_PATTERN = '/^[^\p{Cc}\p{Cs}\p{Zl}\p{Zp}]*$/u';

    public static function ownerName(string $value): string
    {
        self::assertValidText($value, '島主名');
        $value = self::trimSpaces($value);
        $length = mb_strlen($value);
        if ($length < 1 || $length > self::OWNER_NAME_MAX_LENGTH) {
            throw new DomainException('島主名は1文字以上30文字以下で入力してください。');
        }

        return $value;
    }

    public static function comment(string $value): string
    {
        self::assertValidText($value, '一言コメント');
        $value = self::trimSpaces($value);
        if (mb_strlen($value) > self::COMMENT_MAX_LENGTH) {
            throw new DomainException('一言コメントは100文字以下で入力してください。');
        }

        return $value;
    }

    public static function trimSpaces(string $value): string
    {
        return preg_replace('/(?:^\p{Zs}+|\p{Zs}+$)/u', '', $value) ?? $value;
    }

    private static function assertValidText(string $value, string $label): void
    {
        if (! mb_check_encoding($value, 'UTF-8') || preg_match(self::SINGLE_LINE_PATTERN, $value) !== 1) {
            throw new DomainException("{$label}に改行や制御文字は使用できません。");
        }
    }
}
