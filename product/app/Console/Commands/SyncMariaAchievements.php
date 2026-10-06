<?php

namespace App\Console\Commands;

use App\Models\AuthIdentity;
use App\Models\UserAchievement;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

final class SyncMariaAchievements extends Command
{
    protected $signature = 'hakoniwa:sync-maria-achievements';

    protected $description = '保存済みの箱庭実績を、紐づけたDiscord IDでMariaへ連動する';

    public function handle(): int
    {
        $url = config('services.maria_achievements.url');
        $token = config('services.maria_achievements.token');
        if (! is_string($url) || $url === '' || ! is_string($token) || $token === '') {
            $this->comment('Maria実績連動の接続先・専用認証が未設定です。');

            return self::SUCCESS;
        }
        // 取得行そのものを送信待ちとして使う。未連携の人はDiscord連携後に対象になる。
        $receipts = UserAchievement::query()->whereNull('maria_sent_at')
            ->whereIn('user_id', AuthIdentity::query()->where('provider', 'discord')->select('user_id'))
            ->orderBy('id')->limit(25)->get();
        $failed = 0;
        foreach ($receipts as $receipt) {
            $discordId = AuthIdentity::query()->where('user_id', $receipt->user_id)->where('provider', 'discord')->value('provider_user_id');
            try {
                // 命名transactionからは外部へ接続しない。成功応答までは次回も送る。
                $response = Http::acceptJson()->withToken($token)->connectTimeout(2)->timeout(5)->post($url, [
                    'discord_user_id' => $discordId,
                    'achievement_key' => $receipt->achievement_key,
                ]);
                if (! $response->successful() || $response->json('accepted') !== true) {
                    $failed++;

                    continue;
                }
                $receipt->update(['maria_sent_at' => now()]);
            } catch (Throwable) {
                $failed++;
            }
        }
        $this->info('Maria連動: 成功'.($receipts->count() - $failed).'件 / 保留'.$failed.'件。');

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
