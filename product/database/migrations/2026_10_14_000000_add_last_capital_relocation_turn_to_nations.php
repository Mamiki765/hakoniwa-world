<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        Schema::table('nations', function (Blueprint $table): void {
            $table->bigInteger('last_capital_relocation_turn')->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Capital relocation history is forward-only. Restore a reviewed backup instead.');
    }
};
