<?php

namespace App\Services\Hrms;

use App\Models\Company;
use App\Models\Employee;
use App\Models\PayrollEvent;
use App\Models\PayrollItem;
use App\Models\PayrollPayment;
use App\Models\PayrollRun;
use App\Models\SalaryStructure;
use App\Models\User;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PayrollDirectory
{
    public function __construct(private readonly PayrollStatutoryReadiness $statutory) {}

    /**
     * @return array<string, mixed>
     */
    public function overview(User $user, ?int $year = null, ?int $month = null): array
    {
        $empty = [
            'currencyNote' => 'Currency is recorded on each salary structure.',
            'runs' => 0,
            'draft' => 0,
            'calculated' => 0,
            'underReview' => 0,
            'review' => 0,
            'approved' => 0,
            'processed' => 0,
            'outstanding' => 0,
            'processedGross' => '0.00',
            'processedNet' => '0.00',
            'employees' => 0,
            'activeEmployees' => 0,
            'salaryConfigured' => 0,
            'payslips' => 0,
            'latest' => null,
            'eligibility' => null,
            'period' => $this->emptyPeriod($year, $month),
            'activity' => [],
        ];

        if (! $this->canView($user) || ! Schema::hasTable('payroll_runs')) {
            return $empty;
        }

        $companyId = (int) $user->companyId();
        $runs = PayrollRun::query()->inCompany($companyId);
        $processedIds = (clone $runs)->where('status', 'processed')->pluck('id');
        $processedGross = 0;
        $net = 0;

        if ($processedIds->isNotEmpty() && Schema::hasTable('payroll_items')) {
            $processedItems = PayrollItem::query()->whereIn('payroll_run_id', $processedIds)->get(['gross', 'net']);

            foreach ($processedItems as $item) {
                $processedGross += Money::cents((string) $item->gross);
                $net += Money::cents((string) $item->net);
            }
        }

        $calculated = (clone $runs)->where('status', 'calculated')->count();
        $underReview = (clone $runs)->where('status', 'under_review')->count();

        return [
            'currencyNote' => 'Amounts stay in the currency stored on each salary record. No currency was assumed.',
            'runs' => (clone $runs)->count(),
            'draft' => (clone $runs)->where('status', 'draft')->count(),
            'calculated' => $calculated,
            'underReview' => $underReview,
            'review' => $calculated + $underReview,
            'approved' => (clone $runs)->where('status', 'approved')->count(),
            'processed' => $processedIds->count(),
            'outstanding' => Schema::hasTable('payroll_payments')
                ? PayrollPayment::query()->where('tenant_id', $companyId)->where('status', 'pending')->count()
                : 0,
            'processedGross' => Money::format($processedGross),
            'processedNet' => Money::format($net),
            'employees' => $processedIds->isEmpty() ? 0 : PayrollItem::query()->whereIn('payroll_run_id', $processedIds)->count(),
            'activeEmployees' => Employee::query()->inCompany($companyId)->where('status', 'active')->count(),
            'salaryConfigured' => Schema::hasTable('salary_structures')
                ? SalaryStructure::query()->where('tenant_id', $companyId)->distinct()->count('employee_id')
                : 0,
            'payslips' => $processedIds->isEmpty() || ! Schema::hasTable('payroll_items')
                ? 0
                : PayrollItem::query()->whereIn('payroll_run_id', $processedIds)->where('tenant_id', $companyId)->count(),
            'latest' => $this->latest($companyId),
            'eligibility' => $this->eligibility($companyId, $year, $month),
            'period' => $this->period($companyId, $year, $month),
            'activity' => $this->activity($companyId),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function runs(User $user, ?string $filter, ?int $year = null, ?int $month = null): array
    {
        if (! $this->canView($user) || ! Schema::hasTable('payroll_runs')) {
            return [];
        }

        $query = PayrollRun::query()->inCompany((int) $user->companyId())->latest('year')->latest('month');

        if ($filter === 'review') {
            $query->whereIn('status', ['calculated', 'under_review']);
        } elseif (in_array($filter, ['draft', 'calculated', 'under_review', 'approved', 'processed'], true)) {
            $query->where('status', $filter);
        }

        if ($year !== null) {
            $query->where('year', $year);
        }

        if ($month !== null) {
            $query->where('month', $month);
        }

        $runs = $query->limit(24)->get();
        $totals = $this->totals($runs->pluck('id')->all());

        return $runs->map(function (PayrollRun $run) use ($totals): array {
            $row = $totals[$run->id] ?? ['employees' => 0, 'gross' => '0.00', 'net' => '0.00'];

            return [...$this->summary($run), ...$row];
        })->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function run(PayrollRun $run): array
    {
        $run->load(['items.employee.designation', 'items.employee.department', 'items.payment', 'events']);

        return [
            ...$this->summary($run),
            'warnings' => $run->warnings ?? [],
            'membership' => $this->membership($run),
            'totals' => $this->reliableTotals($run),
            'blocked' => collect($run->warnings ?? [])->contains(fn (array $warning): bool => ($warning['level'] ?? null) === 'critical') || ! $this->statutory->ready(),
            'statutory' => $this->statutory->present(),
            'deductionsConfigured' => false,
            'reviewNote' => $run->review_note,
            'items' => $run->items->map(fn (PayrollItem $item) => [
                'id' => $item->id,
                'employee' => trim(($item->employee->first_name ?? '').' '.($item->employee->last_name ?? '')),
                'code' => $item->employee->employee_code ?? '',
                'currency' => $item->currency,
                'basic' => (string) $item->basic,
                'hra' => (string) $item->hra,
                'special' => (string) $item->special_allowance,
                'pf' => (string) $item->pf_employee,
                'esi' => (string) $item->esi_employee,
                'tds' => (string) $item->tds,
                'lop' => (string) $item->lop_amount,
                'department' => $item->employee->department?->name ?? '',
                'designation' => $item->employee->designation?->name ?? '',
                'gross' => (string) $item->gross,
                'deductions' => (string) $item->deductions,
                'net' => (string) $item->net,
                'payrollStatus' => ucfirst(str_replace('_', ' ', (string) ($item->payroll_status ?? $run->status))),
                'payslipStatus' => $run->status === 'processed' ? 'Available' : 'Not generated',
                'workingDays' => (string) $item->working_days,
                'presentDays' => (string) $item->present_days,
                'leaveDays' => (string) $item->leave_days,
            ])->all(),
            'history' => $run->events->map(fn ($event) => [
                'action' => str_replace('_', ' ', (string) $event->action),
                'note' => $event->note,
                'at' => (string) $event->created_at,
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function salaries(User $user, string $search): array
    {
        if (! in_array($user->applicationRole(), ['company_admin', 'hr', 'accounts'], true) || ! Schema::hasTable('salary_structures')) {
            return ['rows' => []];
        }

        $companyId = (int) $user->companyId();
        $employees = Employee::query()->inCompany($companyId)->orderBy('first_name')->get();

        if ($search !== '') {
            $employees = $employees->filter(fn (Employee $employee) => str_contains(strtolower($employee->first_name.' '.$employee->last_name.' '.$employee->employee_code), strtolower($search)))->values();
        }

        $structures = SalaryStructure::query()->where('tenant_id', $companyId)->orderByDesc('effective_from')->orderByDesc('id')->get()->groupBy('employee_id');

        return [
            'rows' => $employees->map(function (Employee $employee) use ($structures): array {
                $history = $structures->get($employee->id, collect());

                return [
                    'id' => $employee->id,
                    'name' => trim($employee->first_name.' '.$employee->last_name),
                    'code' => $employee->employee_code,
                    'status' => ucfirst((string) $employee->status),
                    'history' => $history->map(fn (SalaryStructure $structure) => [
                        'id' => $structure->id,
                        'effectiveFrom' => $structure->effective_from?->toDateString(),
                        'currency' => $structure->currency,
                        'basic' => (string) $structure->basic,
                        'hra' => (string) $structure->hra,
                        'special' => (string) $structure->special_allowance,
                    ])->all(),
                ];
            })->all(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function payments(User $user, ?string $status = null): array
    {
        if (! in_array($user->applicationRole(), ['company_admin', 'accounts'], true) || ! Schema::hasTable('payroll_payments')) {
            return [];
        }

        $query = PayrollPayment::query()
            ->where('tenant_id', $user->companyId())
            ->with('item.employee', 'item.run')
            ->latest('id');

        if (in_array($status, ['pending', 'paid', 'reversed'], true)) {
            $query->where('status', $status);
        }

        return $query->limit(50)->get()->map(fn (PayrollPayment $payment) => [
            'id' => $payment->id,
            'employee' => trim(($payment->item->employee->first_name ?? '').' '.($payment->item->employee->last_name ?? '')),
            'period' => $payment->item->run ? $payment->item->run->year.'-'.str_pad((string) $payment->item->run->month, 2, '0', STR_PAD_LEFT) : '',
            'amount' => (string) $payment->amount,
            'currency' => $payment->item->currency,
            'status' => ucfirst((string) $payment->status),
            'method' => $payment->method,
            'reference' => $payment->reference,
            'paidOn' => $payment->paid_on?->toDateString(),
            'note' => $payment->note,
        ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function payslips(User $user, ?int $year = null, ?int $month = null, ?string $search = null): array
    {
        if ($user->companyId() === null || $user->applicationRole() === 'super_admin' || ! Schema::hasTable('payroll_items')) {
            return [];
        }

        $query = PayrollItem::query()
            ->where('tenant_id', $user->companyId())
            ->whereHas('run', function ($inner) use ($year, $month): void {
                $inner->where('status', 'processed');

                if ($year !== null) {
                    $inner->where('year', $year);
                }

                if ($month !== null) {
                    $inner->where('month', $month);
                }
            })
            ->with('run', 'employee');

        if (! in_array($user->applicationRole(), ['company_admin', 'accounts', 'hr'], true)) {
            $employeeId = Employee::query()->where('user_id', $user->id)->where('tenant_id', $user->companyId())->value('id');

            if ($employeeId === null) {
                return [];
            }

            $query->where('employee_id', $employeeId);
        }

        $needle = trim((string) $search);

        if ($needle !== '') {
            $like = '%'.addcslashes($needle, '%_\\').'%';
            $query->whereHas('employee', function ($inner) use ($like): void {
                $inner->where('employee_code', 'like', $like)
                    ->orWhere('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like);
            });
        }

        $limit = $year !== null && $month !== null ? 500 : 36;

        return $query->latest('id')->limit($limit)->get()->map(fn (PayrollItem $item) => $this->payslip($item))->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function payslip(PayrollItem $item): array
    {
        $item->loadMissing('run', 'employee.designation', 'employee.department', 'payment');
        $company = Company::query()->find($item->tenant_id);
        $legal = Schema::hasTable('company_legal_profiles')
            ? DB::table('company_legal_profiles')->where('tenant_id', $item->tenant_id)->where('country', 'india')->first()
            : null;

        return [
            'id' => $item->id,
            'company' => $company?->name,
            'legalName' => is_string($legal->legal_name ?? null) && $legal->legal_name !== '' ? $legal->legal_name : $company?->name,
            'legalAddress' => is_string($legal->legal_address ?? null) && $legal->legal_address !== '' ? $legal->legal_address : null,
            'generatedAt' => $item->run?->processed_at?->toDateString(),
            'employee' => trim(($item->employee->first_name ?? '').' '.($item->employee->last_name ?? '')),
            'code' => $item->employee->employee_code ?? '',
            'title' => $item->employee->designation?->name,
            'department' => $item->employee->department?->name,
            'joined' => $item->employee->date_of_joining?->toDateString(),
            'period' => $item->run ? $item->run->year.'-'.str_pad((string) $item->run->month, 2, '0', STR_PAD_LEFT) : '',
            'currency' => $item->currency,
            'basic' => (string) $item->basic,
            'hra' => (string) $item->hra,
            'special' => (string) $item->special_allowance,
            'gross' => (string) $item->gross,
            'deductions' => (string) $item->deductions,
            'deductionsConfigured' => false,
            'deductionNotice' => 'PF, ESI, TDS, and unpaid leave are not configured. They are not shown as calculated deductions.',
            'imported' => is_array($item->import_supplied),
            'supplied' => is_array($item->import_supplied) ? $item->import_supplied : null,
            'otherEarnings' => $item->other_earnings !== null ? (string) $item->other_earnings : null,
            'professionalTax' => $item->professional_tax !== null ? (string) $item->professional_tax : null,
            'otherDeductions' => $item->other_deductions !== null ? (string) $item->other_deductions : null,
            'pf' => (string) $item->pf_employee,
            'esi' => (string) $item->esi_employee,
            'tds' => (string) $item->tds,
            'paidDays' => (string) $item->present_days,
            'workingDays' => (string) $item->working_days,
            'lwp' => (string) $item->lop_days,
            'net' => (string) $item->net,
            'paidOn' => $item->payment?->paid_on?->toDateString(),
            'paymentStatus' => $item->payment ? ucfirst((string) $item->payment->status) : 'Pending',
            'paymentMethod' => $item->payment?->method,
            'reference' => 'PS-'.$item->payroll_run_id.'-'.$item->id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(PayrollRun $run): array
    {
        return [
            'id' => $run->id,
            'period' => $run->year.'-'.str_pad((string) $run->month, 2, '0', STR_PAD_LEFT),
            'status' => match ($run->status) {
                'under_review' => 'Under Review',
                'import_review' => 'Needs review',
                default => ucfirst((string) $run->status),
            },
            'rawStatus' => $run->status,
            'source' => Schema::hasColumn('payroll_runs', 'source') ? ($run->source ?: 'calculated') : 'calculated',
            'legacy' => ! Schema::hasColumn('payroll_runs', 'source') || ($run->source ?: 'calculated') !== 'import',
        ];
    }

    /**
     * @return array{year: int|null, month: int|null, found: bool, status: string|null, gross: string, net: string, employees: int}
     */
    private function emptyPeriod(?int $year, ?int $month): array
    {
        return [
            'year' => $year,
            'month' => $month,
            'found' => false,
            'status' => null,
            'rawStatus' => null,
            'runId' => null,
            'gross' => '0.00',
            'deductions' => '0.00',
            'net' => '0.00',
            'employees' => 0,
            'source' => null,
            'imported' => 0,
            'validated' => 0,
            'errors' => 0,
            'confirmed' => 0,
            'generated' => 0,
        ];
    }

    /**
     * @return array{year: int|null, month: int|null, found: bool, status: string|null, gross: string, net: string, employees: int}
     */
    private function period(int $companyId, ?int $year, ?int $month): array
    {
        $empty = $this->emptyPeriod($year, $month);

        if ($year === null || $month === null || ! Schema::hasTable('payroll_items')) {
            return $empty;
        }

        $run = PayrollRun::query()->inCompany($companyId)->where('year', $year)->where('month', $month)->first();

        if ($run === null) {
            return $empty;
        }

        $columns = ['gross', 'deductions', 'net'];

        if (Schema::hasColumn('payroll_items', 'payslip_path')) {
            $columns[] = 'payslip_path';
            $columns[] = 'payslip_error';
        }

        $items = PayrollItem::query()->where('payroll_run_id', $run->id)->where('tenant_id', $companyId)->get($columns);
        $summary = $this->summary($run);
        $counts = $this->importCounts($run, $items);

        return [
            'year' => $year,
            'month' => $month,
            'found' => true,
            'status' => $summary['status'],
            'rawStatus' => $run->status,
            'runId' => $run->id,
            'gross' => $summary['legacy'] ? $this->moneySum($items->pluck('gross')) : $counts['gross'],
            'deductions' => $summary['legacy'] ? $this->moneySum($items->pluck('deductions')) : $counts['deductions'],
            'net' => $summary['legacy'] ? $this->moneySum($items->pluck('net')) : $counts['net'],
            'employees' => $items->count(),
            'source' => $summary['source'],
            'imported' => $counts['imported'],
            'validated' => $counts['validated'],
            'errors' => $counts['errors'],
            'confirmed' => $counts['confirmed'],
            'generated' => $counts['generated'],
        ];
    }

    /**
     * @return list<array{action: string, period: string, at: string}>
     */
    private function activity(int $companyId): array
    {
        if (! Schema::hasTable('payroll_events')) {
            return [];
        }

        return PayrollEvent::query()
            ->where('tenant_id', $companyId)
            ->latest('id')
            ->limit(8)
            ->get()
            ->map(function (PayrollEvent $event) use ($companyId): array {
                $run = PayrollRun::query()->inCompany($companyId)->whereKey($event->payroll_run_id)->first();

                return [
                    'action' => str_replace('_', ' ', (string) $event->action),
                    'period' => $run ? $run->year.'-'.str_pad((string) $run->month, 2, '0', STR_PAD_LEFT) : '',
                    'at' => (string) $event->created_at,
                ];
            })->all();
    }

    /**
     * @param  list<int>  $runIds
     * @return array<int, array{employees: int, gross: string, deductions: string, net: string}>
     */
    private function totals(array $runIds): array
    {
        if ($runIds === [] || ! Schema::hasTable('payroll_items')) {
            return [];
        }

        $grouped = [];

        foreach (PayrollItem::query()->whereIn('payroll_run_id', $runIds)->get(['payroll_run_id', 'gross', 'deductions', 'net']) as $item) {
            $runId = (int) $item->payroll_run_id;
            $grouped[$runId]['rows'][] = $item;
        }

        $totals = [];

        foreach ($grouped as $runId => $bucket) {
            $rows = collect($bucket['rows']);
            $totals[$runId] = [
                'employees' => $rows->count(),
                'gross' => $this->moneySum($rows->pluck('gross')),
                'deductions' => $this->moneySum($rows->pluck('deductions')),
                'net' => $this->moneySum($rows->pluck('net')),
            ];
        }

        return $totals;
    }

    /**
     * @param  iterable<mixed>  $amounts
     */
    private function moneySum(iterable $amounts): string
    {
        $cents = 0;

        foreach ($amounts as $amount) {
            $cents += Money::cents((string) $amount);
        }

        return Money::format($cents);
    }

    /**
     * @return array{id: int, period: string, status: string, rawStatus: string}|null
     */
    private function latest(int $companyId): ?array
    {
        $run = PayrollRun::query()->inCompany($companyId)->latest('year')->latest('month')->latest('id')->first();

        return $run === null ? null : $this->summary($run);
    }

    /**
     * @return array{included: int, excluded: int}|null
     */
    private function eligibility(int $companyId, ?int $year, ?int $month): ?array
    {
        if ($year === null || $month === null) {
            return null;
        }

        $groups = $this->classify($companyId, $this->monthBounds($year, $month));

        return [
            'included' => count($groups['included']),
            'excluded' => count($groups['excluded']),
        ];
    }

    /**
     * @return array{source: string, included: list<array{name: string, code: string, reason: string}>, excluded: list<array{name: string, code: string, reason: string}>}
     */
    private function membership(PayrollRun $run): array
    {
        $groups = $this->classify((int) $run->tenant_id, $this->monthBounds((int) $run->year, (int) $run->month));
        $saved = $run->items->keyBy('employee_id');

        if ($saved->isEmpty() && $run->status === 'draft') {
            return [
                'source' => 'preview',
                'included' => array_map(fn (array $row): array => $this->publicMember($row), $groups['included']),
                'excluded' => array_map(fn (array $row): array => $this->publicMember($row), $groups['excluded']),
            ];
        }

        $included = [];
        $excluded = [];

        foreach ([...$groups['included'], ...$groups['excluded']] as $row) {
            $employeeId = $row['employeeId'];
            $row = $this->publicMember($row);

            if ($saved->has($employeeId)) {
                $row['reason'] = 'Included in the saved calculation.';
                $included[] = $row;

                continue;
            }

            $excluded[] = $row;
        }

        return ['source' => 'saved', 'included' => $included, 'excluded' => $excluded];
    }

    /**
     * @param  array{employeeId: int, name: string, code: string, reason: string}  $row
     * @return array{name: string, code: string, reason: string}
     */
    private function publicMember(array $row): array
    {
        return [
            'name' => $row['name'],
            'code' => $row['code'],
            'reason' => $row['reason'],
        ];
    }

    /**
     * @return array{reliable: bool, currency: string|null, employees: int, gross: string|null, net: string|null, note: string}|null
     */
    private function reliableTotals(PayrollRun $run): ?array
    {
        if ($run->items->isEmpty()) {
            return null;
        }

        $currencies = $run->items->pluck('currency')->unique()->values();

        if ($currencies->count() !== 1) {
            return [
                'reliable' => false,
                'currency' => null,
                'employees' => $run->items->count(),
                'gross' => null,
                'net' => null,
                'note' => 'Saved rows use more than one currency, so they are not combined.',
            ];
        }

        $processed = $run->status === 'processed';

        return [
            'reliable' => true,
            'currency' => (string) $currencies->first(),
            'employees' => $run->items->count(),
            'gross' => $this->moneySum($run->items->pluck('gross')),
            'net' => $processed ? $this->moneySum($run->items->pluck('net')) : null,
            'note' => $processed
                ? 'Gross and net come from the processed payroll rows.'
                : 'Gross is the saved basic, HRA, and special allowance. Net payable stays hidden because statutory deductions are not configured.',
        ];
    }

    /**
     * @param  array{start: Carbon, end: Carbon}  $period
     * @return array{included: list<array{employeeId: int, name: string, code: string, reason: string}>, excluded: list<array{employeeId: int, name: string, code: string, reason: string}>}
     */
    private function classify(int $companyId, array $period): array
    {
        $included = [];
        $excluded = [];

        foreach (Employee::query()->inCompany($companyId)->orderBy('employee_code')->get() as $employee) {
            $row = [
                'employeeId' => (int) $employee->id,
                'name' => trim($employee->first_name.' '.$employee->last_name),
                'code' => (string) $employee->employee_code,
                'reason' => 'Active employee with a salary record effective for this period.',
            ];

            if ($employee->status !== 'active') {
                $row['reason'] = 'Not an active employee.';
                $excluded[] = $row;

                continue;
            }

            if ($employee->date_of_joining === null || $employee->date_of_joining->gt($period['start'])) {
                $row['reason'] = 'Joined during or after this period. Proration is not configured.';
                $excluded[] = $row;

                continue;
            }

            $structure = SalaryStructure::query()
                ->where('tenant_id', $companyId)
                ->where('employee_id', $employee->id)
                ->whereDate('effective_from', '<=', $period['end']->toDateString())
                ->orderByDesc('effective_from')
                ->orderByDesc('id')
                ->first();

            if ($structure === null) {
                $row['reason'] = 'No salary record effective for this period.';
                $excluded[] = $row;

                continue;
            }

            $included[] = $row;
        }

        return ['included' => $included, 'excluded' => $excluded];
    }

    /**
     * @return array{start: Carbon, end: Carbon}
     */
    private function monthBounds(int $year, int $month): array
    {
        $start = Carbon::create($year, $month, 1)->startOfDay();

        return ['start' => $start, 'end' => $start->copy()->endOfMonth()->startOfDay()];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function importHistory(User $user): array
    {
        if (! $this->canView($user) || ! Schema::hasTable('payroll_runs')) {
            return [];
        }

        $runs = PayrollRun::query()->inCompany((int) $user->companyId())->latest('year')->latest('month')->limit(24)->get();
        $totals = $this->totals($runs->pluck('id')->all());
        $names = $this->userNames($runs->pluck('uploaded_by')->filter()->all());

        return $runs->map(function (PayrollRun $run) use ($totals, $names): array {
            $summary = $this->summary($run);
            $row = $totals[$run->id] ?? ['employees' => 0, 'gross' => '0.00', 'deductions' => '0.00', 'net' => '0.00'];
            $preview = $summary['legacy'] ? null : $this->importCounts($run, collect());

            return [
                'id' => $run->id,
                'period' => $summary['period'],
                'uploadedAt' => $summary['legacy'] ? null : $run->uploaded_at?->toDateString(),
                'uploadedBy' => $summary['legacy'] ? null : ($names[(int) $run->uploaded_by] ?? null),
                'employees' => $summary['legacy'] ? $row['employees'] : ($run->status === 'processed' ? $row['employees'] : $preview['imported']),
                'gross' => $summary['legacy'] || $run->status === 'processed' ? $row['gross'] : $preview['gross'],
                'deductions' => $summary['legacy'] || $run->status === 'processed' ? $row['deductions'] : $preview['deductions'],
                'net' => $summary['legacy'] || $run->status === 'processed' ? $row['net'] : $preview['net'],
                'importStatus' => $summary['legacy'] ? 'Legacy payroll' : $summary['status'],
                'payslipStatus' => $summary['legacy']
                    ? ($run->status === 'processed' ? 'Available' : 'Not generated')
                    : ($run->payslip_status === 'complete' ? 'Generated' : ($run->payslip_status === 'partial' ? 'Needs retry' : 'Not generated')),
                'legacy' => $summary['legacy'],
                'rawStatus' => $run->status,
            ];
        })->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function importReview(PayrollRun $run): array
    {
        $preview = is_array($run->import_rows) ? $run->import_rows : [];
        $rows = is_array($preview['rows'] ?? null) ? $preview['rows'] : [];
        $errors = 0;

        foreach ($rows as $row) {
            if (($row['errors'] ?? []) !== []) {
                $errors++;
            }
        }

        $uploader = $run->uploaded_by ? User::query()->find($run->uploaded_by) : null;
        $failed = Schema::hasColumn('payroll_items', 'payslip_error')
            ? PayrollItem::query()->where('payroll_run_id', $run->id)->whereNotNull('payslip_error')->with('employee')->get()
            : collect();

        return [
            ...$this->summary($run),
            'year' => (int) $run->year,
            'month' => (int) $run->month,
            'fileName' => $run->original_name,
            'uploadedAt' => $run->uploaded_at?->toDateTimeString(),
            'uploadedBy' => $uploader?->name,
            'headers' => $preview['headers'] ?? [],
            'mapping' => $preview['mapping'] ?? [],
            'fileError' => $preview['fileError'] ?? null,
            'rows' => $rows,
            'errorCount' => $errors,
            'validCount' => count($rows) - $errors,
            'canConfirm' => $run->status === 'import_review' && $errors === 0 && $rows !== [] && ($preview['fileError'] ?? null) === null,
            'payslipStatus' => $run->payslip_status,
            'fields' => array_keys(PayrollImport::FIELDS),
            'failures' => $failed->map(fn (PayrollItem $item) => [
                'id' => $item->id,
                'code' => $item->employee->employee_code ?? '',
                'error' => $item->payslip_error,
            ])->all(),
        ];
    }

    /**
     * @param  iterable<PayrollItem>  $items
     * @return array{imported: int, validated: int, errors: int, confirmed: int, generated: int, gross: string, deductions: string, net: string}
     */
    private function importCounts(PayrollRun $run, iterable $items): array
    {
        $rows = is_array($run->import_rows) ? ($run->import_rows['rows'] ?? []) : [];
        $imported = is_array($rows) ? count($rows) : 0;
        $errors = 0;
        $gross = 0;
        $deductions = 0;
        $net = 0;

        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (($row['errors'] ?? []) !== []) {
                    $errors++;

                    continue;
                }

                $supplied = is_array($row['supplied'] ?? null) ? $row['supplied'] : [];
                $gross += isset($supplied['gross']) ? Money::cents((string) $supplied['gross']) : 0;
                $deductions += isset($supplied['deductions']) ? Money::cents((string) $supplied['deductions']) : 0;
                $net += isset($supplied['net']) ? Money::cents((string) $supplied['net']) : 0;
            }
        }

        $generated = 0;

        foreach ($items as $item) {
            if (is_string($item->payslip_path ?? null) && $item->payslip_path !== '' && ($item->payslip_error ?? null) === null) {
                $generated++;
            }
        }

        $confirmed = $run->status === 'processed' && ($run->source ?? '') === 'import' ? $imported : 0;

        if ($run->status === 'processed' && ($run->source ?? '') === 'import') {
            $gross = 0;
            $deductions = 0;
            $net = 0;

            foreach ($items as $item) {
                $supplied = is_array($item->import_supplied ?? null) ? $item->import_supplied : [];
                $gross += isset($supplied['gross']) ? Money::cents((string) $supplied['gross']) : Money::cents((string) $item->gross);
                $deductions += isset($supplied['deductions']) ? Money::cents((string) $supplied['deductions']) : Money::cents((string) $item->deductions);
                $net += isset($supplied['net']) ? Money::cents((string) $supplied['net']) : Money::cents((string) $item->net);
            }
        }

        return [
            'imported' => ($run->source ?? '') === 'import' ? $imported : 0,
            'validated' => ($run->source ?? '') === 'import' ? max(0, $imported - $errors) : 0,
            'errors' => ($run->source ?? '') === 'import' ? $errors : 0,
            'confirmed' => $confirmed,
            'generated' => $generated,
            'gross' => Money::format($gross),
            'deductions' => Money::format($deductions),
            'net' => Money::format($net),
        ];
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function userNames(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return User::query()->whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    private function canView(User $user): bool
    {
        return in_array($user->applicationRole(), ['company_admin', 'accounts', 'hr'], true) && $user->companyId() !== null;
    }
}
