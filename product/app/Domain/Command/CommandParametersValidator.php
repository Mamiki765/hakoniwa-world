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
            throw new DomainException('parameters.quantityは使用できません。quantityを第一級fieldで指定してください。');
        }

        $unknown = array_diff(array_keys($parameters), array_keys($schemas));
        if ($unknown !== []) {
            throw new DomainException('未定義のcommand parameterが含まれています。');
        }

        $validated = [];
        foreach ($schemas as $name => $schema) {
            if (! is_array($schema)) {
                throw new DomainException("{$name}のparameter schemaが不正です。");
            }

            $provided = array_key_exists($name, $parameters);
            if (! $provided) {
                if (array_key_exists('default', $schema)) {
                    $value = $schema['default'];
                } elseif (($schema['required'] ?? false) === true) {
                    throw new DomainException("{$name}は必須です。");
                } else {
                    continue;
                }
            } else {
                $value = $parameters[$name];
            }

            if ($value === null) {
                if (($schema['required'] ?? false) === true || ($schema['nullable'] ?? false) !== true) {
                    throw new DomainException("{$name}にnullは指定できません。");
                }
                $validated[$name] = null;

                continue;
            }

            if (($schema['type'] ?? null) !== 'integer' || ! is_int($value)) {
                throw new DomainException("{$name}は整数で指定してください。");
            }
            $minimum = $schema['minimum'] ?? PHP_INT_MIN;
            $maximum = $schema['maximum'] ?? PHP_INT_MAX;
            if (! is_int($minimum) || ! is_int($maximum) || $value < $minimum || $value > $maximum) {
                throw new DomainException("{$name}は{$minimum}から{$maximum}の範囲で指定してください。");
            }
            $validated[$name] = $value;
        }

        return $validated;
    }
}
