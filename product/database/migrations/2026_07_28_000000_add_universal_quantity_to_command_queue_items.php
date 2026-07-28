<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $items = DB::table('nation_command_queue_items')->orderBy('id')->get(['id', 'parameters']);
        $migrated = [];

        foreach ($items as $item) {
            $parameters = $this->parameters($item->parameters, (int) $item->id);
            $quantity = array_key_exists('quantity', $parameters) ? $parameters['quantity'] : 1;
            if (! is_int($quantity) || $quantity < 1 || $quantity > 99) {
                throw new RuntimeException(
                    "nation_command_queue_items id={$item->id} has invalid parameters.quantity; expected integer 1..99.",
                );
            }
            unset($parameters['quantity']);
            $migrated[(int) $item->id] = ['quantity' => $quantity, 'parameters' => $parameters];
        }

        Schema::table('nation_command_queue_items', function (Blueprint $table): void {
            $table->smallInteger('quantity')->nullable();
        });

        foreach ($migrated as $id => $values) {
            DB::table('nation_command_queue_items')->where('id', $id)->update([
                'quantity' => $values['quantity'],
                'parameters' => $this->encodeParameters($values['parameters']),
            ]);
        }

        DB::statement('ALTER TABLE nation_command_queue_items ALTER COLUMN quantity SET DEFAULT 1');
        DB::statement('ALTER TABLE nation_command_queue_items ALTER COLUMN quantity SET NOT NULL');
        DB::statement(
            'ALTER TABLE nation_command_queue_items '
            .'ADD CONSTRAINT nation_command_queue_items_quantity_check CHECK (quantity BETWEEN 1 AND 99)',
        );

    }

    public function down(): void
    {
        $items = DB::table('nation_command_queue_items')->orderBy('id')->get(['id', 'quantity', 'parameters']);
        $restored = [];

        foreach ($items as $item) {
            $parameters = $this->parameters($item->parameters, (int) $item->id);
            if (array_key_exists('quantity', $parameters)) {
                throw new RuntimeException(
                    "nation_command_queue_items id={$item->id} already has parameters.quantity; rollback would overwrite it.",
                );
            }
            $parameters['quantity'] = (int) $item->quantity;
            $restored[(int) $item->id] = $parameters;
        }

        foreach ($restored as $id => $parameters) {
            DB::table('nation_command_queue_items')->where('id', $id)->update([
                'parameters' => $this->encodeParameters($parameters),
            ]);
        }

        DB::statement(
            'ALTER TABLE nation_command_queue_items DROP CONSTRAINT IF EXISTS nation_command_queue_items_quantity_check',
        );
        Schema::table('nation_command_queue_items', function (Blueprint $table): void {
            $table->dropColumn('quantity');
        });
    }

    /** @return array<string, mixed> */
    private function parameters(mixed $value, int $itemId): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value)) {
            throw new RuntimeException("nation_command_queue_items id={$itemId} has invalid parameters JSON.");
        }

        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new RuntimeException("nation_command_queue_items id={$itemId} parameters must be a JSON object.");
        }

        return $decoded;
    }

    /** @param array<string, mixed> $parameters */
    private function encodeParameters(array $parameters): string
    {
        return json_encode($parameters === [] ? (object) [] : $parameters, JSON_THROW_ON_ERROR);
    }
};
