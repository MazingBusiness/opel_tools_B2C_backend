<?php

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['cat_groups', 'categories', 'brands'] as $key) {
            $value = $this->input($key);

            if (is_string($value)) {
                $this->merge([
                    $key => array_values(array_filter(array_map(
                        static fn (string $id): int => (int) trim($id),
                        explode(',', $value),
                    ))),
                ]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'group_id' => ['sometimes', 'nullable', 'integer'],
            'cat_groups' => ['sometimes', 'array'],
            'cat_groups.*' => ['integer'],
            'category_id' => ['sometimes', 'nullable', 'integer'],
            'categories' => ['sometimes', 'array'],
            'categories.*' => ['integer'],
            'brand_id' => ['sometimes', 'nullable', 'integer'],
            'brands' => ['sometimes', 'array'],
            'brands.*' => ['integer'],
            'min' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'max' => ['sometimes', 'nullable', 'numeric', 'min:0', 'gte:min'],
            'in_stock' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'nullable', Rule::in(['price_low_to_high', 'price_high_to_low', 'new_arrival'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }
}
