<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SellUndergroundEquipmentRequest extends FormRequest
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
            'price' => ['prohibited'],
            'sell_price' => ['prohibited'],
            'shard_balance' => ['prohibited'],
        ];
    }
}
