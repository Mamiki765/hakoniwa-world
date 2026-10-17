<?php

$domain = require __DIR__.'/../current/secretary.php';
foreach (['chancellor_suit', 'grand_chancellor_suit', 'star_chancellor_suit'] as $key) {
    $domain['payload']['secretary']['items'][$key]['effects'][0]['eligible_skill_keys'][] = 'energy_saving';
}

return $domain;
