<?php

$domain = require __DIR__.'/../v39/commands-and-production.php';
foreach ($domain['payload']['command_definitions'] as &$command) {
    if ($command['key'] === 'territory_expand') {
        $command['metadata']['adjacency']['source_terrain'] = 'land';
        $command['description'] = '自国の陸地に隣接する中立陸地、または他国の荒地・焼け野原を領有します。休止中の首都周辺は保護されます。';
    }
}
unset($command);
$domain['classification']['behavior'][] = 'source_terrain';

return $domain;
