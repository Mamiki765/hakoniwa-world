<?php

namespace Tests\Support;

final class ReusableSurfaceWorldState
{
    public static int $generationCount = 0;

    public static float $generationSeconds = 0.0;

    public static ?string $database = null;

    public static ?string $worldKey = null;
}
