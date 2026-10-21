<?php

namespace App\Http\Requests\Hrms;

use Illuminate\Foundation\Http\FormRequest;

class RecordPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', $this->route('payment')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        if ($this->route()->getName() === 'hrms.payments.reverse') {
            return ['note' => ['required', 'string', 'min:8', 'max:1000']];
        }

        return [
            'paid_on' => ['required', 'date'],
            'method' => ['required', 'in:bank_transfer,cheque,cash,other'],
            'reference' => ['required', 'string', 'max:80'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
