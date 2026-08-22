<?php

namespace App\Console\Commands;

use App\Domain\Ruleset\RulesetAuthoringValidator;
use App\Domain\Ruleset\RulesetUpgradeAuthoringCatalog;
use DomainException;
use Illuminate\Console\Command;

final class ValidateRuleset extends Command
{
    protected $signature = 'hakoniwa:ruleset:validate {--key= : Authoring version key}';

    protected $description = 'Validate an authored ruleset without publishing it or changing a World';

    public function handle(
        RulesetAuthoringValidator $validator,
        RulesetUpgradeAuthoringCatalog $authoredRulesets,
    ): int {
        $key = $this->option('key');
        if (! is_string($key) || $key === '') {
            $this->error('A non-empty --key is required.');

            return self::FAILURE;
        }

        $rulesets = $authoredRulesets->all();
        $configuredRulesets = config('hakoniwa.published_rulesets');
        if (is_array($configuredRulesets)) {
            $rulesets = array_replace($rulesets, $configuredRulesets);
        }
        $settings = array_key_exists($key, $rulesets)
            ? $rulesets[$key]
            : null;
        if (! is_array($settings)) {
            $this->error("Ruleset authoring key {$key} does not exist.");

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
