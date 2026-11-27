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
        return ['planned_ends_at'];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'planned_ends_at' => ['required', 'date', 'before:2100-01-01'],
            // يُتجاهل في الخدمة (التمديد داخل نفس العقد يحافظ على starts_at الأصلية).
            'starts_at' => ['nullable', 'date'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $ends = $this->input('planned_ends_at');
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
            'starts_at' => 'بداية التمديد',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'planned_ends_at.required' => 'نهاية التمديد مطلوبة.',
            'planned_ends_at.date' => 'نهاية التمديد غير صالحة.',
            'planned_ends_at.before' => 'نهاية التمديد خارج النطاق المسموح.',
            'starts_at.date' => 'بداية التمديد غير صالحة.',
        ];
    }
}
