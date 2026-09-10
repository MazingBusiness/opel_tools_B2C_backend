<?php

namespace App\Modules\Auth\Http\Requests;

use App\Modules\Auth\Rules\ValidIndianPhone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email') && is_string($this->email)) {
            $this->merge(['email' => strtolower(trim($this->email)) ?: null]);
        }

        if ($this->has('phone') && is_string($this->phone)) {
            $this->merge(['phone' => trim($this->phone) ?: null]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $userId = $this->user()?->id;

        return [
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'nullable',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($userId),
            ],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20', new ValidIndianPhone],
            'avatar' => ['sometimes', 'nullable', 'string', 'max:2048'],
        ];
    }
}
