<?php

namespace App\Application;

use App\Models\Nation;
use App\Models\NationResource;
use App\Models\NationResourceSalePolicy;
use App\Models\ResourceDefinition;
use DomainException;

final class NationResourceService
{
    public function initialize(Nation $nation): void
    {
        $rules = $nation->world()->firstOrFail()->rulesetVersion()->firstOrFail()->settings;
        $initial = $this->initialResources($rules);
        $definitions = ResourceDefinition::query()->whereIn('key', array_keys($initial))->get()->keyBy('key');

        if ($definitions->count() !== count($initial)) {
            throw new DomainException('初期資源定義が不足しています。先に世界初期化を実行してください。');
        }

        foreach ($initial as $key => $amount) {
            $definition = $definitions->get($key);

            NationResource::query()->create([
                'nation_id' => $nation->id,
                'resource_definition_id' => $definition->id,
                'amount' => $amount,
            ]);

            if ($definition->tradable) {
                NationResourceSalePolicy::query()->create([
                    'nation_id' => $nation->id,
                    'resource_definition_id' => $definition->id,
                    'policy' => $rules['default_sale_policy'] ?? 'stockpile',
                    'keep_amount' => null,
                    'version' => 1,
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, int>
     */
    private function initialResources(array $rules): array
    {
        $configured = $rules['initial_resources'] ?? null;

        if (! is_array($configured)) {
            throw new DomainException('初期資源設定が不正です。');
        }

        $initial = [];
        foreach ($configured as $key => $amount) {
            if (! is_string($key) || ! is_int($amount) || $amount < 0) {
                throw new DomainException('初期資源設定が不正です。');
            }
            $initial[$key] = $amount;
        }

        return $initial;
    }
}
