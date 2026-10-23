<?php

namespace App\Http\Requests\Hrms;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCandidateFormSettingsRequest extends FormRequest
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
            'enabled' => ['required', 'boolean'],
            'confirm_disable' => ['required', 'boolean'],
        ];
    }
}
