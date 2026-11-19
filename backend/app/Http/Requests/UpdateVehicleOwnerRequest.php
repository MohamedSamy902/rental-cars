<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesDigits;
use App\Rules\EgyptianNationalId;
use App\Rules\EgyptianPhone;
use App\Support\Nationalities;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
        $nationality = $this->input('nationality');
        if ($nationality === null || $nationality === '') {
            $owner = $this->route('owner');
            $nationality = is_object($owner) ? ($owner->nationality ?? null) : null;
        }
        $egyptian = Nationalities::isEgyptian($nationality);

        return [
            'full_name' => ['sometimes', 'required', 'string', 'max:200'],
            'phone' => ['sometimes', 'required', 'string', 'max:30', new EgyptianPhone],
            'phone_alt' => ['nullable', 'string', 'max:30', new EgyptianPhone],
            'nationality' => ['sometimes', 'required', 'string', 'max:80', Rule::in(Nationalities::all())],
            'national_id' => array_values(array_filter([
                'sometimes',
                'required',
                'string',
                'max:30',
                $egyptian ? new EgyptianNationalId : null,
            ])),
            'id_front_path' => ['sometimes', 'required', 'string', 'max:500'],
            'id_back_path' => ['sometimes', 'required', 'string', 'max:500'],
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
            'nationality' => 'الجنسية',
            'national_id' => 'الرقم القومي / رقم الهوية',
            'id_front_path' => 'وجه البطاقة',
            'id_back_path' => 'ظهر البطاقة',
            'notes' => 'الملاحظات',
        ];
    }
}
