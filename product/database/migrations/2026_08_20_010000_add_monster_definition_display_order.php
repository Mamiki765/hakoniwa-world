<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monster_definitions', function (Blueprint $table): void {
            $table->integer('display_order')->nullable();
        });
        DB::statement(<<<'SQL'
ALTER TABLE monster_definitions
ADD CONSTRAINT monster_definitions_display_order_non_negative
CHECK (display_order IS NULL OR display_order >= 0)
SQL);
        DB::statement(<<<'SQL'
CREATE UNIQUE INDEX monster_definitions_ruleset_display_order_unique
ON monster_definitions (ruleset_version_id, display_order)
WHERE display_order IS NOT NULL
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Monster display order is forward-only; restore through an explicit reviewed conversion.',
        );
    }
};
