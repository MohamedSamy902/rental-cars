<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesDigits;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVehicleRequest extends FormRequest
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
            'year',
            'odometer',
            'daily_rate',
            'default_deposit',
            'rental_priority',
            'seats',
            'target_monthly_income',
            'purchase_value_estimate',
            'owner_id',
            'owner_monthly_amount',
            'owner_since',
            'tracker_sim_number',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('vehicles', 'code')->whereNull('deleted_at'),
            ],
            'plate' => [
                'required',
                'string',
                'max:50',
                Rule::unique('vehicles', 'plate')->whereNull('deleted_at'),
            ],
            'brand' => ['required', 'string', 'max:100'],
            'model' => ['required', 'string', 'max:100'],
            'year' => ['required', 'integer', 'min:1990', 'max:2100'],
            'category' => ['required', 'string', 'max:100'],
            'fuel_type' => ['required', 'string', 'max:50'],
            'odometer' => ['required', 'integer', 'min:0'],
            'daily_rate' => ['required', 'integer', 'min:0'],
            'default_deposit' => ['required', 'integer', 'min:0'],
            'rental_priority' => ['nullable', 'integer', 'min:0', 'max:100'],
            'tracker_device_number' => ['nullable', 'string', 'max:100'],
            'tracker_sim_number' => ['nullable', 'string', 'max:100'],
            'color' => ['nullable', 'string', 'max:50'],
            'transmission' => ['nullable', 'string', 'max:50'],
            'chassis_number' => ['nullable', 'string', 'max:100'],
            'engine_number' => ['nullable', 'string', 'max:100'],
            'engine_capacity' => ['nullable', 'string', 'max:50'],
            'seats' => ['nullable', 'integer', 'min:1', 'max:60'],
            'target_monthly_income' => ['nullable', 'integer', 'min:0'],
            'purchase_value_estimate' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
            'owner_id' => ['nullable', 'integer', 'exists:vehicle_owners,id'],
            'owner_monthly_amount' => ['nullable', 'integer', 'min:0'],
            'owner_since' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'code' => 'الكود الداخلي',
            'plate' => 'رقم اللوحة',
            'brand' => 'الماركة',
            'model' => 'الموديل',
            'year' => 'سنة الصنع',
            'category' => 'الفئة',
            'fuel_type' => 'نوع الوقود',
            'odometer' => 'العداد الفعلي',
            'daily_rate' => 'الإيجار اليومي',
            'default_deposit' => 'التأمين النقدي',
            'rental_priority' => 'أولوية التأجير',
            'owner_id' => 'المالك',
            'owner_monthly_amount' => 'المبلغ الشهري للمالك',
            'owner_since' => 'تاريخ بدء الملكية',
            'notes' => 'الملاحظات',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.required' => 'الكود الداخلي مطلوب.',
            'code.unique' => 'الكود الداخلي مستخدم مسبقاً.',
            'plate.required' => 'رقم اللوحة مطلوب.',
            'plate.unique' => 'رقم اللوحة مستخدم مسبقاً.',
            'brand.required' => 'الماركة مطلوبة.',
            'model.required' => 'الموديل مطلوب.',
            'year.required' => 'سنة الصنع مطلوبة.',
            'category.required' => 'الفئة مطلوبة.',
            'fuel_type.required' => 'نوع الوقود مطلوب.',
            'odometer.required' => 'العداد الفعلي مطلوب.',
            'daily_rate.required' => 'الإيجار اليومي مطلوب.',
            'default_deposit.required' => 'التأمين النقدي مطلوب.',
        ];
    }
}
