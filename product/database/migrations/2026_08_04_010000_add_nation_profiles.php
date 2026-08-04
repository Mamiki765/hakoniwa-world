<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nations', function (Blueprint $table): void {
            $table->string('owner_name', 30)->default('')->after('name');
            $table->string('profile_comment', 100)->default('')->after('owner_name');
        });
    }

    public function down(): void
    {
        Schema::table('nations', function (Blueprint $table): void {
            $table->dropColumn(['owner_name', 'profile_comment']);
        });
    }
};
