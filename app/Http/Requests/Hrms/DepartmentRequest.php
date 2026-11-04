<?php

namespace App\Http\Requests\Hrms;

use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $department = $this->route('department');

        if ($department instanceof Department) {
            return $this->user()?->can('update', $department) ?? false;
        }

        return $this->user()?->can('create', Department::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = $this->actor()->companyId() ?? 0;
        $departmentId = $this->route('department') instanceof Department ? $this->route('department')->id : null;

        return [
            'name' => [
                'required',
                'string',
                'max:191',
                Rule::unique('departments', 'name')->where('tenant_id', $companyId)->ignore($departmentId),
            ],
            'code' => ['nullable', 'string', 'max:191'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'code' => $this->input('code') === null || trim((string) $this->input('code')) === '' ? null : trim((string) $this->input('code')),
            'is_active' => $this->has('is_active') ? $this->boolean('is_active') : true,
        ]);
    }

    private function actor(): User
    {
        $user = $this->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
