<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesDigits;
use App\Models\TenantSetting;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreContractRequest extends FormRequest
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
            'prepaid_excess_km',
            'deposit',
            'min_days',
            'discount',
            'other_fees',
            'delivery_service_fee',
            'odometer',
            'payment_amount',
            'extended_from_contract_id',
            'swapped_from_contract_id',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customer_id' => [
                'required',
                Rule::exists('customers', 'id')->whereNull('deleted_at'),
            ],
            'vehicle_id' => [
                'required',
                Rule::exists('vehicles', 'id')->whereNull('deleted_at'),
            ],
            'guarantor_customer_id' => [
                'nullable',
                Rule::exists('customers', 'id')->whereNull('deleted_at'),
            ],
            'starts_at' => ['required', 'date'],
            'planned_ends_at' => ['required', 'date', 'after:starts_at', 'before:2100-01-01'],
            'daily_rate' => ['nullable', 'integer', 'min:0'],
            'daily_km_allowance' => ['nullable', 'integer', 'min:0'],
            'excess_km_rate' => ['nullable', 'integer', 'min:0'],
            'prepaid_excess_km' => ['nullable', 'integer', 'min:0'],
            'deposit' => ['nullable', 'integer', 'min:0'],
            'min_days' => ['nullable', 'integer', 'min:0'],
            'discount' => ['nullable', 'integer', 'min:0'],
            'other_fees' => ['nullable', 'integer', 'min:0'],
            'other_fees_description' => ['nullable', 'string', 'max:500'],
            'has_delivery_service' => ['sometimes', 'boolean'],
            'delivery_service_fee' => ['required_if:has_delivery_service,true', 'nullable', 'integer', 'min:0'],
            'delivery_service_notes' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'odometer' => ['nullable', 'integer', 'min:0'],
            'accessory_ids' => ['nullable', 'array'],
            'accessory_ids.*' => ['integer', 'exists:vehicle_accessories,id'],
            'payment_amount' => ['required', 'integer', 'min:1'],
            'payment_method' => ['nullable', 'string', 'in:cash,card,transfer'],
            'payment_reference' => ['nullable', 'string', 'max:100'],
            'payment_notes' => ['nullable', 'string', 'max:500'],
            'balance_due_on' => ['nullable', 'date', 'after_or_equal:today'],
            'extended_from_contract_id' => [
                'nullable',
                Rule::exists('contracts', 'id')->whereNull('deleted_at'),
            ],
            'swapped_from_contract_id' => [
                'nullable',
                Rule::exists('contracts', 'id')->whereNull('deleted_at'),
            ],
            'swap_reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($v->errors()->isNotEmpty()) {
                return;
            }
            $paid = (int) $this->input('payment_amount', 0);
            if ($paid < 1) {
                $v->errors()->add('payment_amount', 'دفعة عند الإنشاء مطلوبة ولا يجوز أن تكون صفراً أو سالبة.');

                return;
            }

            $starts = $this->input('starts_at');
            $ends = $this->input('planned_ends_at');
            $rate = $this->input('daily_rate');
            if ($rate === null || $rate === '') {
                $vehicleId = (int) $this->input('vehicle_id');
                $rate = Vehicle::query()->whereKey($vehicleId)->value('daily_rate');
            }
            $rate = (int) $rate;
            if (! $starts || ! $ends || $rate < 1) {
                return;
            }

            try {
                $a = Carbon::parse((string) $starts);
                $b = Carbon::parse((string) $ends);
            } catch (\Throwable) {
                return;
            }
            if ($b->lte($a)) {
                return;
            }
            $days = max(1, (int) ceil($a->diffInSeconds($b) / 86400));
            $deliveryEnabled = filter_var($this->input('has_delivery_service', false), FILTER_VALIDATE_BOOLEAN);
            $deliveryFee = $deliveryEnabled ? (int) $this->input('delivery_service_fee', 0) : 0;
            $otherFees = (int) $this->input('other_fees', 0);
            $discount = (int) $this->input('discount', 0);
            $ops = TenantSetting::query()->first()?->operational ?? [];
            $defaultExcessRate = (int) ($ops['default_excess_km_rate'] ?? $ops['excess_km_rate'] ?? 5);
            $excessRateInput = $this->input('excess_km_rate');
            $excessRate = ($excessRateInput === null || $excessRateInput === '')
                ? $defaultExcessRate
                : (int) $excessRateInput;
            $prepaidKm = max(0, (int) $this->input('prepaid_excess_km', 0));
            $prepaidAmount = $prepaidKm * max(0, $excessRate);
            $total = max(0, ($days * $rate) + $deliveryFee + $otherFees + $prepaidAmount - $discount);
            if ($paid > $total) {
                $v->errors()->add(
                    'payment_amount',
                    sprintf('مبلغ الدفعة لا يجوز أن يتجاوز إجمالي العقد التقديري (%d ج.م).', $total)
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'customer_id' => 'العميل',
            'vehicle_id' => 'العربية',
            'guarantor_customer_id' => 'الضامن',
            'starts_at' => 'وقت البداية',
            'planned_ends_at' => 'وقت النهاية المخطط',
            'daily_rate' => 'الإيجار اليومي',
            'daily_km_allowance' => 'بدل الكيلومترات اليومي',
            'excess_km_rate' => 'سعر الكيلومتر الزائد',
            'prepaid_excess_km' => 'كيلومترات زيادة مدفوعة مقدماً',
            'deposit' => 'التأمين النقدي',
            'min_days' => 'الحد الأدنى للأيام',
            'discount' => 'الخصم',
            'other_fees' => 'رسوم أخرى',
            'other_fees_description' => 'وصف الرسوم الأخرى',
            'has_delivery_service' => 'خدمة تسليم واستلام',
            'delivery_service_fee' => 'مبلغ خدمة التسليم والاستلام',
            'delivery_service_notes' => 'ملاحظات التسليم/المكان',
            'notes' => 'الملاحظات',
            'odometer' => 'العداد الحالي',
            'accessory_ids' => 'المستلزمات',
            'payment_amount' => 'مبلغ الدفعة',
            'payment_method' => 'طريقة الدفع',
            'balance_due_on' => 'تاريخ تحصيل المتبقي',
            'extended_from_contract_id' => 'عقد التمديد السابق',
            'swapped_from_contract_id' => 'عقد التبديل السابق',
            'swap_reason' => 'سبب التبديل',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'customer_id.required' => 'العميل مطلوب.',
            'customer_id.exists' => 'العميل المحدّد غير صالح.',
            'vehicle_id.required' => 'العربية مطلوبة.',
            'vehicle_id.exists' => 'العربية المحدّدة غير صالحة.',
            'guarantor_customer_id.exists' => 'الضامن المحدّد غير صالح.',
            'starts_at.required' => 'وقت البداية مطلوب.',
            'starts_at.date' => 'وقت البداية غير صالح.',
            'planned_ends_at.required' => 'وقت النهاية المخطط مطلوب.',
            'planned_ends_at.date' => 'وقت النهاية المخطط غير صالح.',
            'planned_ends_at.after' => 'يجب أن يكون وقت النهاية المخطط بعد وقت البداية.',
            'planned_ends_at.before' => 'وقت النهاية المخطط خارج النطاق المسموح.',
            'daily_rate.integer' => 'يجب أن يكون الإيجار اليومي عدداً صحيحاً.',
            'daily_rate.min' => 'لا يجوز أن يكون الإيجار اليومي سالباً.',
            'deposit.integer' => 'يجب أن يكون التأمين النقدي عدداً صحيحاً.',
            'deposit.min' => 'لا يجوز أن يكون التأمين النقدي سالباً.',
            'discount.integer' => 'يجب أن يكون الخصم عدداً صحيحاً.',
            'discount.min' => 'لا يجوز أن يكون الخصم سالباً.',
            'other_fees.integer' => 'يجب أن تكون الرسوم الأخرى عدداً صحيحاً.',
            'other_fees.min' => 'لا يجوز أن تكون الرسوم الأخرى سالبة.',
            'delivery_service_fee.required_if' => 'حدّد مبلغ خدمة التسليم والاستلام عند تفعيلها.',
            'delivery_service_fee.integer' => 'يجب أن يكون مبلغ خدمة التسليم والاستلام عدداً صحيحاً.',
            'delivery_service_fee.min' => 'لا يجوز أن يكون مبلغ خدمة التسليم والاستلام سالباً.',
            'delivery_service_notes.max' => 'ملاحظات التسليم لا تتجاوز 255 حرفاً.',
            'payment_amount.required' => 'دفعة عند الإنشاء مطلوبة — لا تأجير بلا دفع.',
            'payment_amount.min' => 'دفعة عند الإنشاء يجب أن تكون أكبر من صفر.',
            'extended_from_contract_id.exists' => 'عقد التمديد السابق غير صالح.',
            'swapped_from_contract_id.exists' => 'عقد التبديل السابق غير صالح.',
        ];
    }
}
