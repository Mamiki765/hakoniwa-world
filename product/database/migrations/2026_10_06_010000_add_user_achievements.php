<?php

use App\Application\PowerEconomyUpgrade;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        Schema::create('user_achievements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('achievement_key');
            $table->string('title_key');
            $table->timestampTz('acquired_at');
            $table->unique(['user_id', 'achievement_key']);
        });
        app(PowerEconomyUpgrade::class)->enableUserAchievements();
        // 既に命名済みの人への補填・外部通知は、Ownerの判断後に別途追加する。
    }

    public function down(): void
    {
        throw new RuntimeException('User achievements are forward-only. Restore a reviewed backup instead of deleting acquired titles.');
    }
};
