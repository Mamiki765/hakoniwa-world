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
        Schema::create('underground_battle_image_references', function (Blueprint $table): void {
            $table->id();
            // A request-keyed row is created while a party snapshot is being
            // prepared. It is promoted to a battle row in the settlement
            // transaction, so an image replacement cannot win the gap between
            // snapshot preparation and battle creation.
            $table->string('reference_key', 64);
            $table->foreignId('underground_battle_id')
                ->nullable()
                ->constrained('underground_battles')
                // Keep the explicit retention lease if an administrative
                // cleanup removes a battle row before its log lifetime ends.
                ->nullOnDelete();
            $table->timestampTz('retained_until');
            $table->string('path', 80);
            $table->timestamps();
            $table->unique(
                ['reference_key', 'path'],
                'underground_battle_image_refs_key_path_unique',
            );
            $table->index(
                ['path', 'retained_until'],
                'underground_battle_image_refs_path_until_index',
            );
            $table->index(
                ['underground_battle_id', 'path'],
                'underground_battle_image_refs_battle_path_index',
            );
        });

        DB::statement("ALTER TABLE underground_battle_image_references ADD CONSTRAINT underground_battle_image_refs_path_check CHECK (path ~ '^[0-9a-f]{64}\\.(png|jpg|webp|gif)$')");
    }

    public function down(): void
    {
        throw new RuntimeException(
            'The 3.8.0 Underground battle image-reference migration is forward-only; restore the verified pre-migration backup.',
        );
    }
};
