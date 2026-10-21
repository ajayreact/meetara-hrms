<?php

namespace App\Http\Requests\Hrms;

use Illuminate\Foundation\Http\FormRequest;

class StoreOvertimeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->companyId() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'work_date' => ['required', 'date'],
            'hours' => ['required', 'regex:/^\d+(\.5)?$/'],
            'reason' => ['required', 'string', 'min:8', 'max:1000'],
            'tenant_id' => ['prohibited'],
            'employee_id' => ['prohibited'],
        ];
    }
}
