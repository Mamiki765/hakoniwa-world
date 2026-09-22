<?php

use App\Application\CompensationWarehouseService;
use App\Application\DailyLoginRewardService;
use App\Application\ParadoxBalanceService;
use App\Models\CompensationGrant;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $databasePath, $operation, $encodedPayload] = $argv;
$payload = json_decode($encodedPayload, true, 512, JSON_THROW_ON_ERROR);
if (! is_array($payload)) {
    throw new RuntimeException('Worker payload must be an object.');
}

$backend = DB::selectOne('SELECT pg_backend_pid() AS pid');
if (! is_object($backend) || ! isset($backend->pid)) {
    throw new RuntimeException('Worker could not determine its PostgreSQL backend PID.');
}
file_put_contents($databasePath, (string) $backend->pid, LOCK_EX);

try {
    $user = User::query()->findOrFail((int) $payload['user_id']);
    if ($operation === 'daily_login') {
        $claim = app(DailyLoginRewardService::class)->claim($user);
        $result = [
            'status' => 'success',
            'operation' => $operation,
            'awarded_now' => $claim['awarded_now'],
        ];
    } elseif ($operation === 'paradox_debit') {
        $debit = app(ParadoxBalanceService::class)->debit(
            $user->id,
            (int) $payload['amount'],
            (string) $payload['entry_key'],
            'command',
        );
        $result = [
            'status' => 'success',
            'operation' => $operation,
            'balance_after' => $debit['balance_after'] ?? null,
        ];
    } elseif ($operation === 'compensation_claim') {
        $grant = CompensationGrant::query()->findOrFail((int) $payload['grant_id']);
        $claim = app(CompensationWarehouseService::class)->claim(
            $user,
            $grant,
            (string) $payload['request_id'],
        );
        $result = [
            'status' => 'success',
            'operation' => $operation,
            'duplicate' => $claim['duplicate'],
            'grant_status' => $claim['grant']['status'],
        ];
    } else {
        throw new RuntimeException("Unknown operation {$operation}.");
    }

    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR));
} catch (Throwable $exception) {
    fwrite(STDOUT, json_encode([
        'status' => 'exception',
        'operation' => $operation,
        'class' => $exception::class,
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR));
}
