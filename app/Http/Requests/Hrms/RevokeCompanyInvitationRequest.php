<?php

namespace App\Http\Requests\Hrms;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class RevokeCompanyInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->isPlatformAdmin();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'confirm' => ['required', 'string'],
            'tenant_id' => ['prohibited'],
            'role' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('confirm') !== 'REVOKE') {
                $validator->errors()->add('confirm', 'Confirm revocation before saving it.');
            }
        });
    }
}
