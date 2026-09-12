<?php

namespace Tests\Underground\Unit;

use App\Application\Underground\UndergroundIntroCatalog;
use Tests\TestCase;

final class UndergroundIntroCatalogTest extends TestCase
{
    public function test_intro_catalog_remains_the_versioned_story_and_recollection_source(): void
    {
        $catalog = app(UndergroundIntroCatalog::class);

        $this->assertNotSame('', $catalog->identity());
        $this->assertArrayHasKey('identity', $catalog->recollections());
        $this->assertArrayHasKey('past', $catalog->recollections());
        $this->assertArrayHasKey('serious_talk', $catalog->recollections());
        $this->assertSame('案内人', $catalog->normalizeShopkeeperName('  案内人  '));
    }
}
