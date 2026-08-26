<?php

declare(strict_types=1);

$productRoot = dirname(__DIR__, 2);
$sourcePath = $productRoot.'/database/schema/pgsql-schema.sql';
$targetDirectory = $productRoot.'/storage/framework/cache/phpstan-schema';
$targetPath = $targetDirectory.'/pgsql-schema.sql';

$source = file_get_contents($sourcePath);

if ($source === false) {
    throw new RuntimeException("Unable to read the canonical schema dump: {$sourcePath}");
}

$tableCount = preg_match_all(
    '/^CREATE TABLE public\.([a-z][a-z0-9_]*) \(\R(.*?)^\);\R/ms',
    $source,
    $tables,
    PREG_SET_ORDER,
);

if ($tableCount === false || $tableCount === 0 || $tableCount !== substr_count($source, 'CREATE TABLE public.')) {
    throw new RuntimeException('Unable to extract every table from the canonical PostgreSQL schema dump.');
}

$derivedTables = [];

foreach ($tables as $table) {
    $columns = [];

    foreach (preg_split('/\R/u', $table[2]) ?: [] as $line) {
        $line = trim($line, " \t\n\r\0\x0B,");

        if ($line === '' || str_starts_with($line, 'CONSTRAINT ')) {
            continue;
        }

        if (preg_match(
            '/^"?([a-z][a-z0-9_]*)"? (bigint|integer|smallint|boolean|character varying\(\d+\)|character\(\d+\)|text|jsonb|uuid|numeric\(\d+,\d+\)|timestamp\(\d+\) (?:with|without) time zone)(?: |$)/',
            $line,
            $column,
        ) !== 1) {
            throw new RuntimeException("Unsupported PostgreSQL column definition in {$table[1]}: {$line}");
        }

        $type = match (true) {
            $column[2] === 'bigint' => 'BIGINT',
            $column[2] === 'integer' => 'INT',
            $column[2] === 'smallint' => 'SMALLINT',
            $column[2] === 'boolean' => 'BOOLEAN',
            $column[2] === 'text' => 'TEXT',
            $column[2] === 'jsonb' => 'JSON',
            $column[2] === 'uuid' => 'CHAR(36)',
            str_starts_with($column[2], 'character varying') => strtoupper(str_replace('character varying', 'varchar', $column[2])),
            str_starts_with($column[2], 'character') => strtoupper(str_replace('character', 'char', $column[2])),
            str_starts_with($column[2], 'numeric') => strtoupper(str_replace('numeric', 'decimal', $column[2])),
            str_starts_with($column[2], 'timestamp') => 'DATETIME',
            default => throw new RuntimeException("Unsupported PostgreSQL type in {$table[1]}.{$column[1]}: {$column[2]}"),
        };
        $nullability = str_contains($line, ' NOT NULL') ? 'NOT NULL' : 'NULL';

        $columns[] = sprintf('    `%s` %s %s', $column[1], $type, $nullability);
    }

    if ($columns === []) {
        throw new RuntimeException("No columns were extracted for {$table[1]}.");
    }

    $derivedTables[] = sprintf("CREATE TABLE `%s` (\n%s\n);", $table[1], implode(",\n", $columns));
}

$derived = implode("\n\n", $derivedTables)."\n";

if (! is_dir($targetDirectory) && ! mkdir($targetDirectory, 0777, true) && ! is_dir($targetDirectory)) {
    throw new RuntimeException("Unable to create the PHPStan schema directory: {$targetDirectory}");
}

if (file_put_contents($targetPath, $derived) === false) {
    throw new RuntimeException("Unable to write the PHPStan schema view: {$targetPath}");
}
