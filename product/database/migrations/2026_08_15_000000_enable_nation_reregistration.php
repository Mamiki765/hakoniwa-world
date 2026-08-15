<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nation_creation_requests', function (Blueprint $table): void {
            $table->dropUnique('nation_creation_requests_user_id_world_id_unique');
            $table->index(
                ['user_id', 'world_id'],
                'nation_creation_requests_user_world_index',
            );
        });
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Nation creation request history is forward-only after re-registration is enabled.',
        );
    }
};
