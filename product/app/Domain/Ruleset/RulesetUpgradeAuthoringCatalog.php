<?php

namespace App\Domain\Ruleset;

use RuntimeException;

final class RulesetUpgradeAuthoringCatalog
{
    /** @var list<string> */
    private const FILES = [
        'roadmap-pr2-v1.php',
        'roadmap-pr6-v1.php',
        'roadmap-pr7-v1.php',
        'roadmap-pr11-v1.php',
        'roadmap-pr14-v1.php',
        'roadmap-pr15-v1.php',
        'roadmap-pr18-v1.php',
        'roadmap-pr19-v1.php',
        'roadmap-pr21-v1.php',
        'roadmap-pr22-v1.php',
        'hakoniwa-2s-plus-v1.php',
        'hakoniwa-2s-plus-v2.php',
        'hakoniwa-2s-plus-v3.php',
        'hakoniwa-2s-plus-v4.php',
        'hakoniwa-2s-plus-v5.php',
        'hakoniwa-2s-plus-v6.php',
        'hakoniwa-2s-plus-v7.php',
        'hakoniwa-2s-plus-v8.php',
        'hakoniwa-2s-plus-v9.php',
        'hakoniwa-2s-plus-v10.php',
        'hakoniwa-2s-plus-v11.php',
        'hakoniwa-2s-plus-v12.php',
        'hakoniwa-2s-plus-v13.php',
        'hakoniwa-2s-plus-v14.php',
    ];

    /** @var array<string, array<string, mixed>>|null */
    private ?array $rulesets = null;

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        return $this->rulesets ??= RulesetAuthoringCollection::fromFiles(array_map(
            static fn (string $file): string => config_path('hakoniwa/rulesets/'.$file),
            self::FILES,
        ))->all();
    }

    /** @return array<string, mixed> */
    public function get(string $key): array
    {
        $rulesets = $this->all();
        $ruleset = $rulesets[$key] ?? null;
        if (! is_array($ruleset)) {
            throw new RuntimeException("Upgrade Ruleset authoring key {$key} does not exist.");
        }

        return $ruleset;
    }
}
