<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('command_definitions')
            ->where('key', 'excavate')
            ->orderBy('id')
            ->each(function (object $definition): void {
                $metadata = json_decode((string) $definition->metadata, true, 512, JSON_THROW_ON_ERROR);
                $metadata['parameters'] = [
                    'quantity' => [
                        'label' => '数量',
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => 99,
                        'default' => 1,
                        'quick_presets' => [1, 5, 10, 25, 50, 99],
                        'required' => true,
                        'meaning' => 'turn engineで実行する掘削回数。PR #5では予約・表示・検証だけを行う。',
                    ],
                ];

                DB::table('command_definitions')->where('id', $definition->id)->update([
                    'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        DB::table('command_definitions')
            ->where('key', 'excavate')
            ->orderBy('id')
            ->each(function (object $definition): void {
                $metadata = json_decode((string) $definition->metadata, true, 512, JSON_THROW_ON_ERROR);
                unset($metadata['parameters']);

                DB::table('command_definitions')->where('id', $definition->id)->update([
                    'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                ]);
            });
    }
};
