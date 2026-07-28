<?php

namespace App\Domain\Economy;

use App\Models\Nation;

interface CapacityModifier
{
    public function moneyCapacityDelta(Nation $nation): int;

    public function foodCapacityTonsDelta(Nation $nation): int;
}
