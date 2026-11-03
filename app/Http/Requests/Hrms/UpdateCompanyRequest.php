<?php

namespace App\Http\Requests\Hrms;

use App\Models\User;
use App\Services\Hrms\CompanyOnboarding;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->isPlatformAdmin();
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name', '')),
            'legal_name' => trim((string) $this->input('legal_name', '')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:191'],
            'legal_name' => ['nullable', 'string', 'max:191'],
            'timezone' => ['required', 'string', Rule::in(timezone_identifiers_list())],
            'email' => ['nullable', 'email', 'max:191'],
            'phone' => ['nullable', 'string', 'max:191'],
            'address' => ['nullable', 'string', 'max:2000'],
            'week_off' => ['nullable', 'array'],
            'week_off.*' => ['string', Rule::in(CompanyOnboarding::DAYS)],
            'slug' => ['prohibited'],
            'status' => ['prohibited'],
            'tenant_id' => ['prohibited'],
            'role' => ['prohibited'],
            'user_id' => ['prohibited'],
            'password' => ['prohibited'],
        ];
    }
}
