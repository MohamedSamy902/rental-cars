<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesDigits;
use App\Rules\EgyptianNationalId;
use App\Rules\EgyptianPhone;
use App\Rules\MediaReference;
use Illuminate\Foundation\Http\FormRequest;

class StoreCustomerRequest extends FormRequest
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
            'phone',
            'phone_alt',
            'national_id',
            'national_id_expires_on',
            'passport_expires_on',
            'birth_date',
            'license_number',
            'license_expires_on',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:200'],
            'phone' => ['required', 'string', 'max:30', new EgyptianPhone],
            'phone_alt' => ['nullable', 'string', 'max:30', new EgyptianPhone],
            'email' => ['nullable', 'email', 'max:200'],
            'national_id' => ['nullable', 'string', 'max:30', new EgyptianNationalId],
            'national_id_expires_on' => ['nullable', 'date'],
            'passport_number' => ['nullable', 'string', 'max:50'],
            'passport_expires_on' => ['nullable', 'date'],
            'birth_date' => ['required', 'date'],
            'address' => ['nullable', 'string'],
            'license_number' => ['required', 'string', 'max:50'],
            'license_expires_on' => ['required', 'date'],
            'national_id_image_path' => ['nullable', 'string', 'max:500', new MediaReference],
            'license_image_path' => ['nullable', 'string', 'max:500', new MediaReference],
            'national_id_media_id' => ['nullable', 'integer', 'exists:media_files,id'],
            'license_media_id' => ['nullable', 'integer', 'exists:media_files,id'],
            'notes' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'full_name' => 'الاسم الكامل',
            'phone' => 'الهاتف',
            'phone_alt' => 'هاتف بديل',
            'email' => 'البريد الإلكتروني',
            'national_id' => 'الرقم القومي',
            'national_id_expires_on' => 'تاريخ انتهاء الرقم القومي',
            'passport_number' => 'رقم جواز السفر',
            'passport_expires_on' => 'تاريخ انتهاء جواز السفر',
            'birth_date' => 'تاريخ الميلاد',
            'address' => 'العنوان',
            'license_number' => 'رقم الرخصة',
            'license_expires_on' => 'انتهاء الرخصة',
            'national_id_image_path' => 'صورة الرقم القومي',
            'license_image_path' => 'صورة الرخصة',
            'notes' => 'الملاحظات',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'full_name.required' => 'الاسم الكامل مطلوب.',
            'phone.required' => 'الهاتف مطلوب.',
            'birth_date.required' => 'تاريخ الميلاد مطلوب.',
            'license_number.required' => 'رقم الرخصة مطلوب.',
            'license_expires_on.required' => 'انتهاء الرخصة مطلوب.',
            'email.email' => 'البريد الإلكتروني غير صالح.',
        ];
    }
}
