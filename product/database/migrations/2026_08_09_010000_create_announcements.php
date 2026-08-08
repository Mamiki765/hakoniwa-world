<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 160);
            $table->text('body');
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index(['created_at', 'id']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException(
            'The announcements migration is forward-only because operator-authored production articles must not be destroyed.',
        );
    }
};
