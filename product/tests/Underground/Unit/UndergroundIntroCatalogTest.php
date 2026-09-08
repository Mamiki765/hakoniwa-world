<?php

namespace Tests\Underground\Unit;

use App\Application\Underground\UndergroundIntroCatalog;
use Tests\TestCase;

final class UndergroundIntroCatalogTest extends TestCase
{
    public function test_guide_banter_is_a_valid_display_only_catalog(): void
    {
        $catalog = app(UndergroundIntroCatalog::class);
        $entries = $catalog->guideBanter();
        $selected = $catalog->randomGuideBanter();

        $this->assertNotEmpty($entries);
        $this->assertContains($selected, $entries);
        $this->assertSame(['key', 'text'], array_keys($selected));
        $this->assertNotSame('', $selected['text']);
        $this->assertSame(
            $catalog->stableGuideBanter('secretary:19:2026-09-08'),
            $catalog->stableGuideBanter('secretary:19:2026-09-08'),
        );
        $this->assertContains($catalog->stableGuideBanter('secretary:19:2026-09-08'), $entries);
    }
}
