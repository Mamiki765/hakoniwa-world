<?php

use App\Application\Underground\UndergroundRuntimeEquipmentGenerator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('underground_owned_equipment', function (Blueprint $table): void {
            $table->unsignedSmallInteger('quality_percent')->nullable();
        });
        $generator = app(UndergroundRuntimeEquipmentGenerator::class);
        DB::table('underground_owned_equipment')->where('instance_kind', 'generated')
            ->select(['id', 'generated_payload'])->orderBy('id')->chunkById(200, function ($rows) use ($generator): void {
                foreach ($rows as $row) {
                    $payload = json_decode($row->generated_payload, true, flags: JSON_THROW_ON_ERROR);
                    DB::table('underground_owned_equipment')->where('id', $row->id)
                        ->update(['quality_percent' => $generator->qualityPercent($payload)]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('underground_owned_equipment', function (Blueprint $table): void {
            $table->dropColumn('quality_percent');
        });
    }
};
