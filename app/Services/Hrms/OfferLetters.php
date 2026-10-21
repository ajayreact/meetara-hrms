<?php

namespace App\Services\Hrms;

use App\Models\Employee;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OfferLetters
{
    public const POSITIONS = [
        'opt_recruiter' => 'OPT Recruiter',
        'remote_opt_recruiter' => 'Remote OPT Recruiter',
        'bench_sales_recruiter' => 'Bench Sales Recruiter',
        'opt_team_lead' => 'OPT Team Lead',
        'bench_sales_team_lead' => 'Bench Sales Team Lead',
    ];

    public function __construct(
        private readonly EmployeeOnboarding $onboarding,
        private readonly OfferLetterReadiness $readiness,
    ) {}

    public function ready(): bool
    {
        return Schema::hasTable('offer_letters');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $actor, array $data): int
    {
        $this->guard($actor);
        $values = $this->values($actor, $data);

        return (int) DB::table('offer_letters')->insertGetId([
            ...$values,
            'tenant_id' => $actor->companyId(),
            'status' => 'draft',
            'version' => 1,
            'created_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function preview(User $actor, int $letterId): string
    {
        return DB::transaction(function () use ($actor, $letterId): string {
            $letter = $this->lock($actor, $letterId);
            $this->assertOpen($letter);
            $body = $this->body($actor, [
                'position_code' => $letter->position_code,
                'employee_name' => $letter->employee_name,
                'monthly_salary' => $letter->monthly_salary,
                'annual_ctc' => $letter->annual_ctc,
                'joining_date' => $letter->joining_date,
                'notice_months' => $letter->notice_months,
                'hr_name' => $letter->hr_name,
                'hr_signature' => $letter->hr_signature,
            ]);
            DB::table('offer_letters')->where('id', $letter->id)->update([
                'status' => 'generated',
                'preview_body' => $body,
                'updated_at' => now(),
            ]);

            return $body;
        });
    }

    public function issue(User $actor, int $letterId): void
    {
        DB::transaction(function () use ($actor, $letterId): void {
            $letter = $this->lock($actor, $letterId);
            $this->assertOpen($letter);

            if ($letter->preview_body === null || $letter->status !== 'generated') {
                throw ValidationException::withMessages(['letter' => 'Preview the letter before issuing it.']);
            }

            $this->readiness->assertReady();

            $path = $letter->employee_id
                ? 'employee-documents/'.(int) $letter->tenant_id.'/'.(int) $letter->employee_id.'/'.$letter->id.'-v'.$letter->version.'.pdf'
                : 'offer-letters/'.(int) $letter->tenant_id.'/'.$letter->id.'-v'.$letter->version.'.pdf';
            Storage::disk('local')->put($path, (new OfferLetterPdf)->render((string) $letter->preview_body));
            DB::table('offer_letters')->where('id', $letter->id)->update([
                'status' => 'issued',
                'issued_body' => $letter->preview_body,
                'issued_values' => json_encode([
                    'position' => $letter->position_code,
                    'employee_name' => $letter->employee_name,
                    'monthly_salary' => $letter->monthly_salary,
                    'annual_ctc' => $letter->annual_ctc,
                    'joining_date' => $letter->joining_date,
                    'notice_months' => $letter->notice_months,
                    'hr_name' => $letter->hr_name,
                ]),
                'issued_at' => now(),
                'issued_by' => $actor->id,
                'file_path' => $path,
                'updated_at' => now(),
            ]);
            $this->attachGeneratedCopy($actor, $letter, $path);
        });
    }

    public function decide(User $actor, int $letterId, string $status): void
    {
        DB::transaction(function () use ($actor, $letterId, $status): void {
            $letter = $this->lock($actor, $letterId);

            if ($letter->status !== 'issued') {
                throw ValidationException::withMessages(['letter' => 'Only an issued letter can be accepted or declined.']);
            }

            DB::table('offer_letters')->where('id', $letter->id)->update([
                'status' => $status,
                'updated_at' => now(),
            ]);
        });
    }

    public function revise(User $actor, int $letterId): int
    {
        return DB::transaction(function () use ($actor, $letterId): int {
            $letter = $this->lock($actor, $letterId);

            if (! in_array($letter->status, ['issued', 'accepted', 'declined'], true)) {
                throw ValidationException::withMessages(['letter' => 'Revise a letter only after it has been issued.']);
            }

            return (int) DB::table('offer_letters')->insertGetId([
                'tenant_id' => $letter->tenant_id,
                'employee_id' => $letter->employee_id,
                'position_code' => $letter->position_code,
                'employee_name' => $letter->employee_name,
                'monthly_salary' => $letter->monthly_salary,
                'annual_ctc' => $letter->annual_ctc,
                'joining_date' => $letter->joining_date,
                'notice_months' => $letter->notice_months,
                'hr_name' => $letter->hr_name,
                'hr_signature' => $letter->hr_signature,
                'status' => 'draft',
                'version' => ((int) $letter->version) + 1,
                'replaces_letter_id' => $letter->id,
                'created_by' => $actor->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function download(User $actor, int $letterId): StreamedResponse
    {
        $letter = $this->find($actor, $letterId);
        $prefixes = [
            'offer-letters/'.(int) $letter->tenant_id.'/',
            'employee-documents/'.(int) $letter->tenant_id.'/',
        ];
        $allowed = false;

        foreach ($prefixes as $prefix) {
            if (is_string($letter->file_path) && str_starts_with($letter->file_path, $prefix) && ! str_contains($letter->file_path, '..')) {
                $allowed = true;
            }
        }

        abort_unless(in_array($letter->status, ['issued', 'accepted', 'declined'], true) && $allowed, 404);
        abort_unless(Storage::disk('local')->exists($letter->file_path), 404);

        return Storage::disk('local')->download($letter->file_path, 'offer-letter-v'.$letter->version.'.pdf', [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function issuance(): array
    {
        return $this->readiness->present();
    }

    /**
     * @return list<array{id: int, label: string}>
     */
    public function employees(User $actor): array
    {
        if (! $this->ready() || ! $this->allowed($actor)) {
            return [];
        }

        return DB::table('employees')
            ->where('tenant_id', $actor->companyId())
            ->orderBy('employee_code')
            ->get(['id', 'employee_code', 'first_name', 'last_name'])
            ->map(fn ($employee) => [
                'id' => (int) $employee->id,
                'label' => $employee->employee_code.' · '.trim($employee->first_name.' '.$employee->last_name),
            ])->all();
    }

    /**
     * Offer letters already stored for one employee. Salary figures stay on the offer-letter page.
     *
     * @return list<array{id: int, position: string, status: string, version: int}>
     */
    public function forEmployee(User $actor, int $employeeId): array
    {
        if (! $this->ready() || ! $this->allowed($actor) || $actor->companyId() === null) {
            return [];
        }

        return DB::table('offer_letters')
            ->where('tenant_id', $actor->companyId())
            ->where('employee_id', $employeeId)
            ->orderByDesc('id')
            ->get(['id', 'position_code', 'status', 'version'])
            ->map(fn ($letter) => [
                'id' => (int) $letter->id,
                'position' => self::POSITIONS[$letter->position_code] ?? (string) $letter->position_code,
                'status' => ucfirst((string) $letter->status),
                'version' => (int) $letter->version,
            ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(User $actor): array
    {
        if (! $this->ready() || ! $this->allowed($actor)) {
            return [];
        }

        return DB::table('offer_letters')
            ->where('tenant_id', $actor->companyId())
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn ($letter) => [
                'id' => (int) $letter->id,
                'employeeId' => $letter->employee_id === null ? null : (int) $letter->employee_id,
                'position' => self::POSITIONS[$letter->position_code] ?? $letter->position_code,
                'employeeName' => (string) $letter->employee_name,
                'status' => ucfirst((string) $letter->status),
                'version' => (int) $letter->version,
                'monthly' => Money::inr((string) $letter->monthly_salary),
                'annual' => Money::inr((string) $letter->annual_ctc),
                'noticeMonths' => (int) $letter->notice_months,
                'preview' => $letter->status === 'draft' ? null : (string) ($letter->issued_body ?: $letter->preview_body),
            ])->all();
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function body(User $actor, array $values): string
    {
        $company = DB::table('tenants')->where('id', $actor->companyId())->value('name');
        $position = self::POSITIONS[$values['position_code']];

        return implode("\n", [
            'DRAFT — NOT APPROVED FOR ISSUANCE',
            '[PLACEHOLDER: approved appointment-letter wording is not on file]',
            'Company: '.($company ?: 'Not recorded'),
            'Position: '.$position,
            'Employee: '.$values['employee_name'],
            'Monthly salary: '.Money::inr((string) $values['monthly_salary']),
            'Annual amount (monthly x 12): '.Money::inr((string) $values['annual_ctc']),
            'Date of joining: '.$values['joining_date'],
            'Notice period: '.$values['notice_months'].' months',
            'HR: '.$values['hr_name'],
            'HR signature: '.$values['hr_signature'],
            '[PLACEHOLDER: duties for '.$position.' require the approved letter]',
            '[PLACEHOLDER: clauses, header, footer, and acceptance wording require the approved PDF]',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function values(User $actor, array $data): array
    {
        $companyId = (int) $actor->companyId();
        $employeeId = (int) $data['employee_id'];
        $employee = Employee::query()->whereKey($employeeId)->where('tenant_id', $companyId)->first();

        if ($employee === null) {
            throw ValidationException::withMessages(['employee_id' => 'Choose an employee from this company.']);
        }

        $name = trim($employee->first_name.' '.$employee->last_name);

        $monthly = Money::format(Money::cents((string) $data['monthly_salary']));

        return [
            'employee_id' => $employeeId,
            'position_code' => $data['position_code'],
            'employee_name' => $name,
            'monthly_salary' => $monthly,
            'annual_ctc' => Money::format(Money::cents($monthly) * 12),
            'joining_date' => $data['joining_date'],
            'notice_months' => (int) ($data['notice_months'] ?? 3),
            'hr_name' => trim((string) $data['hr_name']),
            'hr_signature' => trim((string) $data['hr_signature']),
        ];
    }

    private function attachGeneratedCopy(User $actor, object $letter, string $path): void
    {
        if ($letter->employee_id === null || ! $this->onboarding->ready()) {
            return;
        }

        $employee = Employee::query()->whereKey($letter->employee_id)->where('tenant_id', $letter->tenant_id)->first();

        if ($employee === null) {
            return;
        }

        $this->onboarding->open($actor, $employee);

        $alreadyStored = DB::table('employee_documents')->where('tenant_id', $employee->tenant_id)->where('employee_id', $employee->id)->where('file_path', $path)->exists();

        if (! $alreadyStored) {
            DB::table('employee_documents')->insert([
                'tenant_id' => $letter->tenant_id,
                'employee_id' => $employee->id,
                'category' => 'generated_offer',
                'title' => 'Generated offer letter',
                'file_path' => $path,
                'original_name' => 'offer-letter-v'.$letter->version.'.pdf',
                'review_status' => 'verified',
                'uploaded_by' => $actor->id,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->onboarding->record($employee, $actor, 'offer_issued', (string) $letter->position_code);
        }

        $this->onboarding->sync($employee);
    }

    private function lock(User $actor, int $letterId): object
    {
        $this->guard($actor);
        $letter = DB::table('offer_letters')->where('tenant_id', $actor->companyId())->where('id', $letterId)->lockForUpdate()->first();
        abort_if($letter === null, 404);

        return $letter;
    }

    private function find(User $actor, int $letterId): object
    {
        $this->guard($actor);
        $letter = DB::table('offer_letters')->where('tenant_id', $actor->companyId())->where('id', $letterId)->first();
        abort_if($letter === null, 404);

        return $letter;
    }

    private function assertOpen(object $letter): void
    {
        if (! in_array($letter->status, ['draft', 'generated'], true) || $letter->issued_body !== null) {
            throw ValidationException::withMessages(['letter' => 'An issued letter cannot be changed. Create a new version.']);
        }
    }

    private function guard(User $actor): void
    {
        abort_unless($this->ready() && $this->allowed($actor), 404);
    }

    private function allowed(User $actor): bool
    {
        return $actor->companyId() !== null && in_array($actor->applicationRole(), ['company_admin', 'hr'], true);
    }
}
