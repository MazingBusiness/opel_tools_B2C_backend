<?php

namespace App\Modules\Fulfilment\Http\Requests;

use App\Modules\Fulfilment\Support\Branches;
use Illuminate\Foundation\Http\FormRequest;

class AllocateOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'expected_version' => ['required', 'integer', 'min:0'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.order_item_id' => ['required', 'integer', 'distinct'],
            'lines.*.branches' => ['required', 'array'],
        ];
        foreach (Branches::codes() as $code) {
            $rules["lines.*.branches.{$code}"] = ['sometimes', 'integer', 'min:0', 'max:100000'];
        }

        return $rules;
    }

    /** @return array<int, array<string, int>> */
    public function plan(): array
    {
        $plan = [];
        foreach ($this->validated('lines') as $line) {
            $plan[(int) $line['order_item_id']] = array_map('intval', array_intersect_key(
                (array) $line['branches'],
                array_flip(Branches::codes()),
            ));
        }

        return $plan;
    }
}
