<?php

namespace App\Services\Hrms;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\PayrollEvent;
use App\Models\PayrollItem;
use App\Models\PayrollPayment;
use App\Models\PayrollRun;
use App\Models\SalaryStructure;
use App\Models\User;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class PayrollService
{
    public function __construct(
        private readonly LeaveCalendar $calendar,
        private readonly PayrollStatutoryReadiness $statutory,
    ) {}

    public function open(User $actor, int $year, int $month): PayrollRun
    {
        $companyId = (int) $actor->companyId();

        try {
            $run = new PayrollRun;
            $run->forceFill([
                'tenant_id' => $companyId,
                'year' => $year,
                'month' => $month,
                'status' => 'draft',
                'warnings' => [],
            ])->save();
        } catch (QueryException $exception) {
            if (($exception->errorInfo[1] ?? null) === 1062 || str_contains($exception->getMessage(), 'UNIQUE')) {
                throw new PayrollRejected('A payroll run already exists for that month.');
            }

            throw $exception;
        }

        $this->event($run, $actor, 'opened', null);

        return $run;
    }

    public function calculate(User $actor, PayrollRun $run): void
    {
        DB::transaction(function () use ($actor, $run): void {
            $locked = $this->lock($run, (int) $actor->companyId());

            if (! in_array($locked->status, ['draft', 'calculated'], true)) {
                throw new PayrollRejected('This payroll run can no longer be calculated.');
            }

            $period = $this->period($locked);
            $warnings = [
                ...$this->statutory->draftWarnings(),
                ['level' => 'warning', 'message' => 'No attendance or unpaid-leave deduction rule is configured. Missing attendance was not treated as unpaid.'],
            ];
            $pendingLeave = LeaveRequest::query()
                ->where('tenant_id', $locked->tenant_id)
                ->where('status', 'pending')
                ->where('start_date', '<=', $period['end']->toDateString())
                ->where('end_date', '>=', $period['start']->toDateString())
                ->count();

            if ($pendingLeave > 0) {
                $warnings[] = ['level' => 'warning', 'message' => $pendingLeave.' pending leave request(s) overlap this period and were not deducted.'];
            }

            PayrollPayment::query()->whereIn('payroll_item_id', $locked->items()->pluck('id'))->delete();
            $locked->items()->delete();

            $included = 0;

            foreach (Employee::query()->inCompany((int) $locked->tenant_id)->orderBy('id')->get() as $employee) {
                if ($employee->status !== 'active') {
                    continue;
                }

                if ($employee->date_of_joining === null || $employee->date_of_joining->gt($period['start'])) {
                    $warnings[] = ['level' => 'warning', 'message' => $employee->employee_code.' joined during or after this period and was excluded. Proration is not configured.'];

                    continue;
                }

                $structure = SalaryStructure::query()
                    ->where('tenant_id', $locked->tenant_id)
                    ->where('employee_id', $employee->id)
                    ->whereDate('effective_from', '<=', $period['end']->toDateString())
                    ->orderByDesc('effective_from')
                    ->orderByDesc('id')
                    ->first();

                if ($structure === null) {
                    $warnings[] = ['level' => 'critical', 'message' => $employee->employee_code.' has no salary record effective for this period.'];

                    continue;
                }

                $this->storeItem($locked, $employee, $structure, $period);
                $included++;
            }

            if ($included === 0) {
                $warnings[] = ['level' => 'critical', 'message' => 'No employees could be calculated for this period.'];
            }

            $locked->forceFill([
                'status' => 'calculated',
                'calculated_by' => $actor->id,
                'calculated_at' => now(),
                'reviewed_by' => null,
                'reviewed_at' => null,
                'review_note' => null,
                'approved_by' => null,
                'approved_at' => null,
                'warnings' => $warnings,
            ])->save();
            $this->event($locked, $actor, 'calculated', null);
        });
    }

    public function review(User $actor, PayrollRun $run, string $note): void
    {
        DB::transaction(function () use ($actor, $run, $note): void {
            $locked = $this->lock($run, (int) $actor->companyId());
            $this->assertCalculated($locked);
            $locked->forceFill([
                'status' => 'under_review',
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'review_note' => $note,
            ])->save();
            $this->event($locked, $actor, 'reviewed', $note);
            DB::afterCommit(function () use ($locked, $actor): void {
                $notifier = app(CompanyNotifier::class);
                $notifier->onceEach(
                    array_diff($notifier->roleUserIds((int) $locked->tenant_id, 'Owner'), [$actor->id]),
                    'payroll-'.$locked->id.'-awaiting-approval',
                    'Payroll awaiting approval',
                    'A payroll run is waiting for approval. Amounts are not included in this notice.',
                    '/payroll/runs/'.$locked->id
                );
            });
        });
    }

    public function approve(User $actor, PayrollRun $run, ?string $note): void
    {
        DB::transaction(function () use ($actor, $run, $note): void {
            $locked = $this->lock($run, (int) $actor->companyId());

            if ($locked->status !== 'under_review') {
                throw new PayrollRejected('Review this payroll run before approving it.');
            }

            if ((int) $locked->calculated_by === (int) $actor->id) {
                throw new PayrollRejected('The person who calculated this run cannot approve it.');
            }

            $this->assertNoCritical($locked);
            $this->statutory->assertReady();
            $locked->forceFill([
                'status' => 'approved',
                'approved_by' => $actor->id,
                'approved_at' => now(),
            ])->save();
            $this->event($locked, $actor, 'approved', $note);
            DB::afterCommit(function () use ($locked, $actor): void {
                $notifier = app(CompanyNotifier::class);
                $notifier->onceEach(
                    array_diff(array_values(array_unique([
                        ...$notifier->roleUserIds((int) $locked->tenant_id, 'Owner'),
                        ...$notifier->roleUserIds((int) $locked->tenant_id, 'Accounts'),
                    ])), [$actor->id]),
                    'payroll-'.$locked->id.'-ready',
                    'Payroll ready to process',
                    'A payroll run is ready to process. Amounts are not included in this notice.',
                    '/payroll/runs/'.$locked->id
                );
            });
        });
    }

    public function process(User $actor, PayrollRun $run): void
    {
        DB::transaction(function () use ($actor, $run): void {
            $locked = $this->lock($run, (int) $actor->companyId());

            if ($locked->status === 'processed') {
                return;
            }

            if ($locked->status !== 'approved') {
                throw new PayrollRejected('Approve this payroll run before processing it.');
            }

            $this->statutory->assertReady();

            foreach ($locked->items()->get() as $item) {
                $payment = PayrollPayment::query()->where('payroll_item_id', $item->id)->lockForUpdate()->first();

                if ($payment !== null) {
                    continue;
                }

                $payment = new PayrollPayment;
                $payment->forceFill([
                    'tenant_id' => $locked->tenant_id,
                    'payroll_item_id' => $item->id,
                    'amount' => Money::format(Money::cents((string) $item->net)),
                    'status' => 'pending',
                    'recorded_by' => $actor->id,
                ])->save();
            }

            $locked->items()->update(['payroll_status' => 'processed']);
            $locked->forceFill([
                'status' => 'processed',
                'processed_by' => $actor->id,
                'processed_at' => now(),
            ])->save();
            $this->event($locked, $actor, 'processed', null);
            $period = $locked->year.'-'.str_pad((string) $locked->month, 2, '0', STR_PAD_LEFT);
            $notices = $locked->items()->with('employee')->get()->map(fn (PayrollItem $item): array => [
                'userId' => (int) ($item->employee->user_id ?? 0),
                'itemId' => (int) $item->id,
            ])->all();
            DB::afterCommit(function () use ($notices, $period): void {
                $notifier = app(CompanyNotifier::class);

                foreach ($notices as $notice) {
                    if ($notice['userId'] < 1) {
                        continue;
                    }

                    $notifier->once(
                        $notice['userId'],
                        'payslip-'.$notice['itemId'],
                        'Payslip available',
                        'Your payslip for '.$period.' is ready to download.',
                        '/payslips/'.$notice['itemId']
                    );
                }
            });
        });
    }

    public function correct(User $actor, PayrollRun $run, string $note): void
    {
        DB::transaction(function () use ($actor, $run, $note): void {
            $locked = $this->lock($run, (int) $actor->companyId());

            if ($locked->status !== 'processed') {
                throw new PayrollRejected('Only processed payroll can be corrected.');
            }

            $paid = PayrollPayment::query()
                ->whereIn('payroll_item_id', $locked->items()->pluck('id'))
                ->where('status', 'paid')
                ->exists();

            if ($paid) {
                throw new PayrollRejected('Reverse paid payments before correcting this payroll run.');
            }

            $this->event($locked, $actor, 'correction_opened', $note);
            PayrollPayment::query()->whereIn('payroll_item_id', $locked->items()->pluck('id'))->delete();
            $locked->items()->delete();
            $locked->forceFill([
                'status' => 'draft',
                'processed_by' => null,
                'processed_at' => null,
                'approved_by' => null,
                'approved_at' => null,
                'reviewed_by' => null,
                'reviewed_at' => null,
            ])->save();
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function markPaid(User $actor, PayrollPayment $payment, array $input): void
    {
        DB::transaction(function () use ($actor, $payment, $input): void {
            $locked = PayrollPayment::query()->whereKey($payment->id)->where('tenant_id', $actor->companyId())->lockForUpdate()->first();

            if ($locked === null || $locked->status === 'paid') {
                throw new PayrollRejected('This payment has already been recorded.');
            }

            if (! in_array($locked->status, ['pending', 'reversed'], true)) {
                throw new PayrollRejected('This payment cannot be recorded.');
            }

            $updated = PayrollPayment::query()
                ->whereKey($locked->id)
                ->where('tenant_id', $actor->companyId())
                ->where('status', $locked->status)
                ->update([
                    'status' => 'paid',
                    'paid_on' => $input['paid_on'],
                    'method' => $input['method'],
                    'reference' => $input['reference'],
                    'note' => $input['note'] ?? null,
                    'recorded_by' => $actor->id,
                    'amount' => Money::format(Money::cents((string) $locked->amount)),
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                throw new PayrollRejected('This payment has already been recorded.');
            }

            $item = PayrollItem::query()->find($locked->payroll_item_id);
            $run = $item === null ? null : PayrollRun::query()->find($item->payroll_run_id);

            if ($run !== null) {
                $this->event($run, $actor, 'payment_recorded', (string) $input['reference']);
            }
        });
    }

    public function reversePayment(User $actor, PayrollPayment $payment, string $note): void
    {
        DB::transaction(function () use ($actor, $payment, $note): void {
            $locked = PayrollPayment::query()->whereKey($payment->id)->where('tenant_id', $actor->companyId())->lockForUpdate()->first();

            if ($locked === null || $locked->status !== 'paid') {
                throw new PayrollRejected('Only a recorded payment can be reversed.');
            }

            $updated = PayrollPayment::query()
                ->whereKey($locked->id)
                ->where('tenant_id', $actor->companyId())
                ->where('status', 'paid')
                ->update([
                    'status' => 'reversed',
                    'note' => $note,
                    'recorded_by' => $actor->id,
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                throw new PayrollRejected('Only a recorded payment can be reversed.');
            }

            $item = PayrollItem::query()->find($locked->payroll_item_id);
            $run = $item === null ? null : PayrollRun::query()->find($item->payroll_run_id);

            if ($run !== null) {
                $this->event($run, $actor, 'payment_reversed', $note);
            }
        });
    }

    /**
     * @param  array{start: Carbon, end: Carbon}  $period
     */
    private function storeItem(PayrollRun $run, Employee $employee, SalaryStructure $structure, array $period): void
    {
        $basic = Money::format(Money::cents((string) $structure->basic));
        $hra = Money::format(Money::cents((string) $structure->hra));
        $special = Money::format(Money::cents((string) $structure->special_allowance));
        $gross = Money::add(Money::add($basic, $hra), $special);
        $zero = Money::format(0);
        $start = $period['start']->toDateString();
        $end = $period['end']->toDateString();
        $working = number_format($this->calendar->workingDays($employee, $period['start'], $period['end']), 1, '.', '');
        $logs = AttendanceLog::query()
            ->where('tenant_id', $run->tenant_id)
            ->where('employee_id', $employee->id)
            ->whereBetween('work_date', [$start, $end])
            ->get(['work_mode']);
        $leaveDays = 0.0;

        foreach (LeaveRequest::query()->where('tenant_id', $run->tenant_id)->where('employee_id', $employee->id)->where('status', 'approved')->where('start_date', '<=', $end)->where('end_date', '>=', $start)->get() as $leave) {
            $from = Carbon::parse(max($leave->start_date?->toDateString() ?? $start, $start));
            $to = Carbon::parse(min($leave->end_date?->toDateString() ?? $end, $end));
            $leaveDays += $this->calendar->workingDays($employee, $from, $to);
        }

        $item = new PayrollItem;
        $item->forceFill([
            'tenant_id' => $run->tenant_id,
            'payroll_run_id' => $run->id,
            'employee_id' => $employee->id,
            'currency' => $structure->currency,
            'basic' => $basic,
            'hra' => $hra,
            'special_allowance' => $special,
            'working_days' => $working,
            'present_days' => number_format($logs->count(), 1, '.', ''),
            'leave_days' => number_format($leaveDays, 1, '.', ''),
            'wfh_days' => number_format($logs->where('work_mode', 'wfh')->count(), 1, '.', ''),
            'lop_days' => '0.0',
            'overtime_amount' => $zero,
            'reimbursement' => $zero,
            'perk_amount' => $zero,
            'gratuity_amount' => $zero,
            'lop_amount' => $zero,
            'pf_employee' => $zero,
            'pf_employer' => $zero,
            'esi_employee' => $zero,
            'esi_employer' => $zero,
            'lwf' => $zero,
            'tds' => $zero,
            'loan_recovery' => $zero,
            'gross' => $gross,
            'deductions' => $zero,
            'net' => $gross,
            'payroll_status' => 'calculated',
        ])->save();
    }

    private function assertCalculated(PayrollRun $run): void
    {
        if ($run->status !== 'calculated') {
            throw new PayrollRejected('Calculate this payroll run before sending it for review.');
        }

        $this->assertNoCritical($run);
    }

    private function assertNoCritical(PayrollRun $run): void
    {
        foreach ($run->warnings ?? [] as $warning) {
            if (($warning['level'] ?? null) === 'critical') {
                throw new PayrollRejected('Resolve the payroll warnings before continuing.');
            }
        }
    }

    /**
     * @return array{start: Carbon, end: Carbon}
     */
    private function period(PayrollRun $run): array
    {
        $start = Carbon::create((int) $run->year, (int) $run->month, 1)->startOfDay();

        return ['start' => $start, 'end' => $start->copy()->endOfMonth()->startOfDay()];
    }

    private function lock(PayrollRun $run, int $companyId): PayrollRun
    {
        $locked = PayrollRun::query()->whereKey($run->id)->where('tenant_id', $companyId)->lockForUpdate()->first();

        if ($locked === null) {
            throw new PayrollRejected('This payroll run cannot be changed.');
        }

        return $locked;
    }

    private function event(PayrollRun $run, User $actor, string $action, ?string $note): void
    {
        $event = new PayrollEvent;
        $event->forceFill([
            'tenant_id' => $run->tenant_id,
            'payroll_run_id' => $run->id,
            'user_id' => $actor->id,
            'action' => $action,
            'note' => $note,
            'created_at' => now(),
        ])->save();
    }
}
