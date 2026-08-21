<?php

use App\Application\RulesetV11MigrationService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(RulesetV11MigrationService::class)->migrate();
    }

    public function down(): void
    {
        throw new RuntimeException(
            'The hakoniwa-2s-plus-v11 production migration is forward-only; restore the approved pre-release backup.',
        );
    }
};
