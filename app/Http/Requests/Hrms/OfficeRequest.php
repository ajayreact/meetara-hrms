<?php

namespace App\Http\Requests\Hrms;

use App\Models\Office;
use App\Models\User;
use App\Support\OfficeVerification;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class OfficeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $office = $this->route('office');

        if ($office instanceof Office) {
            return $this->user()?->can('update', $office) ?? false;
        }

        return $this->user()?->can('create', Office::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = $this->actor()->companyId() ?? 0;
        $officeId = $this->route('office') instanceof Office ? $this->route('office')->id : null;

        return [
            'name' => [
                'required',
                'string',
                'max:191',
                Rule::unique('locations', 'name')->where('tenant_id', $companyId)->ignore($officeId),
            ],
            'address' => ['nullable', 'string', 'max:2000'],
            'map_label' => ['nullable', 'string', 'max:120'],
            'timezone' => ['required', 'timezone'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'verification_radius_meters' => ['required', 'integer', 'between:'.OfficeVerification::MIN_RADIUS_METERS.','.OfficeVerification::MAX_RADIUS_METERS],
            'gps_verification_enabled' => ['present', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $latitude = $this->input('latitude');
        $longitude = $this->input('longitude');

        $label = trim((string) $this->input('map_label', ''));

        $this->merge([
            'latitude' => $latitude === '' || $latitude === null ? null : $latitude,
            'longitude' => $longitude === '' || $longitude === null ? null : $longitude,
            'map_label' => $label === '' ? null : $label,
            'gps_verification_enabled' => $this->boolean('gps_verification_enabled'),
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->boolean('gps_verification_enabled') && ($this->input('latitude') === null || $this->input('longitude') === null)) {
                $validator->errors()->add('latitude', 'Enter latitude and longitude before enabling GPS verification.');
            }
        });
    }

    private function actor(): User
    {
        $user = $this->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
