<?php

namespace App\Domain\Ruleset;

use DomainException;

final class CurrentRulesetAuthoringInspector
{
    /** @var list<string> */
    private const DOMAIN_FILES = [
        'world-and-map.php',
        'lifecycle-and-karma.php',
        'economy-and-resources.php',
        'terrain-and-disasters.php',
        'facilities.php',
        'commands-and-production.php',
        'turn-pipeline.php',
        'monsters-and-military.php',
        'secretary.php',
        'trading-post.php',
    ];

    private const CLASSIFICATIONS = ['behavior', 'data', 'flavor'];

    /**
     * @param  array<string, mixed>  $publishedPayload
     * @return array{domains: int, leaves: int, behavior: int, data: int, flavor: int}
     */
    public function inspect(array $publishedPayload): array
    {
        $authoredLeaves = [];
        $classifiedPaths = [];
        $counts = array_fill_keys(self::CLASSIFICATIONS, 0);

        foreach (self::DOMAIN_FILES as $file) {
            $domain = require config_path('hakoniwa/rulesets/current/'.$file);
            if (! is_array($domain) || array_keys($domain) !== ['payload', 'classification']) {
                throw new DomainException("Current Ruleset domain {$file} must contain only payload and classification.");
            }

            $payload = $domain['payload'];
            $classification = $domain['classification'];
            if (! is_array($payload) || ! is_array($classification)) {
                throw new DomainException("Current Ruleset domain {$file} has an invalid authoring shape.");
            }
            if (array_keys($classification) !== self::CLASSIFICATIONS) {
                throw new DomainException(
                    "Current Ruleset domain {$file} must use exactly behavior, data, and flavor classifications.",
                );
            }

            $selectors = $this->selectors($classification, $file);
            $usedSelectors = array_fill_keys(array_keys($selectors), false);
            foreach ($this->leaves($payload) as $path => $leaf) {
                if (array_key_exists($path, $authoredLeaves)) {
                    throw new DomainException("Current Ruleset leaf {$path} is authored by more than one domain.");
                }

                $matches = [];
                foreach ($selectors as $selectorKey => $selector) {
                    if ($this->matches($selector['value'], $path, $leaf['field'])) {
                        $matches[] = $selector;
                        $usedSelectors[$selectorKey] = true;
                    }
                }
                if (count($matches) !== 1) {
                    throw new DomainException(sprintf(
                        'Current Ruleset leaf %s must be classified exactly once; matched %d classifications.',
                        $path,
                        count($matches),
                    ));
                }

                $category = $matches[0]['category'];
                $authoredLeaves[$path] = $leaf;
                $classifiedPaths[$path] = $category;
                $counts[$category]++;
            }

            $unused = array_keys(array_filter($usedSelectors, static fn (bool $used): bool => ! $used));
            if ($unused !== []) {
                throw new DomainException(sprintf(
                    'Current Ruleset domain %s has unused classification selectors: %s.',
                    $file,
                    implode(', ', $unused),
                ));
            }
        }

        $publishedLeaves = $this->leaves($publishedPayload);
        ksort($authoredLeaves);
        ksort($publishedLeaves);
        if ($authoredLeaves !== $publishedLeaves) {
            throw new DomainException('Current Ruleset domain leaves do not exactly match the published payload.');
        }
        if (count($classifiedPaths) !== count($publishedLeaves)) {
            throw new DomainException('Current Ruleset classification coverage is incomplete.');
        }

        return [
            'domains' => count(self::DOMAIN_FILES),
            'leaves' => count($publishedLeaves),
            'behavior' => $counts['behavior'],
            'data' => $counts['data'],
            'flavor' => $counts['flavor'],
        ];
    }

    /**
     * @param  array<string, mixed>  $classification
     * @return array<string, array{category: string, value: string}>
     */
    private function selectors(array $classification, string $file): array
    {
        $selectors = [];
        foreach (self::CLASSIFICATIONS as $category) {
            $values = $classification[$category];
            if (! is_array($values) || ! array_is_list($values)) {
                throw new DomainException("Current Ruleset domain {$file} classification {$category} must be a list.");
            }
            foreach ($values as $selector) {
                if (! is_string($selector) || $selector === '') {
                    throw new DomainException("Current Ruleset domain {$file} has an invalid {$category} selector.");
                }
                $selectorKey = $category.':'.$selector;
                if (array_key_exists($selectorKey, $selectors)) {
                    throw new DomainException("Current Ruleset domain {$file} duplicates selector {$selectorKey}.");
                }
                $selectors[$selectorKey] = ['category' => $category, 'value' => $selector];
            }
        }

        return $selectors;
    }

    /**
     * @param  array<mixed>  $value
     * @return array<string, array{field: string, value: mixed}>
     */
    private function leaves(array $value, string $path = ''): array
    {
        $leaves = [];
        foreach ($value as $field => $nested) {
            $fieldName = (string) $field;
            $nestedPath = $path.'/'.$this->escapePointerSegment($fieldName);
            if (is_array($nested)) {
                $leaves += $this->leaves($nested, $nestedPath);
            } else {
                $leaves[$nestedPath] = ['field' => $fieldName, 'value' => $nested];
            }
        }

        return $leaves;
    }

    private function matches(string $selector, string $path, string $field): bool
    {
        if (! str_starts_with($selector, '/')) {
            return $selector === $field;
        }

        $selectorSegments = explode('/', substr($selector, 1));
        $pathSegments = explode('/', substr($path, 1));
        if (count($selectorSegments) !== count($pathSegments)) {
            return false;
        }
        foreach ($selectorSegments as $index => $segment) {
            if ($segment === '*') {
                if (! ctype_digit($pathSegments[$index])) {
                    return false;
                }

                continue;
            }
            if ($segment !== $pathSegments[$index]) {
                return false;
            }
        }

        return true;
    }

    private function escapePointerSegment(string $segment): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $segment);
    }
}
