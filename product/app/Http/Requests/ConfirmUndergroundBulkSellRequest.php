<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ConfirmUndergroundBulkSellRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'request_id' => ['required', 'uuid'],
            'catalog_identity' => ['required', 'string', 'max:200'],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*' => ['required', 'array:id,sell_price'],
            'items.*.id' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.sell_price' => ['required', 'integer', 'min:1'],
            'filters' => ['prohibited'],
            'item_level_max' => ['prohibited'],
            'rarities' => ['prohibited'],
            'categories' => ['prohibited'],
            'weapon_styles' => ['prohibited'],
        ];
    }
}
