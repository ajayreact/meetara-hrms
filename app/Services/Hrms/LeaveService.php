<?php

namespace App\Services\Hrms;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeavePolicy;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestEvent;
use App\Models\LeaveType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class LeaveService
{
    public function __construct(private readonly LeaveCalendar $calendar) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function apply(User $actor, array $input): LeaveRequest
    {
        if (($input['half_day'] ?? false) === true) {
            throw new LeaveRejected('Half-day leave is not configured for this company.');
        }

        return DB::transaction(function () use ($actor, $input): LeaveRequest {
            $employee = $this->employee($actor);
            $type = LeaveType::query()->inCompany((int) $employee->tenant_id)->whereKey($input['leave_type_id'])->first();

            if ($type === null) {
                throw new LeaveRejected('Choose a leave type from your company.');
            }

            $policy = LeavePolicy::query()->inCompany((int) $employee->tenant_id)->where('leave_type_id', $type->id)->orderByDesc('applicable_after_days')->first();

            if ($policy === null) {
                throw new LeaveRejected('This leave type has no company policy.');
            }

            $start = Carbon::parse($input['start_date'])->startOfDay();
            $end = Carbon::parse($input['end_date'])->startOfDay();

            if ($end->lt($start)) {
                throw new LeaveRejected('The end date must be on or after the start date.');
            }

            if ($start->year !== $end->year) {
                throw new LeaveRejected('A request must stay within one leave year.');
            }

            if ($employee->date_of_joining) {
                $eligible = Carbon::parse($employee->date_of_joining)->addDays((int) $policy->applicable_after_days);

                if ($start->lt($eligible)) {
                    throw new LeaveRejected('This leave type is not available yet.');
                }
            } elseif ((int) $policy->applicable_after_days > 0) {
                throw new LeaveRejected('This leave type is not available yet.');
            }

            $days = $this->calendar->workingDays($employee, $start, $end);

            if ($days < 1) {
                throw new LeaveRejected('The selected dates do not include a working day.');
            }

            $overlap = LeaveRequest::query()
                ->where('employee_id', $employee->id)
                ->where('tenant_id', $employee->tenant_id)
                ->whereIn('status', ['pending', 'approved'])
                ->where('start_date', '<=', $end->toDateString())
                ->where('end_date', '>=', $start->toDateString())
                ->exists();

            if ($overlap) {
                throw new LeaveRejected('Those dates overlap a pending or approved request.');
            }

            $balance = $this->balance($employee, (int) $type->id, $start->year);

            if ($balance === null) {
                throw new LeaveRejected('No leave balance is recorded for this year.');
            }

            $available = $this->available($balance);

            if ($days > $available) {
                throw new LeaveRejected('The request is longer than the remaining balance.');
            }

            $request = new LeaveRequest;
            $request->forceFill([
                'tenant_id' => $employee->tenant_id,
                'employee_id' => $employee->id,
                'leave_type_id' => $type->id,
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'days' => $days,
                'reason' => $input['reason'],
                'status' => 'pending',
                'payroll_status' => 'pending',
            ])->save();

            $balance->forceFill(['pending' => $this->round((float) $balance->pending + $days)])->save();
            $this->event($request, $actor, 'submitted', (string) $input['reason']);

            return $request;
        });
    }

    public function decide(User $actor, LeaveRequest $request, string $decision, ?string $note): void
    {
        if (! in_array($decision, ['approved', 'rejected'], true)) {
            throw new LeaveRejected('That decision is not available.');
        }

        if ($decision === 'rejected' && strlen(trim((string) $note)) < 8) {
            throw new LeaveRejected('Enter a reason of at least 8 characters.');
        }

        DB::transaction(function () use ($actor, $request, $decision, $note): void {
            $locked = $this->lockRequest($request, (int) $actor->companyId());
            $this->assertCanReview($actor, $locked);

            if ($locked->status !== 'pending') {
                throw new LeaveRejected('This request has already been reviewed.');
            }

            $balance = $this->balanceForRequest($locked);

            if ($balance === null || $this->round((float) $balance->pending) + 0.001 < (float) $locked->days) {
                throw new LeaveRejected('The leave balance can no longer be updated for this request.');
            }

            $days = (float) $locked->days;
            $changes = ['pending' => $this->round((float) $balance->pending - $days)];

            if ($decision === 'approved') {
                $changes['used'] = $this->round((float) $balance->used + $days);
            }

            $balance->forceFill($changes)->save();
            $locked->forceFill([
                'status' => $decision,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'reviewer_note' => $note,
            ])->save();
            $this->event($locked, $actor, $decision, $note);
            $employeeUserId = (int) Employee::query()->whereKey($locked->employee_id)->value('user_id');
            DB::afterCommit(fn () => app(CompanyNotifier::class)->once(
                $employeeUserId,
                'leave-'.$locked->id.'-'.$decision,
                'Leave request '.$decision,
                'Your leave request was '.$decision.'.',
                '/leave'
            ));
        });
    }

    public function cancel(User $actor, LeaveRequest $request, ?string $note): void
    {
        DB::transaction(function () use ($actor, $request, $note): void {
            $locked = $this->lockRequest($request, (int) $actor->companyId());
            $employee = Employee::query()->where('user_id', $actor->id)->where('tenant_id', $actor->companyId())->first();
            $owns = $employee !== null && (int) $locked->employee_id === (int) $employee->id;
            $manager = in_array($actor->applicationRole(), ['company_admin', 'hr'], true);

            if ($locked->status === 'pending' && ($owns || $manager)) {
                $this->restore($locked, 'pending');
            } elseif ($locked->status === 'approved' && $manager) {
                $this->restore($locked, 'used');
            } else {
                throw new LeaveRejected('This request cannot be cancelled.');
            }

            $locked->forceFill([
                'status' => 'cancelled',
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'reviewer_note' => $note,
            ])->save();
            $this->event($locked, $actor, 'cancelled', $note);
            $employeeUserId = (int) Employee::query()->whereKey($locked->employee_id)->value('user_id');
            DB::afterCommit(fn () => app(CompanyNotifier::class)->once(
                $employeeUserId,
                'leave-'.$locked->id.'-cancelled',
                'Leave request cancelled',
                'Your leave request was cancelled.',
                '/leave'
            ));
        });
    }

    private function restore(LeaveRequest $request, string $column): void
    {
        $balance = $this->balanceForRequest($request);

        if ($balance === null || $this->round((float) $balance->{$column}) + 0.001 < (float) $request->days) {
            throw new LeaveRejected('The leave balance can no longer be updated for this request.');
        }

        $balance->forceFill([
            $column => $this->round((float) $balance->{$column} - (float) $request->days),
        ])->save();
    }

    private function assertCanReview(User $actor, LeaveRequest $request): void
    {
        $employee = Employee::query()->whereKey($request->employee_id)->where('tenant_id', $request->tenant_id)->first();

        if ($employee === null || (int) $employee->user_id === (int) $actor->id) {
            throw new LeaveRejected('You cannot review your own request.');
        }

        if (in_array($actor->applicationRole(), ['company_admin', 'hr'], true)) {
            return;
        }

        if ($actor->applicationRole() !== 'team_lead') {
            throw new LeaveRejected('You cannot review this request.');
        }

        $lead = Employee::query()->where('user_id', $actor->id)->where('tenant_id', $actor->companyId())->first();

        if ($lead === null || (int) $employee->manager_id !== (int) $lead->id) {
            throw new LeaveRejected('You cannot review this request.');
        }
    }

    private function employee(User $actor): Employee
    {
        $companyId = $actor->companyId();
        $employee = $companyId === null ? null : Employee::query()->where('user_id', $actor->id)->where('tenant_id', $companyId)->lockForUpdate()->first();

        if ($employee === null || $employee->status !== 'active') {
            throw new LeaveRejected('This account cannot request leave.');
        }

        return $employee;
    }

    private function balance(Employee $employee, int $typeId, int $year): ?LeaveBalance
    {
        return LeaveBalance::query()
            ->where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->where('leave_type_id', $typeId)
            ->where('year', $year)
            ->lockForUpdate()
            ->first();
    }

    private function balanceForRequest(LeaveRequest $request): ?LeaveBalance
    {
        return LeaveBalance::query()
            ->where('tenant_id', $request->tenant_id)
            ->where('employee_id', $request->employee_id)
            ->where('leave_type_id', $request->leave_type_id)
            ->where('year', $request->start_date?->year)
            ->lockForUpdate()
            ->first();
    }

    private function lockRequest(LeaveRequest $request, int $companyId): LeaveRequest
    {
        $locked = LeaveRequest::query()->whereKey($request->id)->where('tenant_id', $companyId)->lockForUpdate()->first();

        if ($locked === null) {
            throw new LeaveRejected('This request cannot be reviewed.');
        }

        return $locked;
    }

    private function available(LeaveBalance $balance): float
    {
        return $this->round((float) $balance->allocated - (float) $balance->used - (float) $balance->pending);
    }

    private function round(float $value): float
    {
        return round($value, 1);
    }

    private function event(LeaveRequest $request, User $actor, string $action, ?string $note): void
    {
        $event = new LeaveRequestEvent;
        $event->forceFill([
            'tenant_id' => $request->tenant_id,
            'leave_request_id' => $request->id,
            'user_id' => $actor->id,
            'action' => $action,
            'note' => $note,
            'created_at' => now(),
        ])->save();
    }
}
