<?php

$domain = require __DIR__.'/../release-4.11/power-and-pizza.php';
unset($domain['payload']['power_economy']['pizzeria_maintenance']);
$domain['classification']['data'] = array_values(array_diff($domain['classification']['data'], ['pizzeria_maintenance']));

return $domain;
