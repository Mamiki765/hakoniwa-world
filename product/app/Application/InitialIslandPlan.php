<?php

namespace App\Application;

final readonly class InitialIslandPlan
{
    /**
     * @param  list<int>  $changedCellIds
     * @param  array<int, array<string, int|string|null>>  $cellPrestates
     * @param  array<int, array<string, int|string|null>>  $cellWrites
     * @param  list<int>  $changedChunkIds
     */
    public function __construct(
        public int $mapSpaceId,
        public int $nationId,
        public int $rulesetVersionId,
        public int $centerX,
        public int $centerY,
        public string $seed,
        public array $changedCellIds,
        public array $cellPrestates,
        public array $cellWrites,
        public array $changedChunkIds,
        public int $capitalCellId,
    ) {}
}
