<?php

use App\Application\Underground\UndergroundEquipmentService;
use App\Application\Underground\UndergroundIntroService;
use App\Application\Underground\UndergroundRuntimeException;
use App\Application\Underground\UndergroundRuntimeService;
use App\Models\UndergroundBattle;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $readyPath, $goPath, $databasePath, $encodedPayload] = $argv;
$payload = json_decode($encodedPayload, true, 512, JSON_THROW_ON_ERROR);
file_put_contents($readyPath, 'ready', LOCK_EX);

try {
    $deadline = microtime(true) + 10;
    while (! is_file($goPath)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Underground runtime worker start barrier timed out.');
        }
        usleep(10_000);
    }

    $backend = DB::selectOne('SELECT pg_backend_pid() AS pid');
    file_put_contents($databasePath, (string) $backend->pid, LOCK_EX);

    $user = User::query()->findOrFail((int) $payload['user_id']);
    if (($payload['operation'] ?? 'explore') === 'name_shopkeeper') {
        $result = app(UndergroundIntroService::class)->nameShopkeeper(
            $user,
            (string) $payload['request_id'],
            (string) $payload['name'],
        );
        fwrite(STDOUT, json_encode([
            'status' => 'ok',
            'user_id' => $user->id,
            'request_id' => $payload['request_id'],
            'shopkeeper_name' => $result['shopkeeper_name'],
            'stage' => $result['stage'],
        ], JSON_THROW_ON_ERROR));
    } elseif (($payload['operation'] ?? 'explore') === 'tutorial') {
        $result = app(UndergroundIntroService::class)->tutorial(
            $user,
            (string) $payload['request_id'],
        );
        fwrite(STDOUT, json_encode([
            'status' => 'ok',
            'user_id' => $user->id,
            'request_id' => $payload['request_id'],
            'battle_id' => $result['battle']['id'],
            'stage' => $result['stage'],
        ], JSON_THROW_ON_ERROR));
    } elseif (($payload['operation'] ?? 'explore') === 'contract') {
        $result = app(UndergroundIntroService::class)->contract(
            $user,
            (string) $payload['request_id'],
        );
        fwrite(STDOUT, json_encode([
            'status' => 'ok',
            'user_id' => $user->id,
            'request_id' => $payload['request_id'],
            'stage' => $result['stage'],
        ], JSON_THROW_ON_ERROR));
    } elseif (($payload['operation'] ?? 'explore') === 'growth_path') {
        $result = app(UndergroundIntroService::class)->selectGrowthPath(
            $user,
            (string) $payload['request_id'],
            (string) $payload['growth_path_key'],
        );
        fwrite(STDOUT, json_encode([
            'status' => 'ok',
            'user_id' => $user->id,
            'request_id' => $payload['request_id'],
            'stage' => $result['stage'],
            'growth_path_key' => $result['growth_path']['key'],
        ], JSON_THROW_ON_ERROR));
    } elseif (($payload['operation'] ?? 'explore') === 'bank_transfer') {
        $result = app(UndergroundIntroService::class)->bankTransfer(
            $user,
            (string) $payload['request_id'],
            (string) $payload['action'],
            isset($payload['amount']) ? (int) $payload['amount'] : null,
        );
        fwrite(STDOUT, json_encode([
            'status' => 'ok',
            'user_id' => $user->id,
            'request_id' => $payload['request_id'],
            'shard_balance' => $result['shard_balance'],
            'banked_shard_balance' => $result['banked_shard_balance'],
        ], JSON_THROW_ON_ERROR));
    } elseif (($payload['operation'] ?? 'explore') === 'equipment_purchase') {
        $result = app(UndergroundEquipmentService::class)->purchase(
            $user,
            (string) $payload['request_id'],
            (string) $payload['definition_key'],
        );
        fwrite(STDOUT, json_encode([
            'status' => 'ok',
            'request_id' => $payload['request_id'],
            'definition_key' => $payload['definition_key'],
            'shard_balance' => $result['shard_balance'],
            'vault_used' => $result['vault']['used'],
        ], JSON_THROW_ON_ERROR));
    } elseif (($payload['operation'] ?? 'explore') === 'equipment_sell') {
        $result = app(UndergroundEquipmentService::class)->sell(
            $user,
            (string) $payload['request_id'],
            (int) $payload['item_id'],
        );
        fwrite(STDOUT, json_encode([
            'status' => 'ok',
            'request_id' => $payload['request_id'],
            'shard_balance' => $result['shard_balance'],
            'vault_used' => $result['vault']['used'],
        ], JSON_THROW_ON_ERROR));
    } elseif (($payload['operation'] ?? 'explore') === 'equipment_equip') {
        $result = app(UndergroundEquipmentService::class)->equip(
            $user,
            (string) $payload['request_id'],
            (int) $payload['item_id'],
        );
        fwrite(STDOUT, json_encode([
            'status' => 'ok',
            'request_id' => $payload['request_id'],
            'equipped_weapon_id' => $result['vault']['equipped']['weapon']['id'],
        ], JSON_THROW_ON_ERROR));
    } elseif (($payload['operation'] ?? 'explore') === 'stp_allocate') {
        $result = app(UndergroundIntroService::class)->allocateStp(
            $user,
            (string) $payload['request_id'],
            $payload['allocations'],
        );
        fwrite(STDOUT, json_encode([
            'status' => 'ok',
            'user_id' => $user->id,
            'request_id' => $payload['request_id'],
            'unspent_stp' => $result['unspent_stp'],
            'allocated_stp' => $result['allocated_stp'],
        ], JSON_THROW_ON_ERROR));
    } elseif (($payload['operation'] ?? 'explore') === 'skill_acquire') {
        $result = app(UndergroundIntroService::class)->acquireSkillNode(
            $user,
            (string) $payload['request_id'],
            (string) $payload['node_key'],
        );
        fwrite(STDOUT, json_encode([
            'status' => 'ok',
            'user_id' => $user->id,
            'request_id' => $payload['request_id'],
            'skill_points_unspent' => $result['skill_points_unspent'],
        ], JSON_THROW_ON_ERROR));
    } else {
        $result = app(UndergroundRuntimeService::class)->explore(
            $user,
            (string) $payload['request_id'],
        );
        $battle = $result['battle'];
        if (! $battle instanceof UndergroundBattle) {
            throw new RuntimeException('Underground runtime did not return a battle.');
        }

        fwrite(STDOUT, json_encode([
            'status' => 'ok',
            'user_id' => $user->id,
            'profile_id' => $battle->underground_profile_id,
            'battle_id' => $battle->id,
            'request_id' => $battle->request_id,
            'duplicate' => (bool) $result['duplicate'],
            'result' => $battle->result,
            'xp_awarded' => $battle->xp_awarded,
            'shard_delta' => $battle->shard_delta,
        ], JSON_THROW_ON_ERROR));
    }
} catch (UndergroundRuntimeException $exception) {
    fwrite(STDOUT, json_encode([
        'status' => 'conflict',
        'error_code' => $exception->errorCode,
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR));
} catch (Throwable $exception) {
    fwrite(STDOUT, json_encode([
        'status' => 'error',
        'class' => $exception::class,
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR));
    exit(1);
}
