<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        Schema::create('merchant_conversation_topics', function (Blueprint $table): void {
            $table->id();
            $table->text('question');
            $table->text('answer');
            $table->boolean('enabled')->default(true);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

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

    public function down(): void
    {
        throw new RuntimeException('The 4.8.0 merchant conversation migration is forward-only.');
    }
};
