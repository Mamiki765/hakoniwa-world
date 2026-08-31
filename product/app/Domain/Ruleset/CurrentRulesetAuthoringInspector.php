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

    /** @var array<string, string> */
    private const V17_DOMAIN_OVERRIDES = [
        'world-and-map.php' => 'v17/world-and-map.php',
        'turn-pipeline.php' => 'v17/turn-pipeline.php',
        'monsters-and-military.php' => 'v17/monsters-and-military.php',
        'secretary.php' => 'v17/secretary.php',
    ];

    /** @var array<string, string> */
    private const V18_DOMAIN_OVERRIDES = [
        'world-and-map.php' => 'v18/world-and-map.php',
        'lifecycle-and-karma.php' => 'v18/lifecycle-and-karma.php',
        'terrain-and-disasters.php' => 'v18/terrain-and-disasters.php',
        'facilities.php' => 'v18/facilities.php',
        'commands-and-production.php' => 'v18/commands-and-production.php',
        'turn-pipeline.php' => 'v18/turn-pipeline.php',
        'monsters-and-military.php' => 'v18/monsters-and-military.php',
        'secretary.php' => 'v17/secretary.php',
    ];

    /** @var array<string, string> */
    private const V19_DOMAIN_OVERRIDES = [
        ...self::V18_DOMAIN_OVERRIDES,
        'world-and-map.php' => 'v19/world-and-map.php',
        'commands-and-production.php' => 'v19/commands-and-production.php',
    ];

    private const CLASSIFICATIONS = ['behavior', 'data', 'flavor'];

    /**
     * @param  array<string, mixed>  $publishedPayload
     * @return array{domains: int, leaves: int, behavior: int, data: int, flavor: int}
     */
    public function inspect(array $publishedPayload): array
    {
        $rulesetKey = $publishedPayload['key'] ?? null;
        if (! in_array($rulesetKey, ['hakoniwa-2s-plus-v16', 'hakoniwa-2s-plus-v17', 'hakoniwa-2s-plus-v18', 'hakoniwa-2s-plus-v19'], true)) {
            throw new DomainException('Ruleset authoring inspection supports only immutable v16, v17, v18, and v19.');
        }
        $authoredLeaves = [];
        $classifiedPaths = [];
        $counts = array_fill_keys(self::CLASSIFICATIONS, 0);

        foreach (self::DOMAIN_FILES as $file) {
            $relativePath = match ($rulesetKey) {
                'hakoniwa-2s-plus-v19' => self::V19_DOMAIN_OVERRIDES[$file] ?? 'current/'.$file,
                'hakoniwa-2s-plus-v18' => self::V18_DOMAIN_OVERRIDES[$file] ?? 'current/'.$file,
                'hakoniwa-2s-plus-v17' => self::V17_DOMAIN_OVERRIDES[$file] ?? 'current/'.$file,
                default => 'current/'.$file,
            };
            $domain = require config_path('hakoniwa/rulesets/'.$relativePath);
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
                    if ($this->matches(
                        $selector['value'],
                        $path,
                        $leaf['field'],
                        $leaf['list_index_segments'],
                    )) {
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
                $authoredLeaves[$path] = [
                    'field' => $leaf['field'],
                    'value' => $leaf['value'],
                ];
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

        $publishedLeaves = array_map(
            static fn (array $leaf): array => [
                'field' => $leaf['field'],
                'value' => $leaf['value'],
            ],
            $this->leaves($publishedPayload),
        );
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
     * @param  list<int>  $listIndexSegments
     * @return array<string, array{field: string, value: mixed, list_index_segments: list<int>}>
     */
    private function leaves(
        array $value,
        string $path = '',
        int $depth = 0,
        array $listIndexSegments = [],
    ): array {
        $leaves = [];
        $isList = array_is_list($value);
        foreach ($value as $field => $nested) {
            $fieldName = (string) $field;
            $nestedPath = $path.'/'.$this->escapePointerSegment($fieldName);
            $nestedListIndexSegments = $listIndexSegments;
            if ($isList) {
                $nestedListIndexSegments[] = $depth;
            }
            if (is_array($nested)) {
                $leaves += $this->leaves(
                    $nested,
                    $nestedPath,
                    $depth + 1,
                    $nestedListIndexSegments,
                );
            } else {
                $leaves[$nestedPath] = [
                    'field' => $fieldName,
                    'value' => $nested,
                    'list_index_segments' => $nestedListIndexSegments,
                ];
            }
        }

        return $leaves;
    }

    /** @param list<int> $listIndexSegments */
    private function matches(
        string $selector,
        string $path,
        string $field,
        array $listIndexSegments,
    ): bool {
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
                if (! ctype_digit($pathSegments[$index])
                    || ! in_array($index, $listIndexSegments, true)) {
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
