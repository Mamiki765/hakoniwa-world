<?php

namespace App\Domain\Command;

use DomainException;

final class CommandParametersValidator
{
    /**
     * @param  array<string, mixed>  $schemas
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    public function validate(array $schemas, array $parameters): array
    {
        if (array_key_exists('quantity', $parameters)) {
            throw new PlayerFacingCommandException('parameters.quantityは使用できません。quantityを第一級fieldで指定してください。');
        }

        $unknown = array_diff(array_keys($parameters), array_keys($schemas));
        if ($unknown !== []) {
            throw new PlayerFacingCommandException('未定義のcommand parameterが含まれています。');
        }

        $validated = [];
        foreach ($schemas as $name => $schema) {
            if (! is_array($schema)) {
                throw new DomainException("{$name}のparameter schemaが不正です。");
            }
            if (($schema['type'] ?? null) !== 'integer') {
                throw new DomainException("{$name}のparameter schema typeが不正です。");
            }
            $minimum = $schema['minimum'] ?? PHP_INT_MIN;
            $maximum = $schema['maximum'] ?? PHP_INT_MAX;
            if (! is_int($minimum) || ! is_int($maximum) || $minimum > $maximum) {
                throw new DomainException("{$name}のparameter schema rangeが不正です。");
            }

            $provided = array_key_exists($name, $parameters);
            $usesDefault = false;
            if (! $provided) {
                if (array_key_exists('default', $schema)) {
                    $value = $schema['default'];
                    $usesDefault = true;
                } elseif (($schema['required'] ?? false) === true) {
                    throw new PlayerFacingCommandException("{$name}は必須です。");
                } else {
                    continue;
                }
            } else {
                $value = $parameters[$name];
            }

            if ($value === null) {
                if (($schema['required'] ?? false) === true || ($schema['nullable'] ?? false) !== true) {
                    if ($usesDefault) {
                        throw new DomainException("{$name}のparameter schema defaultがnullです。");
                    }
                    throw new PlayerFacingCommandException("{$name}にnullは指定できません。");
                }
                $validated[$name] = null;

                continue;
            }

            if (! is_int($value)) {
                if ($usesDefault) {
                    throw new DomainException("{$name}のparameter schema default typeが不正です。");
                }
                throw new PlayerFacingCommandException("{$name}は整数で指定してください。");
            }
            if ($value < $minimum || $value > $maximum) {
                if ($usesDefault) {
                    throw new DomainException("{$name}のparameter schema default rangeが不正です。");
                }
                throw new PlayerFacingCommandException("{$name}は{$minimum}から{$maximum}の範囲で指定してください。");
            }
            $validated[$name] = $value;
        }

        return $validated;
    }
}
