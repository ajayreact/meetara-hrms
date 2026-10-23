<?php

namespace App\Http\Requests\Hrms;

use App\Models\Office;
use Illuminate\Foundation\Http\FormRequest;

class ApproveDetectedNetworkRequest extends FormRequest
{
    public function authorize(): bool
    {
        $office = $this->route('office');

        return $office instanceof Office && ($this->user()?->can('update', $office) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'confirm_detected' => ['required', 'accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'confirm_detected.accepted' => 'Confirm that the public address observed by the server should be approved for this office.',
        ];
    }
}
