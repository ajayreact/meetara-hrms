<?php

namespace App\Http\Requests\Hrms;

use App\Models\Designation;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DesignationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $designation = $this->route('designation');

        if ($designation instanceof Designation) {
            return $this->user()?->can('update', $designation) ?? false;
        }

        return $this->user()?->can('create', Designation::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = $this->actor()->companyId() ?? 0;
        $designationId = $this->route('designation') instanceof Designation ? $this->route('designation')->id : null;

        return [
            'name' => [
                'required',
                'string',
                'max:191',
                Rule::unique('designations', 'name')->where('tenant_id', $companyId)->ignore($designationId),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
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
