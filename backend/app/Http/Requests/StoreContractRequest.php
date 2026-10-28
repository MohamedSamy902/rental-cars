<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreContractRequest extends FormRequest
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
            'customer_id' => ['required', 'exists:customers,id'],
            'vehicle_id' => ['required', 'exists:vehicles,id'],
            'guarantor_customer_id' => ['nullable', 'exists:customers,id'],
            'starts_at' => ['required', 'date'],
            'planned_ends_at' => ['required', 'date', 'after:starts_at'],
            'daily_rate' => ['nullable', 'integer', 'min:0'],
            'daily_km_allowance' => ['nullable', 'integer', 'min:0'],
            'excess_km_rate' => ['nullable', 'integer', 'min:0'],
            'deposit' => ['nullable', 'integer', 'min:0'],
            'min_days' => ['nullable', 'integer', 'min:0'],
            'discount' => ['nullable', 'integer', 'min:0'],
            'other_fees' => ['nullable', 'integer', 'min:0'],
            'other_fees_description' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'extended_from_contract_id' => ['nullable', 'exists:contracts,id'],
            'swapped_from_contract_id' => ['nullable', 'exists:contracts,id'],
            'swap_reason' => ['nullable', 'string'],
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
            'starts_at' => 'وقت البداية',
            'planned_ends_at' => 'وقت النهاية المخطط',
        ];
    }
}
