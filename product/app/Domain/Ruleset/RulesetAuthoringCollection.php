<?php

namespace App\Domain\Ruleset;

use DomainException;
use RuntimeException;

final readonly class RulesetAuthoringCollection
{
    /** @param array<string, array<string, mixed>> $rulesets */
    private function __construct(private array $rulesets) {}

    /**
     * @param  list<string>  $paths
     */
    public static function fromFiles(array $paths): self
    {
        $rulesets = [];

        foreach ($paths as $path) {
            $ruleset = require $path;
            if (! is_array($ruleset)) {
                throw new RuntimeException("Ruleset authoring file {$path} must return a PHP array.");
            }

            $rulesets[] = $ruleset;
        }

        return self::fromArrays($rulesets);
    }

    /**
     * @param  list<array<string, mixed>>  $rulesets
     */
    public static function fromArrays(array $rulesets): self
    {
        $byKey = [];

        foreach ($rulesets as $ruleset) {
            $key = $ruleset['key'] ?? null;
            if (! is_string($key) || $key === '') {
                throw new DomainException('Every ruleset authoring payload requires a non-empty key.');
            }
            if (array_key_exists($key, $byKey)) {
                throw new DomainException("Duplicate ruleset authoring key: {$key}.");
            }

            $byKey[$key] = $ruleset;
        }

        return new self($byKey);
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        return $this->rulesets;
    }
}
