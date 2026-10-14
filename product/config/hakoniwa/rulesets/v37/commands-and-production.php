<?php

$domain = require __DIR__.'/../current/commands-and-production.php';
// These are the currently authored construction commands in this domain.
// Runtime eligibility uses construction_context, so later facilities opt in in their definitions.
$underseaCommands = ['build_seabed_base', 'build_undersea_city', 'build_undersea_fire_station'];
foreach ($domain['payload']['command_definitions'] as &$command) {
    if (in_array($command['key'], $underseaCommands, true)) {
        $command['metadata']['construction_context'] = 'undersea';
        $command['metadata']['parameters']['construction_ship_id'] = [
            'type' => 'integer', 'minimum' => 1, 'required' => false,
        ];
    }
}
unset($command);
$domain['classification']['behavior'][] = 'construction_context';

return $domain;
