<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UndergroundBankTransferRequest extends FormRequest
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
            'action' => ['required', 'string', Rule::in([
                'deposit',
                'withdraw',
                'deposit_all',
                'withdraw_all',
            ])],
            'amount' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
