<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

return new class extends Migration
{
    private const LEGACY_TONS_PER_UNIT = 100;

    public function up(): void
    {
        $balances = $this->foodBalances();
        foreach ($balances as $balance) {
            if ((int) $balance->amount > intdiv(PHP_INT_MAX, self::LEGACY_TONS_PER_UNIT)) {
                throw new RuntimeException(
                    "nation_resources id={$balance->id} resource={$balance->resource_key} "
                    ."balance={$balance->amount} cannot be converted safely to tons.",
                );
            }
        }

        Schema::table('resource_definitions', function (Blueprint $table): void {
            $table->string('unit_label')->nullable()->after('unit');
        });

        foreach ($balances as $balance) {
            DB::table('nation_resources')->where('id', $balance->id)->update([
                'amount' => (int) $balance->amount * self::LEGACY_TONS_PER_UNIT,
            ]);
        }
        DB::table('resource_definitions')->where('category', 'food')->update([
            'unit' => 'ton',
            'unit_label' => 'トン',
            'updated_at' => now(),
        ]);
        DB::table('resource_definitions')->where('key', 'monster_meat')->update([
            'name' => '怪獣肉',
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $balances = $this->foodBalances();
        foreach ($balances as $balance) {
            if ((int) $balance->amount % self::LEGACY_TONS_PER_UNIT !== 0) {
                throw new RuntimeException(
                    "nation_resources id={$balance->id} resource={$balance->resource_key} "
                    ."balance={$balance->amount} is not divisible by 100; refusing lossy rollback.",
                );
            }
        }

        foreach ($balances as $balance) {
            DB::table('nation_resources')->where('id', $balance->id)->update([
                'amount' => intdiv((int) $balance->amount, self::LEGACY_TONS_PER_UNIT),
            ]);
        }
        DB::table('resource_definitions')->where('category', 'food')->update([
            'unit' => 'unit',
            'unit_label' => null,
            'updated_at' => now(),
        ]);
        DB::table('resource_definitions')->where('key', 'monster_meat')->update([
            'name' => '肉',
            'updated_at' => now(),
        ]);

        Schema::table('resource_definitions', function (Blueprint $table): void {
            $table->dropColumn('unit_label');
        });
    }

    /** @return Collection<int, object> */
    private function foodBalances()
    {
        return DB::table('nation_resources')
            ->join('resource_definitions', 'resource_definitions.id', '=', 'nation_resources.resource_definition_id')
            ->where('resource_definitions.category', 'food')
            ->orderBy('nation_resources.id')
            ->get([
                'nation_resources.id',
                'nation_resources.amount',
                'resource_definitions.key as resource_key',
            ]);
    }
};
