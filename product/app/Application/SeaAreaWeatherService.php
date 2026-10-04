<?php

namespace App\Application;

use App\Domain\Disaster\HugeMeteorWeatherDistribution;
use App\Domain\Disaster\SeaAreaWeatherLottery;
use App\Domain\Map\GridCoordinate;
use App\Domain\Turn\TurnContext;
use App\Domain\Turn\TurnRandomStreamFactory;
use App\Models\MapChunk;
use App\Models\MapSpace;
use Illuminate\Support\Facades\DB;

final class SeaAreaWeatherService
{
    public function __construct(private readonly SeaAreaWeatherLottery $lottery) {}

    public function draw(TurnContext $context, MapSpace $space): int
    {
        if ($context->state->hasSeaAreaWeather()) {
            return count($context->state->seaAreaWeather());
        }
        $settings = $context->ruleset->settings['turn_processing']['sea_area_weather'];
        $bounds = $space->currentBounds();
        $hugeMeteor = $context->ruleset->settings['turn_processing']['disasters']['huge_meteor'];
        $distribution = new HugeMeteorWeatherDistribution($bounds, $hugeMeteor['center_padding'], $hugeMeteor['probability']);
        $outsideStream = $context->random->stream(TurnRandomStreamFactory::weatherHugeMeteorOutside($settings['stream_version']));
        $centers = [];
        $outsideCount = $distribution->drawOutsideCount($outsideStream);
        for ($index = 0; $index < $outsideCount; $index++) {
            $centers[] = $distribution->drawOutsideCenter($outsideStream);
        }
        $chunks = MapChunk::query()->where('map_space_id', $space->id)
            ->whereExists(function ($query) use ($space): void {
                $query->selectRaw('1')->from('map_cells')
                    ->whereColumn('map_cells.map_chunk_id', 'map_chunks.id')
                    ->whereBetween('x', [$space->min_x, $space->max_x])
                    ->whereBetween('y', [$space->min_y, $space->max_y]);
            })->orderBy('chunk_y')->orderBy('chunk_x')->lockForUpdate()->get();
        $records = [];
        $updates = [];
        foreach ($chunks as $chunk) {
            $region = $bounds->intersectionWithChunk($chunk->chunk_x, $chunk->chunk_y);
            if ($region === null) {
                continue;
            }
            $width = $region['max_x'] - $region['min_x'] + 1;
            $area = $width * ($region['max_y'] - $region['min_y'] + 1);
            $key = $this->lottery->draw($settings, $distribution->internalProbability($area),
                $context->random->stream(TurnRandomStreamFactory::seaAreaWeather($chunk->chunk_x, $chunk->chunk_y, $settings['stream_version'])));
            if ($key === 'huge_meteor') {
                $impact = $context->random->stream(TurnRandomStreamFactory::seaAreaWeatherEffect($key, $chunk->chunk_x, $chunk->chunk_y, $settings['stream_version']))->integer(0, $area - 1);
                $centers[] = new GridCoordinate($region['min_x'] + $impact % $width, $region['min_y'] + intdiv($impact, $width));
            }
            $records[$chunk->id] = [
                'weather_key' => $key,
                'chunk_x' => $chunk->chunk_x, 'chunk_y' => $chunk->chunk_y,
                ...$region,
            ];
            $updates[] = [
                'id' => $chunk->id, 'map_space_id' => $space->id,
                'chunk_x' => $chunk->chunk_x, 'chunk_y' => $chunk->chunk_y,
                'weather_key' => $key, 'weather_turn' => $context->targetTurn,
            ];
        }
        if ($updates !== []) {
            // Only the last processed Turn is stored; rollback/retry follows the World transaction.
            DB::table('map_chunks')->upsert($updates, ['id'], ['weather_key', 'weather_turn']);
        }
        $context->state->setSeaAreaWeather($records);
        $context->state->setWeatherHugeMeteorCenters($centers);

        return count($records);
    }
}
