<?php

namespace App\Http\Requests\Hrms;

use Illuminate\Foundation\Http\FormRequest;

class RotateCandidateFormTokenRequest extends FormRequest
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
            'confirm_rotate' => ['required', 'accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'confirm_rotate.accepted' => 'Confirm that the current public link should stop working. Existing submissions are kept.',
        ];
    }
}
