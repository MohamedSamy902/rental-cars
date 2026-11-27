<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesDigits;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ExtendContractRequest extends FormRequest
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
        return ['planned_ends_at', 'extension_days', 'period_rent'];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'planned_ends_at' => ['nullable', 'date', 'before:2100-01-01'],
            'extension_days' => ['nullable', 'integer', 'min:1', 'max:3660'],
            'period_rent' => ['required', 'integer', 'min:1'],
            // يُتجاهل في الخدمة (التمديد داخل نفس العقد يحافظ على starts_at الأصلية).
            'starts_at' => ['nullable', 'date'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $days = $this->input('extension_days');
            $ends = $this->input('planned_ends_at');
            if (! $days && ! $ends) {
                $validator->errors()->add(
                    'extension_days',
                    'أدخل مدة التمديد بالأيام أو تاريخ النهاية الجديدة.',
                );

                return;
            }

            if (! $ends) {
                return;
            }

            $contract = $this->route('contract');
            $currentEnd = $contract?->planned_ends_at?->format('Y-m-d H:i:s');
            if (! $currentEnd) {
                return;
            }

            if (strtotime((string) $ends) <= strtotime((string) $currentEnd)) {
                $validator->errors()->add(
                    'planned_ends_at',
                    'يجب أن تكون نهاية التمديد بعد النهاية الحالية للعقد.',
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
            'planned_ends_at' => 'نهاية التمديد',
            'extension_days' => 'مدة التمديد',
            'period_rent' => 'إيجار مدة التمديد',
            'starts_at' => 'بداية التمديد',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'planned_ends_at.date' => 'نهاية التمديد غير صالحة.',
            'planned_ends_at.before' => 'نهاية التمديد خارج النطاق المسموح.',
            'extension_days.integer' => 'مدة التمديد يجب أن تكون عدداً صحيحاً.',
            'extension_days.min' => 'مدة التمديد يوم واحد على الأقل.',
            'extension_days.max' => 'مدة التمديد خارج النطاق المسموح.',
            'period_rent.required' => 'إيجار مدة التمديد مطلوب.',
            'period_rent.integer' => 'إيجار مدة التمديد يجب أن يكون عدداً صحيحاً.',
            'period_rent.min' => 'إيجار مدة التمديد يجب أن يكون أكبر من صفر.',
            'starts_at.date' => 'بداية التمديد غير صالحة.',
        ];
    }
}
