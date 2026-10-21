<?php

namespace App\Http\Requests\Hrms;

use Illuminate\Foundation\Http\FormRequest;

class PayrollNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        $run = $this->route('payrollRun');
        $user = $this->user();

        if ($user === null || $run === null) {
            return false;
        }

        return match ($this->route()->getName()) {
            'hrms.payroll.review', 'hrms.payroll.process', 'hrms.payroll.correct' => $user->can('prepare', $run),
            'hrms.payroll.approve' => $user->can('approve', $run),
            default => false,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $approving = $this->route()->getName() === 'hrms.payroll.approve';

        return [
            'note' => [$approving ? 'nullable' : 'required', 'string', $approving ? 'max:1000' : 'min:8', 'max:1000'],
            'confirm' => [$this->route()->getName() === 'hrms.payroll.process' ? 'required' : 'nullable', 'in:PROCESS'],
        ];
    }
}
