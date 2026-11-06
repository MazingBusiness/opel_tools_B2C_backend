<?php

namespace App\Modules\Admin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminCatalogIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('q') && is_string($this->q)) {
            $this->merge(['q' => trim($this->q)]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'filter' => ['sometimes', 'nullable', Rule::in(['overridden', 'hidden'])],
            'group_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'category_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'parent_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'level' => ['sometimes', 'nullable', Rule::in(['top', 'sub'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }
}
