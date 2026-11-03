<?php

namespace App\Services\Hrms;

use App\Models\Employee;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\SalaryStructure;
use App\Models\User;
use App\Support\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PayrollImport
{
    /**
     * @var array<string, list<string>>
     */
    public const FIELDS = [
        'employee_id' => ['employee id', 'employee_id', 'employee code', 'emp id', 'emp code', 'employeeid'],
        'employee_name' => ['employee name', 'name'],
        'payroll_month' => ['payroll month', 'month'],
        'payroll_year' => ['payroll year', 'year'],
        'basic' => ['basic salary', 'basic'],
        'hra' => ['hra', 'house rent allowance'],
        'special' => ['special allowance', 'special'],
        'other_earnings' => ['other earnings', 'other earning'],
        'gross' => ['gross salary', 'gross'],
        'pf' => ['pf', 'provident fund'],
        'esi' => ['esi', 'employee state insurance'],
        'professional_tax' => ['professional tax', 'prof tax', 'pt'],
        'tds' => ['tds', 'tax deducted at source'],
        'other_deductions' => ['other deductions', 'other deduction'],
        'deductions' => ['total deductions', 'deductions'],
        'net' => ['net salary', 'net pay', 'net payable', 'net'],
        'paid_days' => ['paid days'],
        'working_days' => ['working days'],
        'lwp' => ['leave without pay', 'lwp', 'lop days'],
    ];

    /**
     * @var list<string>
     */
    private const EARNINGS = ['basic', 'hra', 'special', 'other_earnings'];

    /**
     * @var list<string>
     */
    private const DEDUCTION_PARTS = ['pf', 'esi', 'professional_tax', 'tds', 'other_deductions'];

    public function __construct(
        private readonly SpreadsheetTable $tables,
        private readonly PayslipPdf $pdf,
        private readonly PayrollDirectory $directory,
        private readonly CompanyNotifier $notifier,
    ) {}

    public function ready(): bool
    {
        return Schema::hasTable('payroll_runs')
            && Schema::hasColumn('payroll_runs', 'import_rows')
            && Schema::hasColumn('payroll_items', 'import_supplied');
    }

    /**
     * @param  array<string, string|null>  $mapping
     */
    public function stage(User $actor, int $year, int $month, UploadedFile $file, array $mapping = []): PayrollRun
    {
        $this->assertReady();
        $table = $this->tables->read($file->getRealPath() ?: $file->getPathname(), (string) $file->getClientOriginalExtension());
        $resolved = $this->resolveMapping($table['headers'], $mapping);
        $preview = $this->preview($actor, $year, $month, $table['headers'], $table['rows'], $resolved);
        $run = $this->draft($actor, $year, $month);
        $path = $file->storeAs('payroll-imports/'.(int) $actor->companyId(), Str::uuid()->toString().'.'.$this->extension($file), 'local');

        if (! is_string($path) || $path === '') {
            throw new PayrollRejected('The uploaded file could not be stored.');
        }

        $previous = $run->file_path;
        $run->forceFill([
            'source' => 'import',
            'status' => 'import_review',
            'original_name' => $file->getClientOriginalName(),
            'file_path' => $path,
            'uploaded_by' => $actor->id,
            'uploaded_at' => now(),
            'confirmed_by' => null,
            'payslip_status' => 'pending',
            'import_rows' => $preview,
        ])->save();
        $this->deletePrivate($previous, (int) $actor->companyId());
        $this->event($run, $actor, 'payroll_uploaded', $file->getClientOriginalName());

        return $run->refresh();
    }

    /**
     * @param  array<string, string|null>  $mapping
     */
    public function remap(User $actor, PayrollRun $run, array $mapping): PayrollRun
    {
        $this->assertReady();
        $this->assertReview($actor, $run);
        $stored = $this->storedTable($run);
        $resolved = $this->resolveMapping($stored['headers'], $mapping);
        $preview = $this->preview($actor, (int) $run->year, (int) $run->month, $stored['headers'], $stored['rows'], $resolved);
        $run->forceFill(['import_rows' => $preview])->save();

        return $run->refresh();
    }

    public function confirm(User $actor, PayrollRun $run): PayrollRun
    {
        $this->assertReady();
        $this->assertReview($actor, $run);
        $preview = is_array($run->import_rows) ? $run->import_rows : [];
        $rows = is_array($preview['rows'] ?? null) ? $preview['rows'] : [];
        $errors = 0;

        foreach ($rows as $row) {
            if (($row['errors'] ?? []) !== []) {
                $errors++;
            }
        }

        if (($preview['fileError'] ?? null) !== null || $rows === [] || $errors > 0) {
            throw new PayrollRejected('Fix every row in the Excel file before confirming this payroll import.');
        }

        DB::transaction(function () use ($actor, $run, $rows): void {
            $locked = PayrollRun::query()->whereKey($run->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== 'import_review') {
                throw new PayrollRejected('This payroll import was already confirmed.');
            }

            foreach ($rows as $row) {
                $supplied = is_array($row['supplied'] ?? null) ? $row['supplied'] : [];
                $currency = SalaryStructure::query()
                    ->where('tenant_id', $actor->companyId())
                    ->where('employee_id', $row['employeeId'])
                    ->orderByDesc('effective_from')
                    ->orderByDesc('id')
                    ->value('currency');

                $item = new PayrollItem;
                $item->forceFill([
                    'tenant_id' => $actor->companyId(),
                    'payroll_run_id' => $run->id,
                    'employee_id' => $row['employeeId'],
                    'currency' => $currency,
                    'basic' => $supplied['basic'] ?? 0,
                    'hra' => $supplied['hra'] ?? 0,
                    'special_allowance' => $supplied['special'] ?? 0,
                    'other_earnings' => $supplied['other_earnings'] ?? null,
                    'gross' => $supplied['gross'] ?? 0,
                    'pf_employee' => $supplied['pf'] ?? 0,
                    'esi_employee' => $supplied['esi'] ?? 0,
                    'professional_tax' => $supplied['professional_tax'] ?? null,
                    'tds' => $supplied['tds'] ?? 0,
                    'other_deductions' => $supplied['other_deductions'] ?? null,
                    'deductions' => $supplied['deductions'] ?? 0,
                    'net' => $supplied['net'] ?? 0,
                    'present_days' => $supplied['paid_days'] ?? 0,
                    'working_days' => $supplied['working_days'] ?? 0,
                    'lop_days' => $supplied['lwp'] ?? 0,
                    'payroll_status' => 'processed',
                    'import_supplied' => $supplied,
                ])->save();
            }

            $locked->forceFill([
                'status' => 'processed',
                'confirmed_by' => $actor->id,
                'processed_by' => $actor->id,
                'processed_at' => now(),
                'payslip_status' => 'pending',
            ])->save();
        });

        $this->event($run, $actor, 'payroll_import_confirmed', 'Imported amounts were stored without recalculation.');
        $this->generate($run->refresh(), false);

        return $run->refresh();
    }

    public function generate(PayrollRun $run, bool $retry): int
    {
        $this->assertReady();

        if ($run->source !== 'import' || $run->status !== 'processed') {
            throw new PayrollRejected('Payslips are generated after a payroll import is confirmed.');
        }

        $written = 0;
        $failed = 0;
        $items = PayrollItem::query()->where('payroll_run_id', $run->id)->with('employee', 'run')->get();

        foreach ($items as $item) {
            $exists = is_string($item->payslip_path) && $item->payslip_path !== '' && Storage::disk('local')->exists($item->payslip_path);

            if ($retry && $exists && $item->payslip_error === null) {
                continue;
            }

            if (! $retry && $exists && $item->payslip_error === null) {
                continue;
            }

            try {
                $slip = $this->directory->payslip($item);
                $slip['generated'] = now()->toDateString();
                $path = 'payroll-payslips/'.(int) $run->tenant_id.'/'.$run->id.'/'.$item->id.'.pdf';
                Storage::disk('local')->put($path, $this->pdf->render($slip));
                $item->forceFill(['payslip_path' => $path, 'payslip_error' => null])->save();
                $written++;
                $userId = (int) ($item->employee->user_id ?? 0);

                if ($userId > 0) {
                    $period = $run->year.'-'.str_pad((string) $run->month, 2, '0', STR_PAD_LEFT);
                    $this->notifier->once($userId, 'payslip-'.$item->id, 'Payslip available', 'Your payslip for '.$period.' is ready to download.', '/payslips/'.$item->id);
                }
            } catch (\Throwable $exception) {
                $item->forceFill(['payslip_error' => 'The payslip PDF could not be written.'])->save();
                $failed++;
                report($exception);
            }
        }

        $run->forceFill(['payslip_status' => $failed === 0 ? 'complete' : 'partial'])->save();

        return $written;
    }

    /**
     * @param  list<string>  $headers
     * @param  array<string, string|null>  $requested
     * @return array<string, string>
     */
    private function resolveMapping(array $headers, array $requested): array
    {
        $available = [];

        foreach ($headers as $header) {
            if ($header !== '') {
                $available[$this->normalize($header)] = $header;
            }
        }

        $mapping = [];

        foreach (self::FIELDS as $field => $aliases) {
            $choice = trim((string) ($requested[$field] ?? ''));

            if ($choice !== '' && in_array($choice, $headers, true)) {
                $mapping[$field] = $choice;

                continue;
            }

            foreach ($aliases as $alias) {
                if (isset($available[$alias])) {
                    $mapping[$field] = $available[$alias];
                    break;
                }
            }
        }

        return $mapping;
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     * @param  array<string, string>  $mapping
     * @return array<string, mixed>
     */
    private function preview(User $actor, int $year, int $month, array $headers, array $rows, array $mapping): array
    {
        $fileError = isset($mapping['employee_id']) ? null : 'Map the Employee ID column. Employees are never matched by name.';
        $parsed = [];
        $seen = [];

        foreach ($rows as $offset => $cells) {
            $line = $offset + 2;
            $values = $this->mappedValues($headers, $cells, $mapping);
            $errors = [];
            $warnings = [];
            $code = trim((string) ($values['employee_id'] ?? ''));
            $employee = null;

            if ($fileError !== null) {
                $errors[] = $fileError;
            } elseif ($code === '') {
                $errors[] = 'Employee ID is required.';
            } else {
                $seen[$code] = ($seen[$code] ?? 0) + 1;
                $employee = Employee::query()->where('tenant_id', $actor->companyId())->where('employee_code', $code)->first();

                if ($employee === null) {
                    $errors[] = 'Employee ID was not found in this company.';
                }
            }

            if ($employee !== null && isset($values['employee_name']) && $values['employee_name'] !== '') {
                $actual = $this->normalize(trim($employee->first_name.' '.$employee->last_name));

                if ($actual !== $this->normalize($values['employee_name'])) {
                    $warnings[] = 'Employee name does not match the employee record. The employee record name is used on the payslip.';
                }
            }

            $supplied = $this->amounts($values, $errors);

            if (isset($mapping['payroll_month'])) {
                $parsedMonth = $this->monthNumber((string) ($values['payroll_month'] ?? ''));

                if ($parsedMonth !== $month) {
                    $errors[] = 'Payroll month does not match the selected month.';
                }
            }

            if (isset($mapping['payroll_year']) && trim((string) ($values['payroll_year'] ?? '')) !== (string) $year) {
                $errors[] = 'Payroll year does not match the selected year.';
            }

            $this->consistent($mapping, $supplied, $errors);

            if (! isset($supplied['net'])) {
                $errors[] = 'Net salary is required. Meetara does not calculate it.';
            }

            $parsed[] = [
                'line' => $line,
                'employeeCode' => $code,
                'employeeId' => $employee?->id,
                'name' => $employee ? trim($employee->first_name.' '.$employee->last_name) : (string) ($values['employee_name'] ?? ''),
                'errors' => $errors,
                'warnings' => $warnings,
                'supplied' => $supplied,
            ];
        }

        foreach ($parsed as $index => $row) {
            $code = $row['employeeCode'];

            if ($code !== '' && ($seen[$code] ?? 0) > 1) {
                $parsed[$index]['errors'][] = 'Duplicate Employee ID in this file.';
            }
        }

        return [
            'fileError' => $fileError,
            'headers' => $headers,
            'mapping' => $mapping,
            'rows' => $parsed,
        ];
    }

    /**
     * @param  list<string>  $headers
     * @param  list<string>  $cells
     * @param  array<string, string>  $mapping
     * @return array<string, string>
     */
    private function mappedValues(array $headers, array $cells, array $mapping): array
    {
        $byHeader = [];

        foreach ($headers as $index => $header) {
            $byHeader[$header] = $cells[$index] ?? '';
        }

        $values = [];

        foreach ($mapping as $field => $header) {
            $values[$field] = trim((string) ($byHeader[$header] ?? ''));
        }

        return $values;
    }

    /**
     * @param  array<string, string>  $values
     * @param  list<string>  $errors
     * @return array<string, string>
     */
    private function amounts(array $values, array &$errors): array
    {
        $money = ['basic', 'hra', 'special', 'other_earnings', 'gross', 'pf', 'esi', 'professional_tax', 'tds', 'other_deductions', 'deductions', 'net'];
        $days = ['paid_days', 'working_days', 'lwp'];
        $supplied = [];

        foreach ([...$money, ...$days] as $field) {
            if (! array_key_exists($field, $values) || $values[$field] === '') {
                continue;
            }

            $parsed = $this->amount($values[$field]);

            if ($parsed === null) {
                $errors[] = $this->label($field).' must be a positive number with up to two decimal places.';

                continue;
            }

            $supplied[$field] = $parsed;
        }

        return $supplied;
    }

    /**
     * @param  array<string, string>  $mapping
     * @param  array<string, string>  $supplied
     * @param  list<string>  $errors
     */
    private function consistent(array $mapping, array $supplied, array &$errors): void
    {
        if ($this->mapped($mapping, [...self::EARNINGS, 'gross']) && $this->present($supplied, [...self::EARNINGS, 'gross'])) {
            $sum = $this->cents($supplied['basic']) + $this->cents($supplied['hra']) + $this->cents($supplied['special']) + $this->cents($supplied['other_earnings']);

            if ($sum !== $this->cents($supplied['gross'])) {
                $errors[] = 'Gross salary does not equal basic, HRA, special allowance and other earnings.';
            }
        }

        if ($this->mapped($mapping, [...self::DEDUCTION_PARTS, 'deductions']) && $this->present($supplied, [...self::DEDUCTION_PARTS, 'deductions'])) {
            $sum = 0;

            foreach (self::DEDUCTION_PARTS as $field) {
                $sum += $this->cents($supplied[$field]);
            }

            if ($sum !== $this->cents($supplied['deductions'])) {
                $errors[] = 'Total deductions do not equal PF, ESI, professional tax, TDS and other deductions.';
            }
        }

        if ($this->mapped($mapping, ['gross', 'deductions', 'net']) && $this->present($supplied, ['gross', 'deductions', 'net'])) {
            if ($this->cents($supplied['gross']) - $this->cents($supplied['deductions']) !== $this->cents($supplied['net'])) {
                $errors[] = 'Net salary does not equal gross salary minus total deductions.';
            }
        }
    }

    /**
     * @param  array<string, string>  $mapping
     * @param  list<string>  $fields
     */
    private function mapped(array $mapping, array $fields): bool
    {
        foreach ($fields as $field) {
            if (! isset($mapping[$field])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, string>  $supplied
     * @param  list<string>  $fields
     */
    private function present(array $supplied, array $fields): bool
    {
        foreach ($fields as $field) {
            if (! isset($supplied[$field])) {
                return false;
            }
        }

        return true;
    }

    private function cents(string $amount): int
    {
        return Money::cents($amount);
    }

    private function amount(string $value): ?string
    {
        $text = str_replace([',', '₹', ' '], '', trim($value));
        $text = preg_replace('/^(INR|Rs\.?)/i', '', $text) ?? $text;

        if (! preg_match('/^\d+(\.\d{1,2})?$/', $text)) {
            return null;
        }

        return Money::format(Money::cents($text));
    }

    private function monthNumber(string $value): ?int
    {
        $value = trim($value);

        if (preg_match('/^\d{1,2}$/', $value)) {
            $month = (int) $value;

            return $month >= 1 && $month <= 12 ? $month : null;
        }

        $names = [1 => 'january', 2 => 'february', 3 => 'march', 4 => 'april', 5 => 'may', 6 => 'june', 7 => 'july', 8 => 'august', 9 => 'september', 10 => 'october', 11 => 'november', 12 => 'december'];
        $key = strtolower($value);

        foreach ($names as $month => $name) {
            if ($key === $name || $key === substr($name, 0, 3)) {
                return $month;
            }
        }

        return null;
    }

    private function draft(User $actor, int $year, int $month): PayrollRun
    {
        $existing = PayrollRun::query()->where('tenant_id', $actor->companyId())->where('year', $year)->where('month', $month)->first();

        if ($existing === null) {
            $run = new PayrollRun;
            $run->forceFill([
                'tenant_id' => $actor->companyId(),
                'year' => $year,
                'month' => $month,
                'status' => 'import_review',
                'source' => 'import',
            ])->save();

            return $run;
        }

        if ($existing->status === 'import_review' && $existing->source === 'import') {
            PayrollItem::query()->where('payroll_run_id', $existing->id)->delete();

            return $existing;
        }

        if ($existing->source === 'import' && $existing->status === 'processed') {
            throw new PayrollRejected('A confirmed payroll import already exists for that month.');
        }

        throw new PayrollRejected('A payroll record already exists for that month. It was not replaced.');
    }

    /**
     * @return array{headers: list<string>, rows: list<list<string>>}
     */
    private function storedTable(PayrollRun $run): array
    {
        if (! is_string($run->file_path) || ! str_starts_with($run->file_path, 'payroll-imports/'.(int) $run->tenant_id.'/') || ! Storage::disk('local')->exists($run->file_path)) {
            throw new PayrollRejected('The uploaded payroll file is no longer available. Upload it again.');
        }

        $extension = pathinfo($run->file_path, PATHINFO_EXTENSION);

        return $this->tables->read(Storage::disk('local')->path($run->file_path), $extension);
    }

    private function assertReview(User $actor, PayrollRun $run): void
    {
        if ((int) $run->tenant_id !== (int) $actor->companyId() || $run->source !== 'import' || $run->status !== 'import_review') {
            throw new PayrollRejected('This payroll import is not open for review.');
        }
    }

    private function assertReady(): void
    {
        if (! $this->ready()) {
            throw new PayrollRejected('Payroll import storage is not available yet.');
        }
    }

    private function extension(UploadedFile $file): string
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());

        return in_array($extension, ['csv', 'txt', 'xlsx'], true) ? $extension : 'csv';
    }

    private function deletePrivate(?string $path, int $companyId): void
    {
        if (is_string($path) && str_starts_with($path, 'payroll-imports/'.$companyId.'/')) {
            Storage::disk('local')->delete($path);
        }
    }

    private function event(PayrollRun $run, User $actor, string $action, string $note): void
    {
        if (! Schema::hasTable('payroll_events')) {
            return;
        }

        DB::table('payroll_events')->insert([
            'tenant_id' => $actor->companyId(),
            'payroll_run_id' => $run->id,
            'user_id' => $actor->id,
            'action' => $action,
            'note' => $note,
            'created_at' => now(),
        ]);
    }

    private function label(string $field): string
    {
        return ucfirst(str_replace('_', ' ', $field));
    }

    private function normalize(string $value): string
    {
        $value = strtolower(trim($value));

        return preg_replace('/\s+/', ' ', $value) ?? $value;
    }
}
