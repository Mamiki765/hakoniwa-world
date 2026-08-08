<?php

$ruleset = require __DIR__.'/hakoniwa-2s-plus-v1.php';

$ruleset['key'] = 'hakoniwa-2s-plus-v2';
$ruleset['version'] = 2;
$ruleset['military']['dormant_impact']['explicit_target_state'] = 'any_existing_coordinate';

return $ruleset;
