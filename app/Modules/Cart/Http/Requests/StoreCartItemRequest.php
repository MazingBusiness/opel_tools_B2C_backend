<?php

namespace App\Modules\Cart\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCartItemRequest extends FormRequest
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
            'product_id' => [
                'required',
                'integer',
                Rule::exists('products', 'id')->where(function ($query) {
                    $query->where('published', true)->where('approved', true);
                }),
            ],
            'variant_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('product_variants', 'id')->where(function ($query) {
                    $query->where('product_id', (int) $this->input('product_id'));
                }),
            ],
            'qty' => ['sometimes', 'integer', 'min:1', 'max:99'],
        ];
    }
}
