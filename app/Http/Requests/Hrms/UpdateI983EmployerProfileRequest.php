<?php

namespace App\Http\Requests\Hrms;

use App\Services\Hrms\I983EmployerProfile;
use Illuminate\Foundation\Http\FormRequest;

class UpdateI983EmployerProfileRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $address = app(I983EmployerProfile::class)->splitAddress(
            (string) $this->input('street', ''),
            (string) $this->input('suite', ''),
            (string) $this->input('city', ''),
            (string) $this->input('state', ''),
            (string) $this->input('zip', ''),
        );
        $this->merge($address);
    }

    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && in_array($user->applicationRole(), ['company_admin', 'hr'], true)
            && $user->companyId() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'legal_name' => ['required', 'string', 'max:191'],
            'website' => ['nullable', 'string', 'max:191'],
            'street' => ['required', 'string', 'max:191'],
            'suite' => ['nullable', 'string', 'max:40'],
            'city' => ['required', 'string', 'max:80'],
            'state' => ['required', 'string', 'regex:/^[A-Za-z]{2}$/'],
            'zip' => ['required', 'string', 'regex:/^\d{5}(-\d{4})?$/'],
            'ein' => ['required', 'string', 'regex:/^\d{2}-?\d{7}$/'],
            'employee_count' => ['required', 'integer', 'min:1', 'max:10000000'],
            'naics' => ['required', 'string', 'regex:/^\d{2,6}$/'],
            'oversight' => ['nullable', 'string', 'max:5000'],
            'measures' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'state.regex' => 'Enter the two-letter state code.',
            'zip.regex' => 'Enter a 5-digit ZIP code.',
            'ein.regex' => 'Enter the 9-digit EIN.',
            'naics.regex' => 'Enter the numeric NAICS code.',
        ];
    }
}
