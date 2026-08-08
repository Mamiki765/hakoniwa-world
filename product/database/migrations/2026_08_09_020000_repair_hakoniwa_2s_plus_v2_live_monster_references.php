<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const REPAIR_SQL = 'operations/repair_hakoniwa_2s_plus_v2_live_monster_references.sql';

    public function up(): void
    {
        $path = database_path(self::REPAIR_SQL);
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException("Unable to read live monster reference repair SQL: {$path}");
        }

        DB::transaction(static fn () => DB::unprepared($sql));
    }

    public function down(): void
    {
        throw new RuntimeException(
            'The hakoniwa-2s-plus-v2 live monster reference repair is forward-only and cannot be rolled back destructively.',
        );
    }
};
