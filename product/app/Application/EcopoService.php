<?php

namespace App\Application;

use App\Domain\Economy\EcopoRules;
use App\Domain\Turn\TurnContext;
use App\Domain\World\WorldEventContext;
use App\Models\Nation;
use App\Models\NationMembership;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Credits only final destruction; capacity planning and preserved overflow never call this service. */
final class EcopoService
{
    public function __construct(
        private readonly TurnEventRecorder $events,
        private readonly ParadoxBalanceService $paradox,
    ) {}

    public function discardedResource(TurnContext|WorldEventContext $context, Nation $nation, string $key, int $amount, string $source): void
    {
        $rules = EcopoRules::fromSettings($context->ruleset->settings);
        if ($rules !== null) {
            $this->credit($context, $nation, $rules->resourcePoints($key, $amount), $key, $amount, $source);
        }
    }

    public function discardedMoney(TurnContext|WorldEventContext $context, Nation $nation, int $amount, string $source): void
    {
        $rules = EcopoRules::fromSettings($context->ruleset->settings);
        if ($rules !== null) {
            $this->credit($context, $nation, $rules->moneyPoints($amount), 'money', $amount, $source);
        }
    }

    private function credit(TurnContext|WorldEventContext $context, Nation $nation, int $points, string $asset, int $discarded, string $source): void
    {
        if ($points === 0) {
            return;
        }
        DB::transaction(function () use ($context, $nation, $points, $asset, $discarded, $source): void {
            $locked = Nation::query()->whereKey($nation->id)->lockForUpdate()->firstOrFail();
            $before = (int) $locked->ecopo;
            if ($before > PHP_INT_MAX - $points) {
                throw new DomainException('Ecopo balance would overflow.');
            }
            $locked->update(['ecopo' => $before + $points]);
            $this->events->record($context, 'ecopo.credited', $locked, [
                'asset' => $asset, 'discarded' => $discarded, 'points' => $points,
                'before' => $before, 'after' => $before + $points, 'source' => $source,
            ], 'nation');
        }, 1);
    }

    /** @return array{ecopo_exchanges: int, ecopo_collected: int, ecopo_paradox: int} */
    public function exchangeForTurn(TurnContext $context): array
    {
        $metrics = ['ecopo_exchanges' => 0, 'ecopo_collected' => 0, 'ecopo_paradox' => 0];
        $rules = EcopoRules::fromSettings($context->ruleset->settings);
        if ($rules === null) {
            return $metrics;
        }

        return DB::transaction(function () use ($context, $rules, $metrics): array {
            $nations = Nation::query()->where('world_id', $context->world->id)
                ->whereIn('state', ['active', 'recovery', 'dormant'])
                ->where('ecopo', '>=', $rules->pointsPerParadox)->orderBy('id')->lockForUpdate()->get();
            $owners = NationMembership::query()->whereIn('nation_id', $nations->modelKeys())
                ->where('world_id', $context->world->id)->where('role', 'owner')->pluck('user_id', 'nation_id');
            foreach ($nations as $nation) {
                $owner = $owners->get($nation->id);
                if ($owner === null) {
                    throw new DomainException('Ecopo exchange requires the current island owner.');
                }
                $before = (int) $nation->ecopo;
                $amount = intdiv($before, $rules->pointsPerParadox);
                $collected = $amount * $rules->pointsPerParadox;
                $result = $this->paradox->credit(
                    (int) $owner, $amount,
                    "ecopo:{$context->world->id}:{$context->targetTurn}:{$nation->id}",
                    'compensation', metadata: ['reason' => 'ecopo_exchange', 'world_id' => $context->world->id, 'nation_id' => $nation->id,
                        'turn' => $context->targetTurn, 'collected_points' => $collected],
                );
                if ($result['duplicate']) {
                    continue;
                }
                $nation->update(['ecopo' => $before - $collected]);
                $this->events->record($context, 'ecopo.exchanged', $nation, [
                    'ecopo_before' => $before, 'ecopo_after' => $before - $collected,
                    'collected_points' => $collected, 'paradox' => $amount,
                ], 'nation');
                $metrics['ecopo_exchanges']++;
                $metrics['ecopo_collected'] += $collected;
                $metrics['ecopo_paradox'] += $amount;
            }

            return $metrics;
        }, 1);
    }
}
