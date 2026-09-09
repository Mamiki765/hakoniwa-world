<?php

namespace Tests\Feature;

use Tests\TestCase;

final class Ver381PresentationContractTest extends TestCase
{
    public function test_party_portrait_events_keep_four_columns_at_every_breakpoint(): void
    {
        $css = file_get_contents(resource_path('css/hakoniwa.css'));
        $this->assertIsString($css);
        $this->assertStringContainsString(
            '.underground-party-portrait-events { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr));',
            $css,
        );
        preg_match_all(
            '/\.underground-party-portrait-events\s*\{[^}]*grid-template-columns\s*:/',
            $css,
            $overrides,
        );
        $this->assertCount(1, $overrides[0]);
    }
}
