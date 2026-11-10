<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesDigits;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdatePendingContractRequest extends FormRequest
{
    use NormalizesDigits;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return list<string>
     */
    protected function digitKeys(): array
    {
        return [
            'customer_id',
            'vehicle_id',
            'guarantor_customer_id',
            'starts_at',
            'planned_ends_at',
            'daily_rate',
            'daily_km_allowance',
            'excess_km_rate',
            'deposit',
            'min_days',
            'discount',
            'other_fees',
            'odometer',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customer_id' => [
                'sometimes',
                'required',
                Rule::exists('customers', 'id')->whereNull('deleted_at'),
            ],
            'vehicle_id' => [
                'sometimes',
                'required',
                Rule::exists('vehicles', 'id')->whereNull('deleted_at'),
            ],
            'guarantor_customer_id' => [
                'nullable',
                Rule::exists('customers', 'id')->whereNull('deleted_at'),
            ],
            'starts_at' => ['sometimes', 'required', 'date'],
            'planned_ends_at' => ['sometimes', 'required', 'date', 'after:starts_at', 'before:2100-01-01'],
            'daily_rate' => ['nullable', 'integer', 'min:0'],
            'daily_km_allowance' => ['nullable', 'integer', 'min:0'],
            'excess_km_rate' => ['nullable', 'integer', 'min:0'],
            'deposit' => ['nullable', 'integer', 'min:0'],
            'min_days' => ['nullable', 'integer', 'min:0'],
            'discount' => ['nullable', 'integer', 'min:0'],
            'other_fees' => ['nullable', 'integer', 'min:0'],
            'other_fees_description' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string'],
            'odometer' => ['nullable', 'integer', 'min:0'],
            'accessory_ids' => ['nullable', 'array'],
            'accessory_ids.*' => ['integer', 'exists:vehicle_accessories,id'],
            'balance_due_on' => ['nullable', 'date', 'after_or_equal:today'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($v->errors()->isNotEmpty()) {
                return;
            }
            $starts = $this->input('starts_at');
            $ends = $this->input('planned_ends_at');
            if (! $starts || ! $ends) {
                return;
            }
            $rate = $this->input('daily_rate');
            if ($rate === null || $rate === '') {
                $vehicleId = (int) ($this->input('vehicle_id') ?: $this->route('contract')?->vehicle_id);
                $rate = Vehicle::query()->whereKey($vehicleId)->value('daily_rate');
            }
            // لا تحقق دفعة هنا — الدفعة عند الإنشاء فقط.
            unset($rate);
            try {
                $a = Carbon::parse((string) $starts);
                $b = Carbon::parse((string) $ends);
            } catch (\Throwable) {
                return;
            }
            if ($b->lte($a)) {
                $v->errors()->add('planned_ends_at', 'يجب أن تكون نهاية العقد بعد بدايته.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'planned_ends_at.after' => 'يجب أن تكون نهاية العقد بعد بدايته.',
            'deposit.min' => 'لا يجوز أن يكون التأمين النقدي سالباً.',
        ];
    }
}
