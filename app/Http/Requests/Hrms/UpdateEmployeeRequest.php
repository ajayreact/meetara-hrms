<?php

namespace App\Http\Requests\Hrms;

use App\Models\Employee;

class UpdateEmployeeRequest extends EmployeeRequest
{
    public function authorize(): bool
    {
        $employee = $this->route('employee');

        return $employee instanceof Employee && ($this->user()?->can('update', $employee) ?? false);
    }
}
