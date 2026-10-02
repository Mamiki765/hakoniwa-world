<?php

namespace App\Application;

use Illuminate\Support\Facades\DB;

final class CurrentBaselineSeeds
{
    public function install(): void
    {
        $now = now();
        DB::table('merchant_conversation_topics')->insert([
            [
                'question' => 'キミは誰？',
                'answer' => "私はアキ。職業は行商人だよ。\nキミの主人と同じ島主の一人でもあるの。\nそして夢魔……キミの思う色欲の魔族に近い存在だ、ハーフだけどね。",
                'enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'question' => '輝石のかけらをなぜ集める？',
                'answer' => "輝石には時間空間のみならず万物が封じられているの。\n私達夢魔は、君達が使えないかけらでもお腹を満たすのには十分なエネルギーを摂取できる。つまりは食料調達だね。\nあとは商売人魂ってやつ？　あはは☆",
                'enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }
}
