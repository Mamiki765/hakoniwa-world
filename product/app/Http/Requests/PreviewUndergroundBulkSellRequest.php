<?php

namespace App\Http\Requests;

use App\Application\Underground\UndergroundEquipmentService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PreviewUndergroundBulkSellRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'item_level_max' => ['nullable', 'integer', 'min:1'],
            'quality_percent_max' => ['nullable', 'integer', 'min:0', 'max:100'],
            'rarities' => ['present', 'array', 'max:6'],
            'rarities.*' => ['string', 'distinct', Rule::in(UndergroundEquipmentService::BULK_SELL_RARITY_KEYS)],
            'categories' => ['present', 'array', 'max:'.count(UndergroundEquipmentService::BULK_SELL_CATEGORY_KEYS)],
            'categories.*' => ['string', 'distinct', Rule::in(UndergroundEquipmentService::BULK_SELL_CATEGORY_KEYS)],
            'weapon_styles' => ['present', 'array', 'max:20'],
            'weapon_styles.*' => ['string', 'distinct', 'max:100'],
            'affix_keys' => ['sometimes', 'array', 'max:30'],
            'affix_keys.*' => ['string', 'distinct', 'max:100'],
            'filter_modes' => ['sometimes', 'array:item_level_max,quality_percent_max,rarities,categories,weapon_styles,affix_keys'],
            'filter_modes.*' => ['string', Rule::in(['match', 'exclude'])],
            'request_id' => ['prohibited'],
            'item_ids' => ['prohibited'],
            'sell_price' => ['prohibited'],
        ];
    }
}
