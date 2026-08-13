<?php

namespace App\Http\Requests;

use App\Domain\Nation\NationProfileText;
use Illuminate\Foundation\Http\FormRequest;

class CreateNationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'request_key' => ['required', 'uuid'],
            'world_id' => ['required', 'integer', 'exists:worlds,id'],
            'name' => [
                'required', 'string', 'min:2', 'max:30', 'regex:/^[^\p{Cc}\p{Cs}<>]+$/u',
            ],
            'owner_name' => [
                'required', 'string', 'min:1', 'max:'.NationProfileText::OWNER_NAME_MAX_LENGTH,
                'regex:'.NationProfileText::SINGLE_LINE_PATTERN,
            ],
            'comment' => [
                'present', 'string', 'max:'.NationProfileText::COMMENT_MAX_LENGTH,
                'regex:'.NationProfileText::SINGLE_LINE_PATTERN,
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $comment = $this->input('comment');
        $values = [];
        if (! $this->exists('comment') || $comment === null) {
            $values['comment'] = '';
        } elseif (is_string($comment)) {
            $values['comment'] = NationProfileText::trimSpaces($comment);
        }
        if (is_string($this->input('owner_name'))) {
            $values['owner_name'] = NationProfileText::trimSpaces($this->input('owner_name'));
        }
        $this->merge($values);
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'name' => '島名',
            'owner_name' => '島主名',
            'comment' => '一言コメント',
        ];
    }
}
