<?php

namespace App\Http\Requests\Chat;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phone-book entries picked on the device (Contact Picker API or a .vcf file).
 */
class SyncContactsRequest extends FormRequest
{
    public const MAX_ENTRIES = 3000;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'contacts' => ['required', 'array', 'min:1', 'max:'.self::MAX_ENTRIES],
            'contacts.*.name' => ['nullable', 'string', 'max:200'],
            'contacts.*.phones' => ['required', 'array', 'min:1', 'max:10'],
            'contacts.*.phones.*' => ['required', 'string', 'max:40'],
        ];
    }

    public function messages(): array
    {
        return [
            'contacts.max' => 'You can sync up to '.self::MAX_ENTRIES.' contacts at a time.',
        ];
    }
}
