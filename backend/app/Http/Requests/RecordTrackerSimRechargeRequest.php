<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class RecordTrackerSimRechargeRequest extends FormRequest
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
            'amount' => ['required', 'integer', 'min:1', 'max:1000000'],
            'recharged_on' => ['nullable', 'date'],
            'next_recharge_on' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $last = $this->input('recharged_on') ?: now()->toDateString();
            $next = $this->input('next_recharge_on');
            if (is_string($next) && $next !== '' && $next < $last) {
                $v->errors()->add('next_recharge_on', 'موعد الشحن القادم لا يمكن أن يسبق تاريخ الشحن.');
            }
        });
    }
}
