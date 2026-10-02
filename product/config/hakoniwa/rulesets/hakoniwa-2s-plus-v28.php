<?php

// Draft only; publication, World/queue upgrade and activation are a separate gate.
$predecessor = require __DIR__.'/hakoniwa-2s-plus-v27.php';
$secretary = (require __DIR__.'/draft-synthesis/secretary.php')['payload']['secretary'];

return [
    ...$predecessor,
    'key' => 'hakoniwa-2s-plus-v28',
    'version' => 28,
    'secretary' => $secretary,
];
