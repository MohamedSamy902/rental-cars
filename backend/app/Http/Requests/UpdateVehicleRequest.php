<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesDigits;
use App\Support\DigitNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVehicleRequest extends FormRequest
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
            'plate_numbers',
        ];
    }

    protected function prepareForValidation(): void
    {
        $payload = $this->all();
        if (! empty($payload['plate_letters'])) {
            $payload['plate_letters'] = preg_replace('/[^\p{L}]+/u', '', (string) $payload['plate_letters']) ?? '';
        }
        if (! empty($payload['plate_numbers'])) {
            $payload['plate_numbers'] = preg_replace('/\D+/', '', (string) $payload['plate_numbers']) ?? '';
        }
        $this->merge(DigitNormalizer::fields($payload, $this->digitKeys()));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $vehicleId = $this->route('vehicle')?->id ?? $this->route('vehicle');

        return [
            'code' => [
                'sometimes',
                'string',
                'max:50',
                Rule::unique('vehicles', 'code')->whereNull('deleted_at')->ignore($vehicleId),
            ],
            'plate' => [
                'sometimes',
                'string',
                'max:50',
                Rule::unique('vehicles', 'plate')->whereNull('deleted_at')->ignore($vehicleId),
            ],
            'plate_letters' => ['nullable', 'string', 'min:2', 'max:3'],
            'plate_numbers' => ['nullable', 'string', 'max:4'],
            'brand' => ['sometimes', 'string', 'max:100'],
            'model' => ['sometimes', 'string', 'max:100'],
            'year' => ['sometimes', 'integer', 'min:1990', 'max:2100'],
            'category' => ['sometimes', 'string', 'max:100'],
            'fuel_type' => ['sometimes', 'string', 'max:50'],
            'odometer' => ['sometimes', 'integer', 'min:0'],
            'daily_rate' => ['sometimes', 'integer', 'min:0'],
            'default_deposit' => ['sometimes', 'integer', 'min:0'],
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
            'code.unique' => 'الكود الداخلي مستخدم مسبقاً.',
            'plate.unique' => 'رقم اللوحة مستخدم مسبقاً.',
        ];
    }
}
