<?php

namespace Tests\Unit;

use Tests\TestCase;

final class Ver230DocumentationContractTest extends TestCase
{
    public function test_player_announcement_and_manual_numbers_follow_formal_v11(): void
    {
        $settings = config('hakoniwa.published_rulesets.hakoniwa-2s-plus-v11');
        $announcement = $this->document('docs/ver-2.3.0-announcement.md');
        $secretaryManual = $this->document('docs/manual/secretary.md');
        $advancedManual = $this->document('docs/manual/advanced.md');
        $oldBow = $settings['secretary']['items']['old_bow']['effects'][0];
        $ring = $settings['secretary']['items']['ring']['effects'][0];
        $monsters = collect($settings['monster_definitions'])->keyBy('key');
        $dispatch = collect($settings['command_definitions'])->firstWhere('key', 'monster_dispatch');

        $this->assertStringContainsString(($oldBow['chance_basis_points'] / 100).'%の確率', $announcement);
        $this->assertStringContainsString($oldBow['damage'].'ダメージ', $announcement);
        $this->assertStringContainsString(($oldBow['chance_basis_points'] / 100).'%の確率', $secretaryManual);
        $this->assertStringContainsString('追加分は5億円', $secretaryManual);
        $this->assertSame(1, $ring['bonus_money_per_level']);
        $this->assertStringContainsString('現在、指輪の通常の入手経路はありません。', $announcement);
        $this->assertStringContainsString('現在、指輪の通常の入手経路はありません。', $secretaryManual);

        foreach ($dispatch['metadata']['monster_dispatch_options'] as $option) {
            $value = number_format($option['cost_money']).'億円';
            $this->assertStringContainsString($value, $announcement);
            $this->assertStringContainsString($value, $advancedManual);
        }
        $aoiValue = number_format($monsters['aoi_inora']['wreckage_value_money']).'億円';
        $this->assertStringContainsString($aoiValue, $announcement);
        $this->assertStringContainsString($aoiValue, $advancedManual);
        $this->assertStringContainsString('HP1', $announcement);
        $this->assertStringContainsString('HP1', $advancedManual);

        foreach ([
            'request fingerprint', 'request provenance', 'selector storage', 'random stream',
            'trigger', 'test fixture', 'checkpoint',
        ] as $internalTerm) {
            $this->assertStringNotContainsStringIgnoringCase($internalTerm, $announcement);
        }
    }

    public function test_operator_and_durable_release_docs_pin_identity_and_recovery_boundaries(): void
    {
        $release = $this->document('docs/ver-2.3.0-v11-release.md');
        $operator = $this->document('docs/operations/ver-2.3.0-v11-migration.md');
        $handoff = $this->document('docs/post-2.3.0-simplification-handoff.md');
        $checksum = hash('sha256', json_encode(
            config('hakoniwa.published_rulesets.hakoniwa-2s-plus-v11'),
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
        ));

        foreach ([$release, $operator] as $document) {
            $this->assertStringContainsString('hakoniwa-2s-plus-v11', $document);
            $this->assertStringContainsString($checksum, $document);
        }
        foreach (['pending', 'running', 'failed', 'blocked'] as $status) {
            $this->assertStringContainsString("`{$status}`", $operator);
        }
        foreach ([
            'queued', 'alive MonsterInstance', 'NationMonsterKillStat',
            'request provenance', 'trigger', 'backup', 'restore', 'migrate:status',
        ] as $postcondition) {
            $this->assertStringContainsStringIgnoringCase($postcondition, $operator);
        }
        foreach ([
            'Ruleset core', 'Versioned balance profile', 'Flavor and presentation',
            'CompleteTurnEngine', 'requires measurement', 'remove after migration',
            'no mixed gameplay + cleanup PR',
        ] as $intent) {
            $this->assertStringContainsString($intent, $handoff);
        }
    }

    private function document(string $path): string
    {
        $document = file_get_contents(base_path($path));
        $this->assertIsString($document);

        return $document;
    }
}
