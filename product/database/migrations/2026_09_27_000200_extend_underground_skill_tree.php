<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        // The new node is additive. Existing allocations, active slots, AI and earned SP remain valid.
        DB::table('underground_profiles')
            ->where('skill_tree_identity', 'secretary-underground-skill-tree-alpha-v2')
            ->update(['skill_tree_identity' => 'secretary-underground-skill-tree-alpha-v3']);
    }

    public function down(): void
    {
        throw new RuntimeException('The additive skill tree upgrade is forward-only; restore the verified pre-migration backup.');
    }
};
