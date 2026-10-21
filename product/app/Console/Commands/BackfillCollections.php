<?php

namespace App\Console\Commands;

use App\Application\CollectionBackfill;
use Illuminate\Console\Command;

final class BackfillCollections extends Command
{
    protected $signature = 'hakoniwa:backfill-collections {--dry-run : Read candidates only (default)} {--apply : Register the candidates; safe to repeat}';

    protected $description = 'Preview or register monster and item collection entries from existing records.';

    public function handle(CollectionBackfill $backfill): int
    {
        if ($this->option('apply') && $this->option('dry-run')) {
            $this->error('Specify either --dry-run or --apply.');

            return self::FAILURE;
        }
        $report = $this->option('apply') ? $backfill->apply() : $backfill->preview();
        $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
