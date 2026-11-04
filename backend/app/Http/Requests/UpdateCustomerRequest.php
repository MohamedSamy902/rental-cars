<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesDigits;
use App\Rules\EgyptianNationalId;
use App\Rules\EgyptianPhone;
use App\Rules\MediaReference;
use App\Support\DigitNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateCustomerRequest extends FormRequest
{
    use NormalizesDigits;

    private bool $forbidBlacklistFields = false;

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

    protected function prepareForValidation(): void
    {
        $this->merge(DigitNormalizer::fields($this->all(), $this->digitKeys()));

        $canBlacklist = (bool) $this->user()?->hasPermission('blacklist.manage');
        if (! $canBlacklist && ($this->exists('status') || $this->exists('blacklist_reason'))) {
            $this->forbidBlacklistFields = true;
            $this->replace($this->except(['status', 'blacklist_reason']));
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'full_name' => ['sometimes', 'string', 'max:200'],
            'phone' => ['sometimes', 'string', 'max:30', new EgyptianPhone],
            'phone_alt' => ['nullable', 'string', 'max:30', new EgyptianPhone],
            'email' => ['nullable', 'email', 'max:200'],
            'national_id' => ['nullable', 'string', 'max:30', new EgyptianNationalId],
            'national_id_expires_on' => ['nullable', 'date'],
            'passport_number' => ['nullable', 'string', 'max:50'],
            'passport_expires_on' => ['nullable', 'date'],
            'birth_date' => ['sometimes', 'date'],
            'address' => ['nullable', 'string'],
            'license_number' => ['sometimes', 'string', 'max:50'],
            'license_expires_on' => ['sometimes', 'date'],
            'national_id_image_path' => ['nullable', 'string', 'max:500', new MediaReference],
            'national_id_back_path' => ['nullable', 'string', 'max:500', new MediaReference],
            'license_image_path' => ['nullable', 'string', 'max:500', new MediaReference],
            'license_back_path' => ['nullable', 'string', 'max:500', new MediaReference],
            'national_id_media_id' => ['nullable', 'integer', 'exists:media_files,id'],
            'license_media_id' => ['nullable', 'integer', 'exists:media_files,id'],
            'notes' => ['nullable', 'string'],
            'status' => ['sometimes', 'in:active,blacklist'],
            'blacklist_reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->forbidBlacklistFields) {
                $validator->errors()->add(
                    'status',
                    'ليست لديك صلاحية إدارة القائمة السوداء.',
                );
            }
        });
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
            'status' => 'الحالة',
            'blacklist_reason' => 'سبب القائمة السوداء',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.string' => 'الهاتف غير صالح.',
            'status.in' => 'الحالة غير صالحة.',
            'email.email' => 'البريد الإلكتروني غير صالح.',
            'license_number.required' => 'رقم الرخصة مطلوب.',
        ];
    }
}
