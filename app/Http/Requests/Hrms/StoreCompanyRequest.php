<?php

namespace App\Http\Requests\Hrms;

use App\Models\User;
use App\Services\Hrms\CompanyOnboarding;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->isPlatformAdmin();
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'slug' => CompanyOnboarding::normalizeSlug((string) $this->input('slug', '')),
            'name' => trim((string) $this->input('name', '')),
            'legal_name' => trim((string) $this->input('legal_name', '')),
            'admin_name' => trim((string) $this->input('admin_name', '')),
            'admin_email' => strtolower(trim((string) $this->input('admin_email', ''))),
            'status' => trim((string) $this->input('status', 'active')) ?: 'active',
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
            'slug' => ['required', 'string', 'max:191', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('tenants', 'slug')],
            'timezone' => ['required', 'string', Rule::in(timezone_identifiers_list())],
            'email' => ['nullable', 'email', 'max:191'],
            'phone' => ['nullable', 'string', 'max:191'],
            'address' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'week_off' => ['nullable', 'array'],
            'week_off.*' => ['string', Rule::in(CompanyOnboarding::DAYS)],
            'admin_name' => ['nullable', 'required_with:admin_email,admin_password', 'string', 'max:191'],
            'admin_email' => ['nullable', 'required_with:admin_name,admin_password', 'email', 'max:191', Rule::unique('users', 'email')],
            'admin_password' => ['nullable', 'required_with:admin_name,admin_email', 'string', 'min:8', 'confirmed'],
            'tenant_id' => ['prohibited'],
            'role' => ['prohibited'],
            'user_id' => ['prohibited'],
            'password' => ['prohibited'],
            'is_platform_admin' => ['prohibited'],
            'admin_user_id' => ['prohibited'],
        ];
    }
}
