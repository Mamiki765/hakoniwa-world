<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        // Additive nodes do not invalidate acquired skills, slots, AI or earned points.
        DB::table('underground_profiles')
            ->where('skill_tree_identity', 'secretary-underground-skill-tree-alpha-v3')
            ->update(['skill_tree_identity' => 'secretary-underground-skill-tree-alpha-v4']);
    }

    public function down(): void
    {
        throw new RuntimeException('The skill tree upgrade is forward-only; restore the verified pre-migration backup.');
    }
};
