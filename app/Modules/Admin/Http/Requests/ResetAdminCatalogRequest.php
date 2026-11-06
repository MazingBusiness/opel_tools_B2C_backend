<?php

namespace App\Modules\Admin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Body: { expected_version: int, fields?: string[] } (omitted = reset every field). */
class ResetAdminCatalogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'expected_version' => ['required', 'integer', 'min:0'],
            'fields' => ['sometimes', 'array'],
            'fields.*' => ['string', 'max:64'],
        ];
    }
}
