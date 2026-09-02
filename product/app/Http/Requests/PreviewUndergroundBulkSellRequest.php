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
            'item_level_max' => ['nullable', 'integer', 'between:1,60'],
            'rarities' => ['present', 'array', 'max:6'],
            'rarities.*' => ['string', 'distinct', Rule::in(UndergroundEquipmentService::BULK_SELL_RARITY_KEYS)],
            'categories' => ['present', 'array', 'max:3'],
            'categories.*' => ['string', 'distinct', Rule::in(UndergroundEquipmentService::BULK_SELL_CATEGORY_KEYS)],
            'weapon_styles' => ['present', 'array', 'max:20'],
            'weapon_styles.*' => ['string', 'distinct', 'max:100'],
            'request_id' => ['prohibited'],
            'item_ids' => ['prohibited'],
            'sell_price' => ['prohibited'],
        ];
    }
}
