<?php

namespace App\Http\Requests\Hrms;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CompanyStatusRequest extends FormRequest
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
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'confirm' => ['required', 'string'],
            'tenant_id' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $expected = $this->input('status') === 'inactive' ? 'DEACTIVATE' : 'REACTIVATE';
            if ($this->input('confirm') !== $expected) {
                $validator->errors()->add('confirm', 'Confirm this status change before saving it.');
            }
        });
    }
}
