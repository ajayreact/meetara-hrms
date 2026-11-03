<?php

namespace App\Http\Requests\Hrms;

use App\Models\User;
use App\Services\Hrms\OfferLetters;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            'country' => ['nullable', Rule::in(['usa', 'india'])],
            'flow' => ['nullable', Rule::in(['appointment'])],
            'position_code' => ['required', Rule::in(array_keys(OfferLetters::POSITIONS))],
            'employee_id' => ['required', 'integer'],
            'employee_name' => ['prohibited'],
            'monthly_salary' => ['required', 'regex:/^\d+(\.\d{1,2})?$/'],
            'joining_date' => ['required', 'date'],
            'notice_unit' => ['nullable', Rule::in(['months', 'days'])],
            'notice_months' => ['exclude_if:notice_unit,days', 'nullable', 'integer', 'min:1', 'max:24'],
            'notice_days' => ['exclude_unless:notice_unit,days', 'required', 'integer', 'min:1', 'max:365'],
            'date_of_issue' => ['nullable', 'date'],
            'designation' => ['nullable', 'string', 'max:191'],
            'reporting_manager' => ['nullable', 'string', 'max:191'],
            'offer_notes' => ['nullable', 'string', 'max:2000'],
            'hr_name' => ['required', 'string', 'max:191'],
            'hr_signature' => ['required', 'string', 'max:191'],
            'hourly_rate' => ['nullable', 'regex:/^\d+(\.\d{1,2})?$/'],
            'basic_salary' => ['nullable', 'regex:/^\d+(\.\d{1,2})?$/'],
            'hra' => ['nullable', 'regex:/^\d+(\.\d{1,2})?$/'],
            'special_allowance' => ['nullable', 'regex:/^\d+(\.\d{1,2})?$/'],
            'probation_months' => ['nullable', 'integer', 'min:0', 'max:24'],
            'work_location' => ['nullable', 'string', 'max:191'],
            'candidate_address' => ['nullable', 'string', 'max:500'],
            'department' => ['nullable', 'string', 'max:191'],
            'employment_type' => ['nullable', 'string', 'max:40'],
            'work_mode' => ['nullable', 'string', 'max:80'],
            'working_hours' => ['nullable', 'string', 'max:80'],
            'salutation' => ['nullable', Rule::in(['mr', 'ms', 'mrs', 'dr'])],
            'salary_in_words' => ['prohibited'],
            'annual_ctc_in_words' => ['prohibited'],
            'phone' => ['prohibited'],
            'employee_code' => ['prohibited'],
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

        if ($this->input('notice_unit') === 'days') {
            $this->merge([
                'notice_unit' => 'days',
                'notice_months' => null,
            ]);
        } else {
            $this->merge(['notice_unit' => 'months']);

            if ($this->input('flow') !== 'appointment' && ! $this->filled('notice_months')) {
                $this->merge(['notice_months' => 3]);
            }
        }

        if (! $this->filled('country')) {
            $this->merge(['country' => 'india']);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $joining = substr((string) $this->input('joining_date'), 0, 10);
            $issue = substr((string) $this->input('date_of_issue'), 0, 10);

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $joining) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $issue) !== 1) {
                return;
            }

            if ($joining <= $issue) {
                $validator->errors()->add('joining_date', 'Joining date must be later than the date of issue.');
            }
        });
    }
}
