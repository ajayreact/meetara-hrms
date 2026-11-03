<?php

namespace App\Http\Requests\Hrms;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class StoreMyDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->companyId() !== null && $user->applicationRole() === 'employee';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category' => ['required', 'string', 'max:40'],
            'reference' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:500'],
            'file' => ['required', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png'],
            'tenant_id' => ['prohibited'],
            'employee_id' => ['prohibited'],
            'review_status' => ['prohibited'],
            'file_path' => ['prohibited'],
        ];
    }
}
