<?php

namespace Tests\Concerns;

use App\Application\RulesetPublisher;
use App\Domain\Ruleset\RulesetUpgradeAuthoringCatalog;
use App\Models\MonsterDefinition;
use RuntimeException;

trait UsesHistoricalRulesetDatabaseFixtures
{
    protected function setUpUsesHistoricalRulesetDatabaseFixtures(): void
    {
        $publisher = app(RulesetPublisher::class);
        $currentRulesetId = null;
        foreach (app(RulesetUpgradeAuthoringCatalog::class)->all() as $settings) {
            $ruleset = $publisher->publish($settings);
            if ($ruleset->key === config('hakoniwa.ruleset.key')) {
                $currentRulesetId = (int) $ruleset->id;
            }
        }
        if ($currentRulesetId === null) {
            throw new RuntimeException('The current Ruleset fixture was not published.');
        }

        // The display-order column was added after v10 publication. The historical
        // chain left all existing definition rows null and populated only v11.
        MonsterDefinition::query()->where('ruleset_version_id', '<>', $currentRulesetId)
            ->update(['display_order' => null]);
    }
}
