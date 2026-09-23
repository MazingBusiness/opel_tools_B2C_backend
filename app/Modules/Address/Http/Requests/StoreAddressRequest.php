<?php

namespace App\Modules\Address\Http\Requests;

use App\Modules\Auth\Rules\ValidIndianPhone;
use App\Modules\Auth\Support\LoginIdentifier;
use InvalidArgumentException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('phone') && is_string($this->phone)) {
            $raw = trim($this->phone);
            try {
                $identifier = LoginIdentifier::parse($raw);
                if ($identifier->channel === LoginIdentifier::CHANNEL_SMS) {
                    $this->merge(['phone' => $identifier->value]);
                } else {
                    $this->merge(['phone' => $raw]);
                }
            } catch (InvalidArgumentException) {
                $this->merge(['phone' => $raw]);
            }
        }
        if ($this->has('pincode') && is_string($this->pincode)) {
            $this->merge(['pincode' => trim($this->pincode)]);
        }
        if ($this->has('line2') && is_string($this->line2) && trim($this->line2) === '') {
            $this->merge(['line2' => null]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:20', new ValidIndianPhone],
            'line1' => ['required', 'string', 'max:255'],
            'line2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:100'],
            'state' => ['required', 'string', 'max:100'],
            'pincode' => ['required', 'string', 'regex:/^[0-9]{6}$/'],
            'type' => ['required', 'string', Rule::in(['home', 'work'])],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }
}
