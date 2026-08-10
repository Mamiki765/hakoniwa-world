<?php

use App\Application\MessageBoardService;
use App\Application\VisitorCodeAllocator;
use App\Application\VisitorCodeGenerator;
use App\Domain\MessageBoard\MessageBoardCooldownException;
use App\Models\Nation;
use App\Models\User;
use App\Models\World;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $action, $readyPath, $goPath, $databasePath, $encodedPayload] = $argv;
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
    $result = match ($action) {
        'public' => app(MessageBoardService::class)->postPublic(
            User::query()->findOrFail((int) $payload['user_id']),
            Nation::query()->findOrFail((int) $payload['target_nation_id']),
            (string) $payload['body'],
        ),
        'secret' => app(MessageBoardService::class)->postSecret(
            User::query()->findOrFail((int) $payload['user_id']),
            Nation::query()->findOrFail((int) $payload['target_nation_id']),
            (string) $payload['body'],
        ),
        'money_update' => DB::transaction(function () use ($payload): array {
            $world = World::query()->whereKey((int) $payload['world_id'])->lockForUpdate()->firstOrFail();
            $nation = Nation::query()
                ->where('world_id', $world->id)
                ->whereKey((int) $payload['nation_id'])
                ->lockForUpdate()
                ->firstOrFail();
            $nation->money = (int) $nation->money + (int) $payload['amount'];
            $nation->save();

            return ['money' => (int) $nation->money];
        }, 3),
        'allocate_collision' => DB::transaction(function () use ($payload): array {
            $barrierDirectory = (string) $payload['collision_barrier_directory'];
            $slot = (int) $payload['slot'];
            $generator = new class($barrierDirectory, $slot) extends VisitorCodeGenerator
            {
                public function __construct(
                    private readonly string $barrierDirectory,
                    private readonly int $slot,
                ) {}

                public function candidate(string $provider, string $providerUserId, int $collisionCounter): string
                {
                    if ($collisionCounter !== 0) {
                        return 'BBBBBBBB';
                    }

                    file_put_contents(
                        $this->barrierDirectory."/candidate-{$this->slot}",
                        'ready',
                        LOCK_EX,
                    );
                    $deadline = microtime(true) + 10;
                    while (! is_file($this->barrierDirectory.'/candidate-0')
                        || ! is_file($this->barrierDirectory.'/candidate-1')) {
                        if (microtime(true) >= $deadline) {
                            throw new RuntimeException('Collision barrier timed out.');
                        }
                        usleep(10_000);
                    }

                    return 'AAAAAAAA';
                }
            };
            $code = (new VisitorCodeAllocator($generator))->allocate(
                User::query()->findOrFail((int) $payload['user_id']),
            );
            usleep(750_000);

            return ['visitor_code' => $code];
        }, 3),
        default => throw new RuntimeException("Unknown worker action {$action}."),
    };

    fwrite(STDOUT, json_encode(['status' => 'ok', 'result' => $result], JSON_THROW_ON_ERROR));
} catch (MessageBoardCooldownException $exception) {
    fwrite(STDOUT, json_encode([
        'status' => 'cooldown',
        'retry_after_seconds' => $exception->retryAfterSeconds,
    ], JSON_THROW_ON_ERROR));
} catch (Throwable $exception) {
    fwrite(STDOUT, json_encode([
        'status' => 'error',
        'class' => $exception::class,
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR));
    exit(1);
}
