<?php

namespace App\Http\Requests\Hrms;

use App\Models\Office;
use App\Support\NetworkAddress;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class OfficeNetworkRequest extends FormRequest
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
            'cidr' => ['required', 'string', 'max:64'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            try {
                $cidr = NetworkAddress::normalizeCidr((string) $this->input('cidr'));
                $this->merge(['cidr' => $cidr]);
                $validator->setData(array_merge($validator->getData(), ['cidr' => $cidr]));
            } catch (\InvalidArgumentException) {
                $validator->errors()->add('cidr', 'Enter a public IPv4 or IPv6 address, or a public CIDR range.');
            }
        });
    }
}
