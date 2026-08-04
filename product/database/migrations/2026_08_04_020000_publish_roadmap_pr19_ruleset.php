<?php

use App\Application\RulesetPublisher;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TARGET_KEY = 'roadmap-pr19-v1';

    /** @var array<string, array{source_unit: string, source_label: null, unit: string, label: string}> */
    private const RESOURCE_UNITS = [
        'industrial_goods' => [
            'source_unit' => 'unit', 'source_label' => null,
            'unit' => 'unit', 'label' => 'ユニット',
        ],
        'minerals' => [
            'source_unit' => 'unit', 'source_label' => null,
            'unit' => 'ton', 'label' => 'トン',
        ],
    ];

    public function up(): void
    {
        $published = config('hakoniwa.published_rulesets');
        $settings = is_array($published) ? ($published[self::TARGET_KEY] ?? null) : null;
        if (! is_array($settings)) {
            throw new RuntimeException('The immutable roadmap-pr19-v1 ruleset snapshot is missing.');
        }

        DB::transaction(function () use ($settings): void {
            $this->updateResourceUnitCatalog();

            // Historical pre-release Worlds remain reset-required. Publication must
            // not repoint a World or rewrite its command queue definitions.
            app(RulesetPublisher::class)->publish($settings);
        });
    }

    public function down(): void
    {
        throw new RuntimeException(
            'The roadmap-pr19-v1 ruleset publication is forward-only; restore from an explicit backup instead.',
        );
    }

    private function updateResourceUnitCatalog(): void
    {
        $rows = DB::table('resource_definitions')
            ->whereIn('key', array_keys(self::RESOURCE_UNITS))
            ->orderBy('key')
            ->lockForUpdate()
            ->get(['id', 'key', 'unit', 'unit_label'])
            ->keyBy('key');
        if ($rows->count() !== count(self::RESOURCE_UNITS)) {
            throw new RuntimeException('The PR19 resource unit catalog is incomplete.');
        }

        foreach (self::RESOURCE_UNITS as $key => $expected) {
            $row = $rows->get($key);
            if ($row->unit === $expected['unit'] && $row->unit_label === $expected['label']) {
                continue;
            }
            if ($row->unit !== $expected['source_unit'] || $row->unit_label !== $expected['source_label']) {
                throw new RuntimeException(
                    "Resource {$key} has unexpected unit metadata; refusing an implicit catalog rewrite.",
                );
            }

            DB::table('resource_definitions')->where('id', $row->id)->update([
                'unit' => $expected['unit'],
                'unit_label' => $expected['label'],
                'updated_at' => now(),
            ]);
        }
    }
};
