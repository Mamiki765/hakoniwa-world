<?php

namespace Tests\Support;

use Tests\Concerns\UsesReusableSurfaceWorld;
use Tests\TestCase;

final class ReusableSurfaceTemplateBuilderTest extends TestCase
{
    use UsesReusableSurfaceWorld;

    public function test_builds_the_reusable_surface_template_baseline(): void
    {
        $world = $this->lightweightWorld();

        self::assertSame('shared-world', $world->key);
    }
}
