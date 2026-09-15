<?php

declare(strict_types=1);

if ($argc < 2) {
    fwrite(STDERR, "Usage: php tests/scripts/merge_junit.php <output.xml> [input.xml...]\n");
    exit(2);
}

try {
    $output = $argv[1];
    $merged = new DOMDocument('1.0', 'UTF-8');
    $merged->formatOutput = true;
    $root = $merged->appendChild($merged->createElement('testsuites'));

    foreach (array_slice($argv, 2) as $input) {
        $source = new DOMDocument;
        if (! $source->load($input, LIBXML_NONET)) {
            throw new RuntimeException("Unable to read JUnit input [{$input}].");
        }
        $sourceRoot = $source->documentElement;
        if (! $sourceRoot instanceof DOMElement) {
            throw new RuntimeException("JUnit input [{$input}] has no document element.");
        }
        $suites = $sourceRoot->tagName === 'testsuite'
            ? [$sourceRoot]
            : iterator_to_array($sourceRoot->childNodes);
        foreach ($suites as $suite) {
            if ($suite instanceof DOMElement && $suite->tagName === 'testsuite') {
                $root->appendChild($merged->importNode($suite, true));
            }
        }
    }

    if ($merged->save($output) === false) {
        throw new RuntimeException("Unable to write merged JUnit output [{$output}].");
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'JUnit merge failed: '.$exception->getMessage()."\n");
    exit(1);
}
