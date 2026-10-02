<?php

use App\Application\CurrentDatabaseBaseline;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        app(CurrentDatabaseBaseline::class)->install();
    }

    public function down(): void
    {
        throw new RuntimeException('The accepted 4.9.0 baseline is forward-only. Restore a reviewed backup instead of rolling back user data.');
    }
};
