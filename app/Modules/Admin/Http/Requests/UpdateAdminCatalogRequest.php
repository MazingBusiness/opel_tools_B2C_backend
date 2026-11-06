<?php

namespace App\Modules\Admin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Body: { expected_version: int (0 = no override row yet), fields: { field: value|null } }.
 * Present key = write it; null = reset that field to B2B. Per-field rules live in
 * CatalogOverrideService (locked / unknown fields → 422).
 */
class UpdateAdminCatalogRequest extends FormRequest
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
            'fields' => ['present', 'array'],
        ];
    }
}
