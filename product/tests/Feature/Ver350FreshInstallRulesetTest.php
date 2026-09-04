<?php

namespace Tests\Feature;

use App\Application\Ver350RulesetUpgrade;
use App\Models\RulesetVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class Ver350FreshInstallRulesetTest extends TestCase
{
    use RefreshDatabase;

    public function test_fresh_install_keeps_the_immutable_v19_source_snapshot(): void
    {
        $source = RulesetVersion::query()->where('key', Ver350RulesetUpgrade::SOURCE_KEY)->sole();
        RulesetVersion::query()->where('key', Ver350RulesetUpgrade::TARGET_KEY)->delete();

        $this->assertSame('fresh_install_current_v20', app(Ver350RulesetUpgrade::class)->run());
        $this->assertDatabaseHas('ruleset_versions', [
            'id' => $source->id,
            'key' => Ver350RulesetUpgrade::SOURCE_KEY,
            'version' => Ver350RulesetUpgrade::SOURCE_VERSION,
        ]);
        $this->assertDatabaseHas('ruleset_versions', [
            'key' => Ver350RulesetUpgrade::TARGET_KEY,
            'version' => Ver350RulesetUpgrade::TARGET_VERSION,
        ]);
    }
}
