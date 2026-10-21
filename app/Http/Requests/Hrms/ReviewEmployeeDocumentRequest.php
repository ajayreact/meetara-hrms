<?php

namespace App\Http\Requests\Hrms;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ReviewEmployeeDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $employee = $this->route('employee');
        $user = $this->user();

        return $employee instanceof Employee
            && $user instanceof User
            && $user->can('update', $employee)
            && (int) $employee->user_id !== (int) $user->id;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['under_review', 'verified', 'rejected'])],
            'note' => ['nullable', 'string', 'max:500', 'required_if:status,rejected'],
            'confirm' => ['required', 'string', 'in:REVIEW,VERIFY,REJECT'],
            'tenant_id' => ['prohibited'],
            'employee_id' => ['prohibited'],
            'file_path' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $expected = match ($this->string('status')->toString()) {
                'under_review' => 'REVIEW',
                'verified' => 'VERIFY',
                'rejected' => 'REJECT',
                default => '',
            };

            if ($this->string('confirm')->toString() !== $expected) {
                $validator->errors()->add('confirm', 'Confirm the review before saving it.');
            }
        });
    }
}
