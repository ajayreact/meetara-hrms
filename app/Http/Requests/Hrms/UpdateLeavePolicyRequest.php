<?php

namespace App\Http\Requests\Hrms;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLeavePolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->applicationRole() === 'company_admin';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'applicable_after_days' => ['required', 'integer', 'min:0', 'max:365'],
        ];
    }
}
