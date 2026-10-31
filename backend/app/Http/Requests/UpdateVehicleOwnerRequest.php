<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesDigits;
use App\Rules\EgyptianNationalId;
use App\Rules\EgyptianPhone;
use Illuminate\Foundation\Http\FormRequest;

class UpdateVehicleOwnerRequest extends FormRequest
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
        return ['phone', 'phone_alt', 'national_id'];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'full_name' => ['sometimes', 'string', 'max:200'],
            'phone' => ['nullable', 'string', 'max:30', new EgyptianPhone],
            'phone_alt' => ['nullable', 'string', 'max:30', new EgyptianPhone],
            'national_id' => ['nullable', 'string', 'max:30', new EgyptianNationalId],
            'id_front_path' => ['nullable', 'string', 'max:500'],
            'id_back_path' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'full_name' => 'اسم المالك',
            'phone' => 'الهاتف',
            'phone_alt' => 'هاتف بديل',
            'national_id' => 'الرقم القومي',
            'id_front_path' => 'وجه البطاقة',
            'id_back_path' => 'ظهر البطاقة',
            'notes' => 'الملاحظات',
        ];
    }
}
