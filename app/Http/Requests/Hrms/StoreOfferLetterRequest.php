<?php

namespace App\Http\Requests\Hrms;

use App\Models\User;
use App\Services\Hrms\OfferLetters;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOfferLetterRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && in_array($user->applicationRole(), ['company_admin', 'hr'], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'position_code' => ['required', Rule::in(array_keys(OfferLetters::POSITIONS))],
            'employee_id' => ['required', 'integer'],
            'employee_name' => ['prohibited'],
            'monthly_salary' => ['required', 'regex:/^\d+(\.\d{1,2})?$/'],
            'joining_date' => ['required', 'date'],
            'notice_months' => ['nullable', 'integer', 'min:1', 'max:24'],
            'hr_name' => ['required', 'string', 'max:191'],
            'hr_signature' => ['required', 'string', 'max:191'],
            'tenant_id' => ['prohibited'],
            'annual_ctc' => ['prohibited'],
            'status' => ['prohibited'],
            'file_path' => ['prohibited'],
            'template_approved' => ['prohibited'],
            'approval_status' => ['prohibited'],
            'approved_by' => ['prohibited'],
            'legal_name' => ['prohibited'],
            'legal_address' => ['prohibited'],
            'address_verified' => ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('employee_id') === '' || $this->input('employee_id') === null) {
            $this->merge(['employee_id' => null]);
        }

        if (! $this->filled('notice_months')) {
            $this->merge(['notice_months' => 3]);
        }
    }
}
