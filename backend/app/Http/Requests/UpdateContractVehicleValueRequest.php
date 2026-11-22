<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateContractVehicleValueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'include_vehicle_value_obligation' => ['required', 'boolean'],
            'vehicle_value_text' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($v->errors()->isNotEmpty()) {
                return;
            }
            $include = filter_var($this->input('include_vehicle_value_obligation'), FILTER_VALIDATE_BOOLEAN);
            $text = trim((string) $this->input('vehicle_value_text', ''));
            if ($include && $text === '') {
                $v->errors()->add('vehicle_value_text', 'اكتب نص ثمن العربية عند تفعيل الالتزام.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'include_vehicle_value_obligation' => 'الالتزام بثمن العربية',
            'vehicle_value_text' => 'نص ثمن العربية',
        ];
    }
}
