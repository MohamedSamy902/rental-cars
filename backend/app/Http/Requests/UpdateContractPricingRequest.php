<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesDigits;
use Illuminate\Foundation\Http\FormRequest;

class UpdateContractPricingRequest extends FormRequest
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
            'daily_rate',
            'daily_km_allowance',
            'excess_km_rate',
            'deposit',
            'discount',
            'other_fees',
            'delivery_service_fee',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'daily_rate' => ['required', 'integer', 'min:0'],
            'daily_km_allowance' => ['required', 'integer', 'min:0'],
            'excess_km_rate' => ['required', 'integer', 'min:0'],
            'deposit' => ['required', 'integer', 'min:0'],
            'discount' => ['required', 'integer', 'min:0'],
            'other_fees' => ['required', 'integer', 'min:0'],
            'other_fees_description' => ['nullable', 'string', 'max:500'],
            'has_delivery_service' => ['sometimes', 'boolean'],
            'delivery_service_fee' => ['required_if:has_delivery_service,true', 'nullable', 'integer', 'min:0'],
            'delivery_service_notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'daily_rate' => 'الإيجار اليومي',
            'daily_km_allowance' => 'بدل الكيلومترات اليومي',
            'excess_km_rate' => 'سعر الكيلومتر الزائد',
            'deposit' => 'التأمين النقدي',
            'discount' => 'الخصم',
            'other_fees' => 'رسوم أخرى',
            'other_fees_description' => 'وصف الرسوم الأخرى',
            'has_delivery_service' => 'خدمة تسليم واستلام',
            'delivery_service_fee' => 'مبلغ خدمة التسليم والاستلام',
            'delivery_service_notes' => 'ملاحظات التسليم/المكان',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'daily_rate.required' => 'الإيجار اليومي مطلوب.',
            'daily_rate.integer' => 'يجب أن يكون الإيجار اليومي عدداً صحيحاً.',
            'daily_rate.min' => 'لا يجوز أن يكون الإيجار اليومي سالباً.',
            'daily_km_allowance.required' => 'بدل الكيلومترات اليومي مطلوب.',
            'excess_km_rate.required' => 'سعر الكيلومتر الزائد مطلوب.',
            'deposit.required' => 'التأمين النقدي مطلوب.',
            'deposit.integer' => 'يجب أن يكون التأمين النقدي عدداً صحيحاً.',
            'deposit.min' => 'لا يجوز أن يكون التأمين النقدي سالباً.',
            'discount.required' => 'الخصم مطلوب.',
            'other_fees.required' => 'الرسوم الأخرى مطلوبة.',
            'delivery_service_fee.required_if' => 'حدّد مبلغ خدمة التسليم والاستلام عند تفعيلها.',
        ];
    }
}
