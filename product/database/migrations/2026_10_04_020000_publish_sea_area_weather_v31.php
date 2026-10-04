<?php

use App\Application\PowerEconomyUpgrade;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        Schema::table('map_chunks', function (Blueprint $table): void {
            $table->string('weather_key')->nullable();
            $table->unsignedBigInteger('weather_turn')->nullable();
        });
        DB::statement("ALTER TABLE map_chunks ADD CONSTRAINT map_chunks_weather_check CHECK ((weather_key IS NULL AND weather_turn IS NULL) OR (weather_key IS NOT NULL AND weather_turn IS NOT NULL AND weather_turn >= 0 AND weather_key IN ('sunny', 'cloudy', 'rain', 'snow', 'thunder', 'typhoon', 'meteor_shower', 'huge_meteor')))");
        app(PowerEconomyUpgrade::class)->enableSeaAreaWeather();
    }

    public function down(): void
    {
        throw new RuntimeException('Sea-area weather is forward-only. Restore a reviewed backup instead of changing Turn provenance.');
    }
};
