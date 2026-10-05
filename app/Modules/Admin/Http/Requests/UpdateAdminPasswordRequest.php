<?php

namespace App\Modules\Admin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class UpdateAdminPasswordRequest extends FormRequest
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
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $user = $this->user();
            $current = (string) $this->input('current_password');

            if ($user === null || ! filled($user->password) || ! Hash::check($current, $user->password)) {
                $validator->errors()->add('current_password', 'The current password is incorrect.');

                return;
            }

            $new = (string) $this->input('password');
            if ($new !== '' && Hash::check($new, $user->password)) {
                $validator->errors()->add('password', 'The new password must be different from the current password.');
            }
        });
    }
}
