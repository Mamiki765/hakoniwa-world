<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('guard_test')->create('guard_records', function (Blueprint $table): void {
            $table->id();
        });
    }

    public function down(): void
    {
        Schema::connection('guard_test')->dropIfExists('guard_records');
    }
};
