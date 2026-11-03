<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class AcceptEmployeeInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() === null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'confirmed', Password::min(8)->letters()->mixedCase()->numbers()],
            'email' => ['prohibited'],
            'name' => ['prohibited'],
            'role' => ['prohibited'],
            'tenant_id' => ['prohibited'],
            'company_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'employee_id' => ['prohibited'],
            'token' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.min' => 'Use at least 8 characters with upper and lower case letters and a number.',
            'password.letters' => 'Use at least 8 characters with upper and lower case letters and a number.',
            'password.mixed' => 'Use at least 8 characters with upper and lower case letters and a number.',
            'password.numbers' => 'Use at least 8 characters with upper and lower case letters and a number.',
        ];
    }
}
