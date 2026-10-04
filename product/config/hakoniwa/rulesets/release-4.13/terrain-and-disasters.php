<?php

$domain = require __DIR__.'/../release-4.11/terrain-and-disasters.php';
foreach (['typhoon', 'meteor_shower'] as $key) {
    unset($domain['payload']['turn_processing']['disasters'][$key]['probability'],
        $domain['payload']['turn_processing']['disasters'][$key]['center_padding'],
        $domain['payload']['turn_processing']['disasters'][$key]['radius']);
}
// Used only to preserve the old per-center expectation in terminal weather + the outside border.
// The legacy global huge meteor opportunity/trigger draw is no longer executed.
$domain['payload']['turn_processing']['disasters']['huge_meteor']['probability'] = ['numerator' => 100, 'denominator' => 2000];
$domain['payload']['turn_processing']['sea_area_weather'] = [
    'stream_version' => 1,
    'denominator' => 10000,
    // Absolute probabilities: normal weights (including future seasons) never rescale these slots.
    'fixed_probabilities' => ['typhoon' => 140, 'meteor_shower' => 55],
    'normal_weights' => ['sunny' => 45, 'cloudy' => 25, 'rain' => 20, 'snow' => 5, 'thunder' => 3],
    'rain_forest_growth_multiplier' => 2,
];
$domain['classification']['data'] = [
    ...$domain['classification']['data'],
    '/turn_processing/sea_area_weather/fixed_probabilities/typhoon',
    '/turn_processing/sea_area_weather/fixed_probabilities/meteor_shower',
    '/turn_processing/sea_area_weather/normal_weights/sunny',
    '/turn_processing/sea_area_weather/normal_weights/cloudy',
    '/turn_processing/sea_area_weather/normal_weights/rain',
    '/turn_processing/sea_area_weather/normal_weights/snow',
    '/turn_processing/sea_area_weather/normal_weights/thunder',
    'rain_forest_growth_multiplier',
];

return $domain;
