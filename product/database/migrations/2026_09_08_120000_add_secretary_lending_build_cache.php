<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        Schema::create('secretary_lending_build_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('secretary_id')->unique()->constrained('secretaries')->cascadeOnDelete();
            $table->string('build_identity', 100);
            $table->char('source_fingerprint', 64);
            $table->jsonb('source_snapshot');
            $table->timestamps();
        });
        DB::statement(<<<'SQL'
ALTER TABLE secretary_lending_build_snapshots
  ADD CONSTRAINT secretary_lending_build_snapshots_fingerprint_check
  CHECK (source_fingerprint ~ '^[0-9a-f]{64}$')
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('The 3.8.0 Secretary lending build cache migration is forward-only.');
    }
};
