<?php

namespace App\Services;

use App\Domain\Map\SeaAreaNameResolver;
use App\Models\MapChunk;
use App\Models\MapSpace;

final class SeaAreaWeatherPresenter
{
    private const LABELS = [
        'sunny' => '晴れ', 'cloudy' => '曇り', 'rain' => '雨', 'snow' => '雪',
        'thunder' => '雷', 'typhoon' => '台風', 'meteor_shower' => '流星群', 'huge_meteor' => '終末',
    ];

    public function __construct(private readonly SeaAreaNameResolver $names, private readonly AssetManifestResolver $assets) {}

    /** @return list<array<string, mixed>> */
    public function present(MapSpace $space): array
    {
        if ($space->key !== config('hakoniwa.world.map_space_key') || ! $space->relationLoaded('chunks')) {
            return [];
        }
        $bounds = $space->currentBounds();
        $assets = [];
        $areas = [];
        foreach ($space->chunks->sort(fn (MapChunk $left, MapChunk $right): int => [$left->chunk_y, $left->chunk_x] <=> [$right->chunk_y, $right->chunk_x]) as $chunk) {
            $region = $bounds->intersectionWithChunk($chunk->chunk_x, $chunk->chunk_y);
            if ($region === null) {
                continue;
            }
            // Before the first weather Turn, all existing/new areas are sunny without inventing a recorded Turn.
            $key = $chunk->weather_key ?? 'sunny';
            $weather = null;
            if (isset(self::LABELS[$key])) {
                $assets[$key] ??= $this->assets->resolve('weather.'.$key, self::LABELS[$key]);
                $weather = ['key' => $key, 'label' => self::LABELS[$key], 'turn' => $chunk->weather_turn, 'asset' => $assets[$key]];
            }
            $areas[] = [
                'chunk_x' => $chunk->chunk_x, 'chunk_y' => $chunk->chunk_y,
                'name' => $this->names->forCoordinate($region['min_x'], $region['min_y']),
                'bounds' => $region, 'weather' => $weather,
            ];
        }

        return $areas;
    }
}
