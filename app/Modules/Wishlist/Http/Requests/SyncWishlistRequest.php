<?php

namespace App\Modules\Wishlist\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SyncWishlistRequest extends FormRequest
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
        // Do not Rule::exists here — one stale/unpublished id must not 422 the whole merge.
        // Controller filters to published+approved products.
        return [
            'product_ids' => ['required', 'array', 'max:100'],
            'product_ids.*' => ['integer'],
        ];
    }
}
