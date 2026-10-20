<?php

namespace App\Domain\Nation;

use DomainException;

/**
 * Optional player-given names for owned things such as Ships and capitals.
 * An empty value means "unnamed"; readers then keep the existing type label.
 */
final class GivenName
{
    public const MAX_LENGTH = 16;

    public static function normalize(?string $value, string $label): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! mb_check_encoding($value, 'UTF-8') || preg_match(NationProfileText::SINGLE_LINE_PATTERN, $value) !== 1) {
            throw new DomainException("{$label}に改行や制御文字は使用できません。");
        }
        $value = NationProfileText::trimSpaces($value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > self::MAX_LENGTH) {
            throw new DomainException($label.'は'.self::MAX_LENGTH.'文字以下で入力してください。');
        }

        return $value;
    }
}
