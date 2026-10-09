<?php

namespace App\Modules\Admin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminOrderIndexRequest extends FormRequest
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
            'payment_status' => ['sometimes', 'nullable', Rule::in(['unpaid', 'pending', 'paid', 'failed'])],
            'payment_method' => ['sometimes', 'nullable', Rule::in(['zoho', 'cod'])],
            'status' => ['sometimes', 'nullable', Rule::in([
                'pending_payment', 'processing', 'shipped', 'delivered', 'failed', 'cancelled', 'paid',
            ])],
            'user_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }
}
