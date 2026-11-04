<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesDigits;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateViolationRequest extends FormRequest
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
                'sometimes',
                'nullable',
                Rule::exists('vehicles', 'id')->whereNull('deleted_at'),
            ],
            'occurred_at' => ['sometimes', 'required', 'date'],
            'location' => ['nullable', 'string', 'max:500'],
            'amount' => ['sometimes', 'required', 'integer', 'min:0'],
            'notes' => ['nullable', 'string'],
            'photo_path' => ['nullable', 'string', 'max:500'],
            'photo_paths' => ['nullable', 'array', 'max:5'],
            'photo_paths.*' => ['string', 'max:500'],
            'contract_id' => [
                'sometimes',
                'required',
                Rule::exists('contracts', 'id')->whereNull('deleted_at'),
            ],
            'status' => ['nullable', Rule::in(['new', 'assigned', 'confirmed', 'paid'])],
            'collection_note' => ['nullable', 'string'],
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
            'status' => 'الحالة',
            'photo_path' => 'المرفق',
            'photo_paths' => 'صور المخالفة',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'vehicle_id.exists' => 'العربية المحدّدة غير صالحة.',
            'occurred_at.required' => 'تاريخ المخالفة مطلوب.',
            'occurred_at.date' => 'تاريخ المخالفة غير صالح.',
            'amount.required' => 'المبلغ مطلوب.',
            'amount.integer' => 'يجب أن يكون المبلغ عدداً صحيحاً.',
            'amount.min' => 'لا يجوز أن يكون المبلغ سالباً.',
            'contract_id.required' => 'الحجز مطلوب.',
            'contract_id.exists' => 'الحجز المحدّد غير صالح.',
            'status.in' => 'حالة المخالفة غير صالحة.',
        ];
    }
}