<?php

namespace App\Http\Requests\Hrms;

use App\Models\Office;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class OfficeScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Office::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'work_start' => ['required', 'date_format:H:i'],
            'work_end' => ['required', 'date_format:H:i'],
            'grace_minutes' => ['required', 'integer', 'min:0', 'max:120'],
            'working_days' => ['required', 'array', 'min:1'],
            'working_days.*' => ['string', Rule::in(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'])],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $start = (string) $this->input('work_start');
            $end = (string) $this->input('work_end');

            if ($start !== '' && $end !== '' && $end <= $start) {
                $validator->errors()->add('work_end', 'The end time must be after the start time.');
            }
        });
    }
}
