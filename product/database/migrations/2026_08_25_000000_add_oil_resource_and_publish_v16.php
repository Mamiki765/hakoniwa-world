<?php

use App\Application\Ver260OilResourceRulesetUpgrade;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        app(Ver260OilResourceRulesetUpgrade::class)->run();
    }

    public function down(): void
    {
        throw new RuntimeException(
            'The v15 to v16 oil resource migration is forward-only; restore the exact supported v15 backup and re-upgrade.',
        );
    }
};
