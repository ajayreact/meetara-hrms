<?php

namespace App\Http\Requests\Hrms;

use App\Services\Hrms\HelpDeskService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->companyId() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category' => ['required', Rule::in(HelpDeskService::CATEGORIES)],
            'title' => ['required', 'string', 'min:4', 'max:191'],
            'description' => ['required', 'string', 'min:8', 'max:5000'],
            'priority' => ['required', Rule::in(HelpDeskService::PRIORITIES)],
            'tenant_id' => ['prohibited'],
            'employee_id' => ['prohibited'],
            'assignee_id' => ['prohibited'],
        ];
    }
}
