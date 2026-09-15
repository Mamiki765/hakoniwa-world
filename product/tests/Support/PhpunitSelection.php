<?php

namespace Tests\Support;

use InvalidArgumentException;

final class PhpunitSelection
{
    /** @var list<string> */
    private const SELECTOR_OPTIONS = [
        '--testsuite', '--exclude-testsuite', '--group', '--exclude-group', '--covers', '--uses',
        '--requires-php-extension', '--filter', '--exclude-filter', '--test-suffix',
    ];

    /** @var list<string> */
    private const VALUE_OPTIONS = [
        '--bootstrap', '-c', '--configuration', '--extension', '--include-path', '-d', '--cache-directory',
        '--generate-baseline', '--use-baseline', '--testsuite', '--exclude-testsuite', '--group',
        '--exclude-group', '--covers', '--uses', '--requires-php-extension', '--filter', '--exclude-filter',
        '--test-suffix', '--default-time-limit', '--order-by', '--random-order-seed', '--columns',
        '--log-junit', '--log-otr', '--log-teamcity', '--testdox-html', '--testdox-text', '--log-events-text',
        '--log-events-verbose-text', '--coverage-clover', '--coverage-openclover', '--coverage-cobertura',
        '--coverage-crap4j', '--coverage-html', '--coverage-php', '--coverage-xml', '--coverage-filter',
        '--atleast-version',
    ];

    /**
     * @param  list<string>  $arguments
     * @return array{selection_mode: 'scope'|'focused', has_test_inputs: bool, passthrough: list<string>}
     */
    public static function classify(array $arguments): array
    {
        $focused = false;
        $hasTestInputs = false;
        $passthrough = [];
        $afterSeparator = false;

        for ($index = 0, $count = count($arguments); $index < $count; $index++) {
            $argument = $arguments[$index];
            if ($afterSeparator) {
                $focused = true;
                $hasTestInputs = true;

                continue;
            }
            if ($argument === '--') {
                $afterSeparator = true;

                continue;
            }
            if ($argument === '' || $argument[0] !== '-') {
                $focused = true;
                $hasTestInputs = true;

                continue;
            }

            $option = explode('=', $argument, 2)[0];
            if (in_array($option, self::SELECTOR_OPTIONS, true)) {
                $focused = true;
            }
            $passthrough[] = $argument;
            if (! str_contains($argument, '=') && in_array($option, self::VALUE_OPTIONS, true)) {
                if (! array_key_exists($index + 1, $arguments)) {
                    throw new InvalidArgumentException("PHPUnit option [{$option}] requires a value.");
                }
                $passthrough[] = $arguments[++$index];
            }
        }

        return [
            'selection_mode' => $focused ? 'focused' : 'scope',
            'has_test_inputs' => $hasTestInputs,
            'passthrough' => $passthrough,
        ];
    }
}
