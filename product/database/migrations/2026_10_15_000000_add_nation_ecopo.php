<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nations', fn (Blueprint $table) => $table->bigInteger('ecopo')->default(0));
        DB::statement('ALTER TABLE nations ADD CONSTRAINT nations_ecopo_nonnegative CHECK (ecopo >= 0)');
    }

    public function down(): void
    {
        throw new RuntimeException('Ecopo balances are forward-only. Restore a reviewed backup instead.');
    }
};
