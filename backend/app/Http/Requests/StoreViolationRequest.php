<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesDigits;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreViolationRequest extends FormRequest
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
            'vehicle_id',
            'occurred_at',
            'amount',
            'contract_id',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'vehicle_id' => [
                'required',
                Rule::exists('vehicles', 'id')->whereNull('deleted_at'),
            ],
            'occurred_at' => ['required', 'date'],
            'location' => ['nullable', 'string', 'max:500'],
            'amount' => ['required', 'integer', 'min:0'],
            'notes' => ['nullable', 'string'],
            'photo_path' => ['nullable', 'string', 'max:500'],
            'contract_id' => [
                'nullable',
                Rule::exists('contracts', 'id')->whereNull('deleted_at'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'vehicle_id' => 'العربية',
            'occurred_at' => 'تاريخ المخالفة',
            'location' => 'الموقع',
            'amount' => 'المبلغ',
            'notes' => 'الملاحظات',
            'contract_id' => 'العقد',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'vehicle_id.required' => 'العربية مطلوبة.',
            'vehicle_id.exists' => 'العربية المحدّدة غير صالحة.',
            'occurred_at.required' => 'تاريخ المخالفة مطلوب.',
            'occurred_at.date' => 'تاريخ المخالفة غير صالح.',
            'amount.required' => 'المبلغ مطلوب.',
            'amount.integer' => 'يجب أن يكون المبلغ عدداً صحيحاً.',
            'amount.min' => 'لا يجوز أن يكون المبلغ سالباً.',
            'contract_id.exists' => 'العقد المحدّد غير صالح.',
        ];
    }
}
