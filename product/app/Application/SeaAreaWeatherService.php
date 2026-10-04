<?php

namespace App\Application;

use App\Domain\Disaster\SeaAreaWeatherLottery;
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
        $size = $context->ruleset->settings['chunk_size'];
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
            $draw = $context->random->stream(TurnRandomStreamFactory::seaAreaWeather(
                $chunk->chunk_x, $chunk->chunk_y, $settings['stream_version'],
            ))->integer(0, $settings['denominator'] - 1);
            $key = $this->lottery->select($settings, $draw);
            $records[$chunk->id] = [
                'weather_key' => $key,
                'chunk_x' => $chunk->chunk_x, 'chunk_y' => $chunk->chunk_y,
                'min_x' => max($space->min_x, $chunk->chunk_x * $size),
                'max_x' => min($space->max_x, ($chunk->chunk_x + 1) * $size - 1),
                'min_y' => max($space->min_y, $chunk->chunk_y * $size),
                'max_y' => min($space->max_y, ($chunk->chunk_y + 1) * $size - 1),
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

        return count($records);
    }
}
