<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesDigits;
use App\Rules\EgyptianPhone;
use App\Support\DigitNormalizer;
use App\Support\Phone;
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

    protected function prepareForValidation(): void
    {
        $this->merge(DigitNormalizer::fields($this->all(), $this->digitKeys()));
        $merge = [];
        foreach (['phone', 'phone_alt'] as $key) {
            if ($this->exists($key) && is_string($this->input($key))) {
                $merge[$key] = Phone::canonicalizeForStorage($this->input($key));
            }
        }
        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'full_name' => ['sometimes', 'required', 'string', 'max:200'],
            'phone' => ['sometimes', 'required', 'string', 'max:30', new EgyptianPhone],
            'phone_alt' => ['nullable', 'string', 'max:30', new EgyptianPhone],
            'nationality' => ['sometimes', 'required', 'string', 'max:80'],
            // رقم قومي مصري أو هوية أجنبية أو جواز — حروف وأرقام بلا صيغة إلزامية
            'national_id' => ['sometimes', 'required', 'string', 'max:80'],
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
