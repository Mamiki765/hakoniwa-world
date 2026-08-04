<?php

namespace App\Http\Requests;

use App\Domain\Nation\NationProfileText;
use App\Models\Nation;
use App\Models\NationMembership;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateNationProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        $nation = $this->route('nation');

        return $this->user() !== null
            && $nation instanceof Nation
            && NationMembership::query()
                ->where('user_id', $this->user()->id)
                ->where('world_id', $nation->world_id)
                ->where('nation_id', $nation->id)
                ->where('role', 'owner')
                ->exists();
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'owner_name' => [
                'sometimes', 'required', 'string', 'min:1',
                'max:'.NationProfileText::OWNER_NAME_MAX_LENGTH,
                'regex:'.NationProfileText::SINGLE_LINE_PATTERN,
            ],
            'comment' => [
                'sometimes', 'string', 'max:'.NationProfileText::COMMENT_MAX_LENGTH,
                'regex:'.NationProfileText::SINGLE_LINE_PATTERN,
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $values = [];
        foreach (['owner_name', 'comment'] as $key) {
            $value = $this->input($key);
            if ($this->exists($key)) {
                $values[$key] = $key === 'comment' && $value === null
                    ? ''
                    : (is_string($value) ? NationProfileText::trimSpaces($value) : $value);
            }
        }
        $this->merge($values);
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['owner_name' => '島主名', 'comment' => '一言コメント'];
    }
}
