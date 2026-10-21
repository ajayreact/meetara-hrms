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
                'gross' => (string) $item->gross,
                'deductions' => (string) $item->deductions,
                'net' => (string) $item->net,
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
        if ($user->applicationRole() !== 'accounts' || ! Schema::hasTable('payroll_payments')) {
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
    public function payslips(User $user): array
    {
        if ($user->companyId() === null || $user->applicationRole() === 'team_lead' || ! Schema::hasTable('payroll_items')) {
            return [];
        }

        $query = PayrollItem::query()
            ->where('tenant_id', $user->companyId())
            ->whereHas('run', fn ($inner) => $inner->where('status', 'processed'))
            ->with('run', 'employee');

        if (! in_array($user->applicationRole(), ['company_admin', 'accounts'], true)) {
            $employeeId = Employee::query()->where('user_id', $user->id)->where('tenant_id', $user->companyId())->value('id');

            if ($employeeId === null) {
                return [];
            }

            $query->where('employee_id', $employeeId);
        }

        return $query->latest('id')->limit(36)->get()->map(fn (PayrollItem $item) => $this->payslip($item))->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function payslip(PayrollItem $item): array
    {
        $item->loadMissing('run', 'employee.designation', 'employee.department', 'payment');
        $company = Company::query()->find($item->tenant_id);

        return [
            'id' => $item->id,
            'company' => $company?->name,
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
                default => ucfirst((string) $run->status),
            },
            'rawStatus' => $run->status,
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
            'gross' => '0.00',
            'net' => '0.00',
            'employees' => 0,
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

        $items = PayrollItem::query()->where('payroll_run_id', $run->id)->where('tenant_id', $companyId)->get(['gross', 'net']);

        return [
            'year' => $year,
            'month' => $month,
            'found' => true,
            'status' => $this->summary($run)['status'],
            'gross' => $this->moneySum($items->pluck('gross')),
            'net' => $this->moneySum($items->pluck('net')),
            'employees' => $items->count(),
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
     * @return array<int, array{employees: int, gross: string, net: string}>
     */
    private function totals(array $runIds): array
    {
        if ($runIds === [] || ! Schema::hasTable('payroll_items')) {
            return [];
        }

        $grouped = [];

        foreach (PayrollItem::query()->whereIn('payroll_run_id', $runIds)->get(['payroll_run_id', 'gross', 'net']) as $item) {
            $runId = (int) $item->payroll_run_id;
            $grouped[$runId]['rows'][] = $item;
        }

        $totals = [];

        foreach ($grouped as $runId => $bucket) {
            $rows = collect($bucket['rows']);
            $totals[$runId] = [
                'employees' => $rows->count(),
                'gross' => $this->moneySum($rows->pluck('gross')),
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

    private function canView(User $user): bool
    {
        return in_array($user->applicationRole(), ['company_admin', 'accounts'], true) && $user->companyId() !== null;
    }
}
