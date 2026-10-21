<?php

namespace App\Http\Requests\Hrms;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class StoreCompanyInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->isPlatformAdmin();
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => strtolower(trim((string) $this->input('email', ''))),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:150'],
            'role' => ['prohibited'],
            'tenant_id' => ['prohibited'],
            'company_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'password' => ['prohibited'],
            'token' => ['prohibited'],
        ];
    }
}
