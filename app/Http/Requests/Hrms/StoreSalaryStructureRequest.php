<?php

namespace App\Http\Requests\Hrms;

use Illuminate\Foundation\Http\FormRequest;

class StoreSalaryStructureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->applicationRole(), ['company_admin', 'hr', 'accounts'], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer'],
            'basic' => ['required', 'regex:/^\d+(\.\d{1,2})?$/'],
            'hra' => ['required', 'regex:/^\d+(\.\d{1,2})?$/'],
            'special_allowance' => ['required', 'regex:/^\d+(\.\d{1,2})?$/'],
            'effective_from' => ['required', 'date'],
            'currency' => ['required', 'regex:/^[A-Za-z]{3}$/'],
        ];
    }
}
