<?php

namespace App\Modules\Admin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAdminOrderPaymentNotesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->exists('payment_notes') && is_string($this->payment_notes)) {
            $trimmed = trim($this->payment_notes);
            $this->merge(['payment_notes' => $trimmed === '' ? null : $trimmed]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'payment_notes' => ['present', 'nullable', 'string', 'max:2000'],
        ];
    }
}
