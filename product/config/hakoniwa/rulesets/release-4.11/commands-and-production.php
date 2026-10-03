<?php

$domain = require __DIR__.'/../current/commands-and-production.php';
// Construction/expansion costs are provisional, editable in this release draft.
foreach ([
    'wind_power' => ['風力発電所建設', '45〜135MWを発電します。増築はありません。被災した設備を建築費の半額で修理できます。', 200],
    'condenser' => ['コンデンサ建設', '電力を1,200MWまで次のターンへ持ち越せます。増築はありません。', 300],
    'pizzeria' => ['ピザ屋建設', '食料と電力から売上を得ます。同じ場所で増築でき、人口は使いません。', 100],
    'thermal_power' => ['火力発電所建設', '石油を優先し、不足分は鉱物を使って半分の出力で発電します。200MWから600MWまで増築できます。', 300],
] as $key => [$name, $description, $cost]) {
    $index = count($domain['payload']['command_definitions']);
    $domain['payload']['command_definitions'][] = [
        'key' => 'build_'.$key, 'name' => $name, 'description' => $description,
        'target_type' => 'cell', 'target_terrain_keys' => ['plain'], 'target_facility_keys' => [],
        'requires_empty_facility' => true, 'cost_money' => $cost, 'required_resources' => [],
        'execution_phase' => 'facility', 'result_terrain_key' => null, 'result_facility_key' => $key,
        'sort_order' => 118 + $index,
        'metadata' => ['consumes_turn' => true, 'execution_deferred' => false],
    ];
}

return $domain;
