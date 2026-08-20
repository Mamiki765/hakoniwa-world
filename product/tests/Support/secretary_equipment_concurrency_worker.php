<?php

use App\Application\NationAbandonmentService;
use App\Application\NationCreationService;
use App\Application\SecretaryEquipmentService;
use App\Domain\Nation\UserMembershipMutationLock;
use App\Models\Nation;
use App\Models\TurnRun;
use App\Models\User;
use App\Models\World;
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
    if ($operation === 'equipment') {
        $secretary = app(SecretaryEquipmentService::class)->mutate(
            $user,
            (int) $payload['slot'],
            isset($payload['item_id']) ? (int) $payload['item_id'] : null,
            (int) $payload['expected_version'],
        );
        $result = [
            'status' => 'success',
            'operation' => $operation,
            'equipment_version' => $secretary->equipment_version,
            'slots' => $secretary->itemInstances->mapWithKeys(
                static fn ($item): array => [(string) $item->id => $item->equipped_slot],
            )->all(),
        ];
    } elseif ($operation === 'abandonment') {
        $nation = Nation::query()->findOrFail((int) $payload['nation_id']);
        $result = app(NationAbandonmentService::class)->abandon($user, $nation, $nation->name);
        $result = ['status' => 'success', 'operation' => $operation] + $result;
    } elseif ($operation === 'abandon_then_block') {
        $lock = app(UserMembershipMutationLock::class);
        $world = World::query()->findOrFail((int) $payload['world_id']);
        $nation = Nation::query()->findOrFail((int) $payload['nation_id']);
        $lock->acquire($user);
        try {
            $abandonment = app(NationAbandonmentService::class)->abandon($user, $nation, $nation->name);
            createPendingTurnRun($world);
        } finally {
            $lock->release($user);
        }
        $result = ['status' => 'success', 'operation' => $operation] + $abandonment;
    } elseif ($operation === 'register_then_block') {
        $lock = app(UserMembershipMutationLock::class);
        $world = World::query()->findOrFail((int) $payload['world_id']);
        $lock->acquire($user);
        try {
            $nation = app(NationCreationService::class)->create(
                $user,
                $world,
                (string) $payload['nation_name'],
                (string) $payload['owner_name'],
            );
            createPendingTurnRun($world);
        } finally {
            $lock->release($user);
        }
        $result = [
            'status' => 'success',
            'operation' => $operation,
            'nation_id' => $nation->id,
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
        'code' => property_exists($exception, 'errorCode') ? $exception->errorCode : null,
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR));
}

function createPendingTurnRun(World $world): void
{
    TurnRun::query()->create([
        'world_id' => $world->id,
        'target_turn' => $world->current_turn + 1,
        'ruleset_version_id' => $world->ruleset_version_id,
        'random_seed' => str_repeat('c', 64),
        'source' => 'manual',
        'is_dry_run' => false,
        'status' => TurnRun::STATUS_PENDING,
        'attempt_count' => 1,
        'pipeline' => [],
        'phase_results' => [],
        'failure_context' => [],
    ]);
}
