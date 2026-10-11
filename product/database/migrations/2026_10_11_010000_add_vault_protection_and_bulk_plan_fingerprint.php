<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('underground_owned_equipment', function (Blueprint $table): void {
            $table->boolean('is_protected')->default(false);
        });
        Schema::table('nation_command_queue_bulk_requests', function (Blueprint $table): void {
            $table->string('request_fingerprint', 64)->nullable();
        });
        // Preserve the installed operation set, including other supported upgrades.
        $constraint = DB::selectOne("SELECT pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conrelid = 'underground_intro_requests'::regclass AND conname = 'underground_intro_requests_operation_check'");
        if ($constraint === null || ! str_starts_with($constraint->definition, 'CHECK (')) {
            throw new RuntimeException('Underground request operation constraint is missing.');
        }
        $expression = substr($constraint->definition, 6);
        DB::statement('ALTER TABLE underground_intro_requests DROP CONSTRAINT underground_intro_requests_operation_check');
        DB::statement("ALTER TABLE underground_intro_requests ADD CONSTRAINT underground_intro_requests_operation_check CHECK ({$expression} OR operation = 'equipment_protection')");
    }

    public function down(): void
    {
        throw new RuntimeException('Vault protection and accepted request identities are forward-only.');
    }
};
