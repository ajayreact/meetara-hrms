<?php

namespace App\Http\Requests\Hrms;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

abstract class EmployeeRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = $this->actor()->companyId() ?? 0;
        $employeeId = $this->route('employee') instanceof Employee ? $this->route('employee')->id : null;

        return [
            'first_name' => ['required', 'string', 'max:191'],
            'last_name' => ['required', 'string', 'max:191'],
            'email' => [
                'required',
                'email',
                'max:191',
                Rule::unique('employees', 'email')->where('tenant_id', $companyId)->ignore($employeeId),
                Rule::unique('users', 'email')->ignore($this->linkedUserId()),
            ],
            'phone' => ['nullable', 'string', 'max:191'],
            'employee_code' => [
                'required',
                'string',
                'max:191',
                Rule::unique('employees', 'employee_code')->where('tenant_id', $companyId)->ignore($employeeId),
            ],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->where('tenant_id', $companyId)],
            'designation_id' => ['nullable', 'integer', Rule::exists('designations', 'id')->where('tenant_id', $companyId)],
            'location_id' => ['nullable', 'integer', Rule::exists('locations', 'id')->where('tenant_id', $companyId)],
            'manager_id' => ['nullable', 'integer', Rule::exists('employees', 'id')->where('tenant_id', $companyId)],
            'date_of_joining' => ['required', 'date'],
            'holiday_calendar' => ['required', Rule::in(['india', 'usa'])],
            'employment_type' => ['required', Rule::in(['full_time', 'part_time', 'contract'])],
            'work_mode' => ['required', Rule::in(['office', 'hybrid', 'remote'])],
            'role' => ['nullable', Rule::in($this->assignableRoles())],
            'create_login' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $actor = $this->actor();
            $companyId = $actor->companyId();

            if ($companyId === null) {
                $validator->errors()->add('email', 'This account is not a member of a company.');

                return;
            }

            if ($this->boolean('create_login') && ! $this->filled('role')) {
                $validator->errors()->add('role', 'Choose an access level when creating a login.');
            }

            if ($this->filled('role')) {
                $exists = $actor->getConnection()->table('roles')
                    ->where('tenant_id', $companyId)
                    ->where('name', $this->string('role')->toString())
                    ->where('guard_name', 'web')
                    ->exists();

                if (! $exists) {
                    $validator->errors()->add('role', 'That access level is not available for this company.');
                }
            }

            $managerId = $this->input('manager_id');
            $employee = $this->route('employee');

            if ($managerId && $employee instanceof Employee && (int) $managerId === (int) $employee->id) {
                $validator->errors()->add('manager_id', 'An employee cannot report to themselves.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'department_id' => $this->emptyToNull('department_id'),
            'designation_id' => $this->emptyToNull('designation_id'),
            'location_id' => $this->emptyToNull('location_id'),
            'manager_id' => $this->emptyToNull('manager_id'),
            'phone' => $this->emptyToNull('phone'),
            'role' => $this->emptyToNull('role'),
            'create_login' => $this->boolean('create_login'),
        ]);
    }

    /**
     * @return list<string>
     */
    protected function assignableRoles(): array
    {
        return match ($this->actor()->applicationRole()) {
            'company_admin' => ['Employee', 'Manager', 'HR', 'Accounts', 'Owner'],
            'hr' => ['Employee', 'Manager', 'HR'],
            default => [],
        };
    }

    protected function actor(): User
    {
        $user = $this->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    protected function linkedUserId(): ?int
    {
        $employee = $this->route('employee');

        if (! $employee instanceof Employee || $employee->user_id === null) {
            return null;
        }

        return (int) $employee->user_id;
    }

    private function emptyToNull(string $key): mixed
    {
        $value = $this->input($key);

        return $value === '' || $value === null ? null : $value;
    }
}
