<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesDigits;
use App\Rules\EgyptianNationalId;
use App\Rules\EgyptianPhone;
use App\Support\DigitNormalizer;
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
            'seats',
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
        return [
            'code' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('vehicles', 'code')->whereNull('deleted_at'),
            ],
            'plate' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('vehicles', 'plate')->whereNull('deleted_at'),
            ],
            'plate_letters' => ['nullable', 'string', 'min:2', 'max:3'],
            'plate_numbers' => ['nullable', 'string', 'max:4'],
            'brand' => ['required', 'string', 'max:100'],
            'model' => ['required', 'string', 'max:100'],
            'year' => ['required', 'integer', 'min:1990', 'max:2100'],
            'category' => ['required', 'string', 'max:100'],
            'fuel_type' => ['required', 'string', 'max:50'],
            'odometer' => ['required', 'integer', 'min:0'],
            'daily_rate' => ['required', 'integer', 'min:0'],
            'default_deposit' => ['required', 'integer', 'min:0'],
            'tracker_device_number' => ['nullable', 'string', 'max:100'],
            'tracker_sim_number' => ['nullable', 'string', 'max:100'],
            'tracker_sim_last_recharged_on' => ['nullable', 'date'],
            'tracker_sim_next_recharge_on' => ['nullable', 'date'],
            'tracker_sim_recharge_cycle_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'color' => ['nullable', 'string', 'max:50'],
            'transmission' => ['nullable', 'string', 'max:50'],
            'chassis_number' => ['required', 'string', 'min:5', 'max:32', 'regex:/^[A-Za-z0-9\-]+$/'],
            'engine_number' => ['nullable', 'string', 'max:100'],
            'engine_capacity' => ['nullable', 'string', 'max:50'],
            'seats' => ['nullable', 'integer', 'min:1', 'max:60'],
            'purchase_value_estimate' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
            'owner_id' => ['nullable', 'integer', 'exists:vehicle_owners,id'],
            'owner_monthly_amount' => ['nullable', 'integer', 'min:0'],
            'owner_since' => ['nullable', 'date'],
            'owner_agreement_starts_on' => ['nullable', 'date'],
            'owner_agreement_ends_on' => ['nullable', 'date', 'after_or_equal:owner_agreement_starts_on'],
            'owner_inline' => ['nullable', 'array'],
            'owner_inline.full_name' => ['required_with:owner_inline', 'string', 'max:150'],
            'owner_inline.phone' => ['required_with:owner_inline', 'string', 'max:30', new EgyptianPhone],
            'owner_inline.phone_alt' => ['nullable', 'string', 'max:30', new EgyptianPhone],
            'owner_inline.national_id' => ['required_with:owner_inline', 'string', 'max:30', new EgyptianNationalId],
            'owner_inline.id_front_path' => ['required_with:owner_inline', 'string', 'max:500'],
            'owner_inline.id_back_path' => ['required_with:owner_inline', 'string', 'max:500'],
            'owner_inline.notes' => ['nullable', 'string'],
            'addon_ids' => ['nullable', 'array'],
            'addon_ids.*' => ['integer', 'exists:vehicle_addon_catalog,id'],
            'license' => ['nullable', 'array'],
            'license.has_license' => ['nullable', 'boolean'],
            'license.document_number' => ['nullable', 'string', 'max:80'],
            'license.expires_on' => ['nullable', 'date'],
            'license.issued_on' => ['nullable', 'date'],
            'license.file_path' => ['nullable', 'string', 'max:500'],
            'license.file_path_back' => ['nullable', 'string', 'max:500'],
            'license.notes' => ['nullable', 'string', 'max:500'],
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
            'chassis_number' => 'رقم الشاسيه',
            'owner_id' => 'المالك',
            'owner_monthly_amount' => 'المبلغ الشهري للمالك',
            'owner_since' => 'تاريخ بدء الملكية',
            'owner_agreement_starts_on' => 'بداية عقد المالك',
            'owner_agreement_ends_on' => 'نهاية عقد المالك',
            'owner_inline.phone' => 'هاتف المالك',
            'owner_inline.national_id' => 'الرقم القومي للمالك',
            'owner_inline.id_front_path' => 'وجه بطاقة المالك',
            'owner_inline.id_back_path' => 'ظهر بطاقة المالك',
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
            'plate_letters.min' => 'حروف اللوحة حرفان على الأقل.',
            'plate_letters.max' => 'حروف اللوحة ثلاثة أحرف كحد أقصى.',
            'brand.required' => 'الماركة مطلوبة.',
            'model.required' => 'الموديل مطلوب.',
            'year.required' => 'سنة الصنع مطلوبة.',
            'category.required' => 'الفئة مطلوبة.',
            'fuel_type.required' => 'نوع الوقود مطلوب.',
            'odometer.required' => 'العداد الفعلي مطلوب.',
            'daily_rate.required' => 'الإيجار اليومي مطلوب.',
            'default_deposit.required' => 'التأمين النقدي مطلوب.',
            'chassis_number.required' => 'رقم الشاسيه مطلوب.',
            'chassis_number.regex' => 'رقم الشاسيه يقبل حروفاً إنجليزية وأرقاماً وشرطة فقط.',
        ];
    }
}
