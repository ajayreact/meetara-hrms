<?php

namespace App\Http\Requests\Hrms;

use App\Auth\AccountStatus;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreInternalMailRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->companyId() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'subject' => trim((string) $this->input('subject')),
            'message' => trim((string) $this->input('message')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'recipient_id' => ['required', 'integer'],
            'subject' => ['required', 'string', 'max:191'],
            'message' => ['required', 'string', 'max:5000'],
            'tenant_id' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'recipient_id' => 'recipient',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $sender = $this->user();
            $recipient = User::query()->whereKey($this->integer('recipient_id'))->first();

            if (! $sender instanceof User || ! $recipient instanceof User || ! $this->canReceive($sender, $recipient)) {
                $validator->errors()->add('recipient_id', 'Choose a person in your company.');
            }
        });
    }

    private function canReceive(User $sender, User $recipient): bool
    {
        return $recipient->id !== $sender->id
            && $recipient->companyId() !== null
            && $recipient->companyId() === $sender->companyId()
            && AccountStatus::denialReason($recipient) === null;
    }
}
