<?php

use App\Application\Ver240InstallUpgradeRebaseline;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        app(Ver240InstallUpgradeRebaseline::class)->run();
    }

    public function down(): void
    {
        throw new RuntimeException(
            'The ver 2.4.0 install/upgrade rebaseline is forward-only; restore the supported ver 2.3.1/v11 backup.',
        );
    }
};
