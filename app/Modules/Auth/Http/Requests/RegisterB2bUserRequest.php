<?php

namespace App\Modules\Auth\Http\Requests;

use App\Modules\Auth\Rules\ValidIndianPhone;
use App\Modules\Auth\Support\LoginIdentifier;
use Illuminate\Foundation\Http\FormRequest;
use InvalidArgumentException;

class RegisterB2bUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name') && is_string($this->input('name'))) {
            $name = trim($this->string('name')->toString());
            $this->merge(['name' => $name === '' ? null : $name]);
        }

        if ($this->has('email') && is_string($this->input('email'))) {
            $email = strtolower(trim($this->string('email')->toString()));
            $this->merge(['email' => $email === '' ? null : $email]);
        }

        if ($this->has('phone') && is_string($this->input('phone'))) {
            $phone = trim($this->string('phone')->toString());

            if ($phone === '') {
                $this->merge(['phone' => null]);
            } else {
                try {
                    $this->merge(['phone' => LoginIdentifier::parse($phone)->value]);
                } catch (InvalidArgumentException) {
                    $this->merge(['phone' => $phone]);
                }
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', 'required_without:phone'],
            'phone' => ['nullable', 'string', 'max:20', 'required_without:email', new ValidIndianPhone],
        ];
    }
}
