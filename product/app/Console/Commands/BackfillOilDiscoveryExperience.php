<?php

namespace App\Console\Commands;

use App\Application\OilDiscoveryExperienceBackfill;
use App\Models\World;
use Illuminate\Console\Command;
use RuntimeException;

final class BackfillOilDiscoveryExperience extends Command
{
    protected $signature = 'hakoniwa:backfill-oil-discovery {--world= : World ID} {--dry-run : Read candidates only (default)} {--apply : Apply once after review}';

    protected $description = 'Preview the fixed pre-v30 oil discovery XP batch; writes only with --apply.';

    public function handle(OilDiscoveryExperienceBackfill $backfill): int
    {
        if (! ctype_digit((string) $this->option('world')) || (int) $this->option('world') < 1
            || ($this->option('apply') && $this->option('dry-run'))) {
            $this->error('Specify --world=<id> and either --dry-run or --apply.');

            return self::FAILURE;
        }
        $world = World::query()->findOrFail((int) $this->option('world'));
        try {
            $report = $this->option('apply') ? $backfill->apply($world) : $backfill->preview($world);
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        } catch (RuntimeException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
