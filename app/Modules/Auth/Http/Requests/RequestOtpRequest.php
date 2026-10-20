<?php

namespace App\Modules\Auth\Http\Requests;

use App\Modules\Auth\Rules\ValidLoginIdentifier;
use Illuminate\Foundation\Http\FormRequest;

class RequestOtpRequest extends FormRequest
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
            'identifier' => ['required', 'string', 'max:255', new ValidLoginIdentifier],
            // "Resend by SMS": skip WhatsApp for this send (phones only; ignored for email).
            'prefer' => ['nullable', 'string', 'in:sms'],
        ];
    }
}
