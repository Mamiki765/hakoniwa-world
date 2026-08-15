<?php

use App\Application\CommandQueueService;
use App\Models\MapSpace;
use App\Models\Nation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $readyPath, $goPath, $databasePath, $encodedPayload] = $argv;
$payload = json_decode($encodedPayload, true, 512, JSON_THROW_ON_ERROR);
if (! is_array($payload)) {
    throw new RuntimeException('Worker payload must be an object.');
}

file_put_contents($readyPath, 'ready', LOCK_EX);
$deadline = microtime(true) + 10;
while (! is_file($goPath)) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Worker start barrier timed out.');
    }
    usleep(10_000);
}

$backend = DB::selectOne('SELECT pg_backend_pid() AS pid');
if (! is_object($backend) || ! isset($backend->pid)) {
    throw new RuntimeException('Worker could not determine its PostgreSQL backend PID.');
}
file_put_contents($databasePath, (string) $backend->pid, LOCK_EX);

try {
    $result = app(CommandQueueService::class)->add(
        user: User::query()->findOrFail((int) $payload['user_id']),
        nation: Nation::query()->findOrFail((int) $payload['nation_id']),
        mapSpace: MapSpace::query()->findOrFail((int) $payload['map_space_id']),
        commandKey: 'land_clear',
        targetX: (int) $payload['target_x'],
        targetY: (int) $payload['target_y'],
        requestKey: (string) $payload['request_key'],
        expectedVersion: 1,
    );
    fwrite(STDOUT, json_encode([
        'status' => 'unexpected_success',
        'item_id' => $result['item']->id,
    ], JSON_THROW_ON_ERROR));
} catch (AuthorizationException $exception) {
    fwrite(STDOUT, json_encode([
        'status' => 'authorization',
        'http_status' => 403,
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
