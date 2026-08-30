<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class PurchaseUndergroundEquipmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'request_id' => ['required', 'uuid'],
            'definition_key' => ['required', 'string', 'max:100'],
            'price' => ['prohibited'],
            'buy_price' => ['prohibited'],
            'stats' => ['prohibited'],
            'rank' => ['prohibited'],
            'item_level' => ['prohibited'],
            'rarity' => ['prohibited'],
            'secretary_id' => ['prohibited'],
            'balance' => ['prohibited'],
            'shard_balance' => ['prohibited'],
        ];
    }
}
