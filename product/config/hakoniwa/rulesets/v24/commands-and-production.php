<?php

$domain = require __DIR__.'/../v23/commands-and-production.php';
$payload = $domain['payload'];
$classification = $domain['classification'];

foreach ($payload['command_definitions'] as &$definition) {
    if (! in_array($definition['key'], ['build_central_bank', 'build_central_granary'], true)) {
        continue;
    }

    $definition['description'] = str_replace(
        '平地へ',
        '平地・村・町・都市へ',
        $definition['description'],
    );
    $definition['metadata']['settlement_overbuild'] = true;
}
unset($definition);

$classification['behavior'][] = 'settlement_overbuild';

return ['payload' => $payload, 'classification' => $classification];
