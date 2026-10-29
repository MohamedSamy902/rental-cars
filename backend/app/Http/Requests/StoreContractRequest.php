<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesDigits;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'deposit',
            'min_days',
            'discount',
            'other_fees',
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
            'deposit' => ['nullable', 'integer', 'min:0'],
            'min_days' => ['nullable', 'integer', 'min:0'],
            'discount' => ['nullable', 'integer', 'min:0'],
            'other_fees' => ['nullable', 'integer', 'min:0'],
            'other_fees_description' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string'],
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

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'customer_id' => 'العميل',
            'vehicle_id' => 'السيارة',
            'guarantor_customer_id' => 'الضامن',
            'starts_at' => 'وقت البداية',
            'planned_ends_at' => 'وقت النهاية المخطط',
            'daily_rate' => 'الإيجار اليومي',
            'daily_km_allowance' => 'بدل الكيلومترات اليومي',
            'excess_km_rate' => 'سعر الكيلومتر الزائد',
            'deposit' => 'التأمين النقدي',
            'min_days' => 'الحد الأدنى للأيام',
            'discount' => 'الخصم',
            'other_fees' => 'رسوم أخرى',
            'other_fees_description' => 'وصف الرسوم الأخرى',
            'notes' => 'الملاحظات',
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
            'vehicle_id.required' => 'السيارة مطلوبة.',
            'vehicle_id.exists' => 'السيارة المحدّدة غير صالحة.',
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
            'extended_from_contract_id.exists' => 'عقد التمديد السابق غير صالح.',
            'swapped_from_contract_id.exists' => 'عقد التبديل السابق غير صالح.',
        ];
    }
}
