<?php

namespace App\Console\Commands;

use App\Application\CompensationWarehouseService;
use App\Models\Nation;
use App\Models\World;
use DomainException;
use Illuminate\Console\Command;
use Throwable;

final class CreateCompensationGrant extends Command
{
    protected $signature = 'hakoniwa:compensation-grant
        {--world= : Exact World key}
        {--nation= : Exact Nation name}
        {--key= : Globally unique idempotency key}
        {--operator= : Operator identifier recorded with the grant}
        {--reason= : Player-visible reason}
        {--money=0 : Money in 億円}
        {--wheat=0 : Wheat in tons}
        {--fish=0 : Fish in tons}
        {--meat=0 : Monster meat in tons}
        {--oil=0 : Oil in 万 barrels}
        {--paradox=0 : Surface Paradox in Pd}
        {--skip-tickets=0 : Skip tickets}
        {--g=0 : Underground hand-held shards in G}
        {--confirm= : Must exactly match the command-reported confirmation token}';

    protected $description = 'Create one audited, player-claimable compensation grant without directly changing balances.';

    public function handle(CompensationWarehouseService $warehouse): int
    {
        $worldKey = trim((string) $this->option('world'));
        $nationName = trim((string) $this->option('nation'));
        $grantKey = trim((string) $this->option('key'));
        $operator = trim((string) $this->option('operator'));
        $reason = trim((string) $this->option('reason'));
        if ($worldKey === '' || $nationName === '' || $grantKey === '' || $operator === '' || $reason === '') {
            $this->error('world, nation, key, operator, and reason are required.');

            return self::FAILURE;
        }
        $world = World::query()->where('key', $worldKey)->first();
        if (! $world instanceof World) {
            $this->error("World '{$worldKey}' does not exist.");

            return self::FAILURE;
        }
        $nations = Nation::query()->where('world_id', $world->id)->where('name', $nationName)->get();
        if ($nations->count() !== 1) {
            $this->error("Exact Nation name '{$nationName}' matched {$nations->count()} rows in World '{$worldKey}'.");

            return self::FAILURE;
        }
        $nation = $nations->sole();
        $confirmation = "GRANT:{$worldKey}:N{$nation->id}:{$grantKey}";
        if (! hash_equals($confirmation, (string) $this->option('confirm'))) {
            $this->error("Refusing to create grant. Re-run with --confirm={$confirmation}");

            return self::FAILURE;
        }

        try {
            $assets = [
                'money' => $this->amount('money'),
                'wheat' => $this->amount('wheat'),
                'fish' => $this->amount('fish'),
                'meat' => $this->amount('meat'),
                'oil' => $this->amount('oil'),
                'paradox' => $this->amount('paradox'),
                'skip_ticket' => $this->amount('skip-tickets'),
                'underground_g' => $this->amount('g'),
            ];
            $result = $warehouse->createGrant($nation, $grantKey, $operator, $reason, $assets);
        } catch (Throwable $exception) {
            $this->error('Compensation grant was not created: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'compensation_grant=%d world=%s nation_id=%d nation=%s status=%s',
            $result['grant']->id,
            $worldKey,
            $nation->id,
            $nation->name,
            $result['duplicate'] ? 'already_exists' : 'created',
        ));

        return self::SUCCESS;
    }

    private function amount(string $option): int
    {
        $value = (string) $this->option($option);
        if ($value === '' || ! ctype_digit($value)) {
            throw new DomainException("--{$option} must be a non-negative integer.");
        }
        $amount = filter_var($value, FILTER_VALIDATE_INT);
        if (! is_int($amount) || $amount < 0) {
            throw new DomainException("--{$option} exceeds the supported integer range.");
        }

        return $amount;
    }
}
