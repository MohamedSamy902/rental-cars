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
            'contract_id' => [
                'required',
                Rule::exists('contracts', 'id')->whereNull('deleted_at'),
            ],
            'vehicle_id' => [
                'nullable',
                Rule::exists('vehicles', 'id')->whereNull('deleted_at'),
            ],
            'occurred_at' => ['required', 'date'],
            'location' => ['nullable', 'string', 'max:500'],
            'amount' => ['required', 'integer', 'min:0'],
            'notes' => ['nullable', 'string'],
            'photo_path' => ['nullable', 'string', 'max:500'],
            'photo_paths' => ['nullable', 'array', 'max:5'],
            'photo_paths.*' => ['string', 'max:500'],
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
            'contract_id' => 'الحجز',
            'photo_paths' => 'صور المخالفة',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'contract_id.required' => 'الحجز مطلوب.',
            'contract_id.exists' => 'الحجز المحدّد غير صالح.',
            'vehicle_id.exists' => 'العربية المحدّدة غير صالحة.',
            'occurred_at.required' => 'تاريخ المخالفة مطلوب.',
            'occurred_at.date' => 'تاريخ المخالفة غير صالح.',
            'amount.required' => 'المبلغ مطلوب.',
            'amount.integer' => 'يجب أن يكون المبلغ عدداً صحيحاً.',
            'amount.min' => 'لا يجوز أن يكون المبلغ سالباً.',
        ];
    }
}
