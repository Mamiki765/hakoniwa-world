<?php

namespace App\Console\Commands;

use App\Application\ModerationRecordService;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class RecordModeration extends Command
{
    protected $signature = 'hakoniwa:moderation-record
                            {category : Lowercase record category, for example policy-violation or resolved}
                            {target-type : nation or user}
                            {target-id : Nation or User ID}
                            {--operator= : Operator identifier recorded in the audit}
                            {--summary= : Single-line factual summary}
                            {--confirm= : Exact category:target-type:target-id confirmation}';

    protected $description = 'Record a moderation note without changing any User, Nation, map, or turn state.';

    public function handle(ModerationRecordService $records): int
    {
        $category = (string) $this->argument('category');
        $targetType = (string) $this->argument('target-type');
        $targetId = filter_var($this->argument('target-id'), FILTER_VALIDATE_INT);
        if (! is_int($targetId) || $targetId < 1) {
            $this->error('target-id must be a positive integer.');

            return self::FAILURE;
        }
        $confirmation = "{$category}:{$targetType}:{$targetId}";
        if (! hash_equals($confirmation, (string) $this->option('confirm'))) {
            $this->error("Refusing to record. Re-run with --confirm={$confirmation}");

            return self::FAILURE;
        }

        try {
            $result = $records->record(
                $category,
                $targetType,
                $targetId,
                (string) $this->option('operator'),
                (string) $this->option('summary'),
            );
        } catch (DomainException|ModelNotFoundException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'moderation_record=%d category=%s target=%s:%d recorded; no gameplay state changed',
            $result['record_id'],
            $result['category'],
            $result['target_type'],
            $result['target_id'],
        ));

        return self::SUCCESS;
    }
}
