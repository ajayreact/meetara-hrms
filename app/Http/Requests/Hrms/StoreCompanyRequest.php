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
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:191'],
            'slug' => ['required', 'string', 'max:191', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('tenants', 'slug')],
            'timezone' => ['required', 'string', Rule::in(timezone_identifiers_list())],
            'email' => ['nullable', 'email', 'max:191'],
            'phone' => ['nullable', 'string', 'max:191'],
            'address' => ['nullable', 'string', 'max:2000'],
            'week_off' => ['nullable', 'array'],
            'week_off.*' => ['string', Rule::in(CompanyOnboarding::DAYS)],
            'status' => ['prohibited'],
            'tenant_id' => ['prohibited'],
            'role' => ['prohibited'],
            'user_id' => ['prohibited'],
            'password' => ['prohibited'],
            'is_platform_admin' => ['prohibited'],
            'admin_user_id' => ['prohibited'],
        ];
    }
}
