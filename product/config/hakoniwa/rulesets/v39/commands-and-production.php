<?php

$domain = require __DIR__.'/../v37/commands-and-production.php';
foreach ($domain['payload']['command_definitions'] as &$command) {
    if ($command['key'] === 'attraction') {
        $command['metadata']['repeat_quantity'] = true;
        $command['description'] = '指定した回数、毎ターン誘致活動を行い、人口が増加しやすくなります。失敗した場合は残りの回数を取り消します。';
    }
}
unset($command);
$domain['classification']['behavior'][] = 'repeat_quantity';

return $domain;
