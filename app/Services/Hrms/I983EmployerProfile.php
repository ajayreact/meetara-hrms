<?php

namespace App\Services\Hrms;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class I983EmployerProfile
{
    public function manages(User $actor): bool
    {
        return in_array($actor->applicationRole(), ['company_admin', 'hr'], true) && $actor->companyId() !== null;
    }

    /**
     * @return array{ready: bool, saved: bool, legalName: string, website: string, street: string, suite: string, city: string, state: string, zip: string, ein: string, employeeCount: string, naics: string}
     */
    public function present(User $actor): array
    {
        $empty = [
            'ready' => $this->ready(),
            'saved' => false,
            'legalName' => '',
            'website' => '',
            'street' => '',
            'suite' => '',
            'city' => '',
            'state' => '',
            'zip' => '',
            'ein' => '',
            'employeeCount' => '',
            'naics' => '',
        ];

        if (! $this->ready() || ! $this->manages($actor)) {
            return $empty;
        }

        $row = $this->row((int) $actor->companyId());

        if ($row === null) {
            return $empty;
        }

        return [
            'ready' => true,
            'saved' => true,
            'legalName' => (string) $row->legal_name,
            'website' => (string) ($row->website ?? ''),
            'street' => (string) $row->street,
            'suite' => (string) ($row->suite ?? ''),
            'city' => (string) $row->city,
            'state' => (string) $row->state,
            'zip' => (string) $row->zip,
            'ein' => $this->reveal($row->ein_ciphertext),
            'employeeCount' => (string) $row->employee_count,
            'naics' => (string) $row->naics,
        ];
    }

    /**
     * Values copied onto a new I-983. A missing profile returns null so the caller can keep its previous suggestion.
     *
     * @return array{legal_name: string, website: ?string, street: string, suite: ?string, city: string, state: string, zip: string, ein: string, employee_count: int, naics: string}|null
     */
    public function snapshot(int $companyId): ?array
    {
        $row = $this->row($companyId);

        if ($row === null) {
            return null;
        }

        return [
            'legal_name' => (string) $row->legal_name,
            'website' => $row->website === null || $row->website === '' ? null : (string) $row->website,
            'street' => (string) $row->street,
            'suite' => $row->suite === null || $row->suite === '' ? null : (string) $row->suite,
            'city' => (string) $row->city,
            'state' => (string) $row->state,
            'zip' => (string) $row->zip,
            'ein' => $this->reveal($row->ein_ciphertext),
            'employee_count' => (int) $row->employee_count,
            'naics' => (string) $row->naics,
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function save(User $actor, array $values): void
    {
        if (! $this->manages($actor)) {
            abort(403);
        }

        if (! $this->ready()) {
            throw ValidationException::withMessages([
                'form' => 'U.S. employer information storage is not ready on this database.',
            ]);
        }

        $companyId = (int) $actor->companyId();
        $now = now();
        $existing = $this->row($companyId);

        DB::table('i983_employer_profiles')->updateOrInsert(
            ['tenant_id' => $companyId],
            [
                'legal_name' => trim((string) $values['legal_name']),
                'website' => $this->blank($values['website'] ?? null),
                'street' => trim((string) $values['street']),
                'suite' => $this->blank($values['suite'] ?? null),
                'city' => trim((string) $values['city']),
                'state' => strtoupper(trim((string) $values['state'])),
                'zip' => trim((string) $values['zip']),
                'ein_ciphertext' => Crypt::encryptString(trim((string) $values['ein'])),
                'employee_count' => (int) $values['employee_count'],
                'naics' => trim((string) $values['naics']),
                'updated_by' => $actor->id,
                'created_at' => $existing->created_at ?? $now,
                'updated_at' => $now,
            ],
        );
    }

    public function ready(): bool
    {
        return Schema::hasTable('i983_employer_profiles');
    }

    private function row(int $companyId): ?object
    {
        if (! $this->ready()) {
            return null;
        }

        return DB::table('i983_employer_profiles')->where('tenant_id', $companyId)->first();
    }

    private function reveal(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            return '';
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return '';
        }
    }

    private function blank(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
