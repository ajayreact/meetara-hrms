<?php

namespace App\Http\Requests\Hrms;

use App\Models\Employee;

class StoreEmployeeRequest extends EmployeeRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Employee::class) ?? false;
    }
}
