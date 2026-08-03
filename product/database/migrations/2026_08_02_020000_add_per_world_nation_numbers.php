<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const POSITIVE_CONSTRAINT = 'nations_nation_number_positive';

    private const UNIQUE_INDEX = 'nations_world_id_nation_number_unique';

    public function up(): void
    {
        Schema::table('nations', function (Blueprint $table): void {
            $table->unsignedInteger('nation_number')->nullable()->after('world_id');
        });

        DB::statement('LOCK TABLE nations IN ACCESS EXCLUSIVE MODE');
        DB::statement(<<<'SQL'
            WITH ranked AS (
                SELECT id,
                    ROW_NUMBER() OVER (PARTITION BY world_id ORDER BY id)::integer AS nation_number
                FROM nations
            )
            UPDATE nations
            SET nation_number = ranked.nation_number
            FROM ranked
            WHERE nations.id = ranked.id
            SQL);
        DB::statement('ALTER TABLE nations ALTER COLUMN nation_number SET NOT NULL');
        DB::statement(
            'ALTER TABLE nations ADD CONSTRAINT '.self::POSITIVE_CONSTRAINT.' CHECK (nation_number > 0)',
        );

        Schema::table('nations', function (Blueprint $table): void {
            $table->unique(['world_id', 'nation_number'], self::UNIQUE_INDEX);
        });
    }

    public function down(): void
    {
        Schema::table('nations', function (Blueprint $table): void {
            $table->dropUnique(self::UNIQUE_INDEX);
        });
        DB::statement('ALTER TABLE nations DROP CONSTRAINT IF EXISTS '.self::POSITIVE_CONSTRAINT);
        Schema::table('nations', function (Blueprint $table): void {
            $table->dropColumn('nation_number');
        });
    }
};
