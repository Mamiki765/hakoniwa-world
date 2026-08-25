<?php

namespace App\Console\Commands;

use App\Domain\Ruleset\RulesetAuthoringValidator;
use DomainException;
use Illuminate\Console\Command;

final class ValidateRuleset extends Command
{
    protected $signature = 'hakoniwa:ruleset:validate {--key= : Current authoring key}';

    protected $description = 'Validate the current authored ruleset without publishing it or changing a World';

    public function handle(RulesetAuthoringValidator $validator): int
    {
        $settings = config('hakoniwa.ruleset');
        if (! is_array($settings)) {
            $this->error('The current Ruleset authoring is not configured.');

            return self::FAILURE;
        }
        $currentKey = $settings['key'] ?? null;
        if (! is_string($currentKey) || $currentKey === '') {
            $this->error('The current Ruleset authoring has no valid key.');

            return self::FAILURE;
        }
        $key = $this->option('key');
        if ($key === null || $key === '') {
            $key = $currentKey;
        }
        if ($key !== $currentKey) {
            $this->error("Ruleset authoring key {$key} is not the current key {$currentKey}.");

            return self::FAILURE;
        }

        try {
            $summary = $validator->validate($settings);
        } catch (DomainException $exception) {
            $this->error("Ruleset {$key} is invalid: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->info(
            "Ruleset {$summary['key']} is valid: version={$summary['version']} "
            ."resources={$summary['resources']} facilities={$summary['facilities']} "
            ."commands={$summary['commands']} production={$summary['production']}.",
        );

        return self::SUCCESS;
    }
}
