<?php

use App\Application\AuthIdentityService;
use App\Application\ExternalIdentityData;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $readyPath, $goPath, $databasePath, $encodedPayload] = $argv;
$payload = json_decode($encodedPayload, true, 512, JSON_THROW_ON_ERROR);
file_put_contents($readyPath, 'ready', LOCK_EX);
$deadline = microtime(true) + 10;
while (! is_file($goPath)) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Auth worker start barrier timed out.');
    }
    usleep(10_000);
}

$backend = DB::selectOne('SELECT pg_backend_pid() AS pid');
file_put_contents($databasePath, (string) $backend->pid, LOCK_EX);

try {
    $linkTo = isset($payload['link_user_id'])
        ? User::query()->findOrFail((int) $payload['link_user_id'])
        : null;
    $user = app(AuthIdentityService::class)->authenticate(
        (string) $payload['provider'],
        new ExternalIdentityData((string) $payload['provider_user_id'], (string) $payload['display_name']),
        $linkTo,
    );
    fwrite(STDOUT, json_encode(['status' => 'ok', 'user_id' => $user->id], JSON_THROW_ON_ERROR));
} catch (Throwable $exception) {
    fwrite(STDOUT, json_encode([
        'status' => 'error',
        'class' => $exception::class,
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR));
    exit(1);
}
