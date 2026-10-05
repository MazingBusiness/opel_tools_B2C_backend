<?php

namespace App\Modules\Admin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AdminForgotPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email') && is_string($this->email)) {
            $this->merge(['email' => strtolower(trim($this->email))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
        ];
    }
}
