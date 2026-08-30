<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UndergroundPlaytestRequest extends FormRequest
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
            'build_key' => ['required', 'string', Rule::in([
                'pure_attacker',
                'pure_tank',
                'pure_healer',
                'balanced',
            ])],
            'enemy_key' => ['required', 'string', Rule::in([
                'depth_stalker',
                'pressure_construct',
                'crystal_warden',
            ])],
        ];
    }
}
