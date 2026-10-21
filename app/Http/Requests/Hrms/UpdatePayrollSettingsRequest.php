<?php

namespace App\Http\Requests\Hrms;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePayrollSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->applicationRole() === 'company_admin' && $this->user()->companyId() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'currency' => ['nullable', 'regex:/^[A-Za-z]{3}$/'],
            'statutory_status' => ['prohibited'],
            'statutory_rate' => ['prohibited'],
            'unpaid_leave_rate' => ['prohibited'],
            'attendance_deduction' => ['prohibited'],
            'proration' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'statutory_status.prohibited' => 'Statutory deductions stay off until a reviewed rule exists.',
            'statutory_rate.prohibited' => 'Statutory deductions stay off until a reviewed rule exists.',
            'unpaid_leave_rate.prohibited' => 'Unpaid leave is not configured.',
            'attendance_deduction.prohibited' => 'Attendance deductions are not configured.',
            'proration.prohibited' => 'Proration is not configured.',
        ];
    }
}
