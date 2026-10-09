<?php

$domain = require __DIR__.'/../release-4.18/lifecycle-and-karma.php';
$domain['payload']['karma']['spp_self_destruct_setup_points'] = 30;

return $domain;
