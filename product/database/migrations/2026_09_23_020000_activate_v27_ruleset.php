<?php

use App\Application\Ver440RulesetUpgrade;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        app(Ver440RulesetUpgrade::class)->run();
    }

    public function down(): void
    {
        throw new RuntimeException(
            'The 4.4.0 Ruleset activation is forward-only; restore the verified pre-migration backup.',
        );
    }
};
