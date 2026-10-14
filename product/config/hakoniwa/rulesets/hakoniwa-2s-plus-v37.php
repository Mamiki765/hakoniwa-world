<?php

// One unreleased draft; preserve the complete immutable v36 predecessor.
$predecessor = require __DIR__.'/hakoniwa-2s-plus-v36.php';
$worldAndMap = (require __DIR__.'/draft-v37/world-and-map.php')['payload'];
$turnPipeline = (require __DIR__.'/draft-v37/turn-pipeline.php')['payload'];

$predecessor['key'] = $worldAndMap['key'];
$predecessor['version'] = $worldAndMap['version'];
$predecessor['turn_processing']['riot']['facility_keys'] = $turnPipeline['turn_processing']['riot']['facility_keys'];

return $predecessor;
