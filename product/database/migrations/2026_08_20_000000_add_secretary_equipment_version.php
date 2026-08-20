<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('secretaries', function (Blueprint $table): void {
            $table->unsignedBigInteger('equipment_version')->default(1);
        });
        DB::statement(<<<'SQL'
ALTER TABLE secretaries
ADD CONSTRAINT secretaries_equipment_version_check CHECK (equipment_version >= 1)
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException(
            'The Secretary equipment version migration is forward-only; restore through an explicit reviewed conversion.',
        );
    }
};
