<?php

namespace App\Http\Requests\Hrms;

use App\Models\Holiday;
use Illuminate\Foundation\Http\FormRequest;

class HolidayRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $holiday = $this->route('holiday');

        if ($user === null || ! in_array($user->applicationRole(), ['company_admin', 'hr'], true)) {
            return false;
        }

        return ! $holiday instanceof Holiday || (int) $holiday->tenant_id === (int) $user->companyId();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'date' => ['required', 'date'],
            'calendar' => ['required', 'in:india,usa'],
            'location_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:active,inactive'],
        ];
    }
}
