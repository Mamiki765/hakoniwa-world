<?php

use App\Application\PowerEconomyUpgrade;
use App\Application\UserAchievementService;
use App\Models\Secretary;
use Illuminate\Database\Eloquent\Collection;
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
            $table->timestampTz('maria_sent_at')->nullable();
            $table->unique(['user_id', 'achievement_key']);
        });
        Schema::table('secretaries', function (Blueprint $table): void {
            $table->string('equipped_title_key')->nullable();
        });
        app(PowerEconomyUpgrade::class)->enableUserAchievements();
        // 過去の命名日時で補填し、未装備の秘書に初期肩書きを付ける。外部通知は行わない。
        Secretary::query()->whereNotNull('name')->whereNotNull('named_at')->chunkById(100, function (Collection $secretaries): void {
            foreach ($secretaries as $secretary) {
                app(UserAchievementService::class)->grantIslandSecretary($secretary);
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('User achievements are forward-only. Restore a reviewed backup instead of deleting acquired titles.');
    }
};
