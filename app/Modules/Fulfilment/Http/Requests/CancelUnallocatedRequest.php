<?php

namespace App\Modules\Fulfilment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CancelUnallocatedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'expected_version' => ['required', 'integer', 'min:0'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.order_item_id' => ['required', 'integer', 'distinct'],
            'lines.*.qty' => ['required', 'integer', 'min:0', 'max:100000'],
        ];
    }

    /** @return array<int, int> */
    public function quantities(): array
    {
        return collect($this->validated('lines'))
            ->mapWithKeys(fn (array $l) => [(int) $l['order_item_id'] => (int) $l['qty']])
            ->all();
    }
}
