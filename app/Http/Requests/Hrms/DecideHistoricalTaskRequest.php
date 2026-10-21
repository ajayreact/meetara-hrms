<?php

namespace App\Http\Requests\Hrms;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DecideHistoricalTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $employee = $this->route('employee');

        return $user instanceof User
            && $employee instanceof Employee
            && $user->can('update', $employee);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'outcome' => ['required', Rule::in(['completed', 'not_applicable', 'pending'])],
            'note' => ['nullable', 'string', 'max:5000', Rule::requiredIf(fn () => in_array($this->input('outcome'), ['completed', 'not_applicable'], true))],
            'confirm' => ['required', Rule::in(['RECORD'])],
        ];
    }
}
