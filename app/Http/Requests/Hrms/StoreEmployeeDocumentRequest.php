<?php

namespace App\Http\Requests\Hrms;

use App\Models\Employee;
use App\Models\User;
use App\Services\Hrms\EmployeeDocuments;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $employee = $this->route('employee');
        $user = $this->user();

        return $employee instanceof Employee
            && $user instanceof User
            && $user->can('view', $employee)
            && ($user->can('update', $employee) || (int) $employee->user_id === (int) $user->id);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category' => ['required', 'string', Rule::in(array_keys(EmployeeDocuments::CATEGORIES))],
            'file' => ['required', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png'],
            'tenant_id' => ['prohibited'],
            'employee_id' => ['prohibited'],
            'review_status' => ['prohibited'],
            'file_path' => ['prohibited'],
        ];
    }
}
