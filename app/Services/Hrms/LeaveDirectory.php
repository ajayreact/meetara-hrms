<?php

namespace App\Services\Hrms;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveBalance;
use App\Models\LeavePolicy;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Office;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

class LeaveDirectory
{
    /**
     * @return array<string, mixed>
     */
    public function page(User $user): array
    {
        if (! Schema::hasTable('leave_requests') || ! Schema::hasTable('leave_types')) {
            return [
                'balances' => [],
                'types' => [],
                'requests' => [],
                'queue' => [],
                'policies' => [],
                'canApply' => false,
                'canReview' => false,
                'canManagePolicies' => false,
                'halfDaySupported' => false,
            ];
        }

        $companyId = $user->companyId();
        $employee = $this->employee($user);
        $types = $companyId === null ? collect() : LeaveType::query()->inCompany($companyId)->orderBy('name')->get();

        return [
            'balances' => $this->balances($employee),
            'types' => $types->map(fn (LeaveType $type) => ['id' => $type->id, 'name' => $type->name, 'code' => $type->code])->all(),
            'requests' => $this->requests($employee),
            'queue' => $this->queue($user),
            'policies' => $user->applicationRole() === 'company_admin' ? $this->policies($companyId) : [],
            'canApply' => $employee !== null && $employee->status === 'active',
            'canReview' => in_array($user->applicationRole(), ['company_admin', 'hr', 'team_lead'], true),
            'canManagePolicies' => $user->applicationRole() === 'company_admin',
            'halfDaySupported' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function preview(User $user): array
    {
        if (! Schema::hasTable('leave_requests') || ! Schema::hasTable('leave_types')) {
            return ['balances' => [], 'upcoming' => $this->upcoming($user), 'queue' => []];
        }

        return [
            'balances' => $this->balances($this->employee($user)),
            'upcoming' => $this->upcoming($user),
            'queue' => array_slice(array_values(array_filter(
                $this->queue($user),
                fn (array $request): bool => $request['status'] === 'Pending',
            )), 0, 5),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function balances(?Employee $employee): array
    {
        if ($employee === null) {
            return [];
        }

        $rows = LeaveBalance::query()
            ->where('employee_id', $employee->id)
            ->where('tenant_id', $employee->tenant_id)
            ->where('year', (int) $this->zonedNow($employee)->year)
            ->get()
            ->keyBy('leave_type_id');

        return LeaveType::query()->inCompany((int) $employee->tenant_id)->orderBy('name')->get()->map(function (LeaveType $type) use ($rows): array {
            $balance = $rows->get($type->id);
            $allocated = (float) ($balance->allocated ?? 0);
            $used = (float) ($balance->used ?? 0);
            $pending = (float) ($balance->pending ?? 0);

            return [
                'id' => $type->id,
                'code' => $type->code,
                'name' => $type->name,
                'allocated' => $this->number($allocated),
                'booked' => $this->number($used + $pending),
                'used' => $this->number($used),
                'pending' => $this->number($pending),
                'remaining' => $this->number(max(0, $allocated - $used - $pending)),
                'recorded' => $balance !== null,
            ];
        })->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function requests(?Employee $employee): array
    {
        if ($employee === null) {
            return [];
        }

        return LeaveRequest::query()
            ->where('employee_id', $employee->id)
            ->where('tenant_id', $employee->tenant_id)
            ->with(['type:id,name', 'events'])
            ->latest('id')
            ->get()
            ->map(fn (LeaveRequest $request) => $this->present($request, true))
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function queue(User $user): array
    {
        if (! in_array($user->applicationRole(), ['company_admin', 'hr', 'team_lead'], true) || $user->companyId() === null) {
            return [];
        }

        $query = LeaveRequest::query()->inCompany($user->companyId())->with(['employee:id,first_name,last_name,manager_id,user_id', 'type:id,name'])->latest('id');
        $own = $this->employee($user);

        if ($own !== null) {
            $query->where('employee_id', '!=', $own->id);
        }

        if ($user->applicationRole() === 'team_lead') {
            if ($own === null) {
                return [];
            }

            $query->whereHas('employee', fn ($inner) => $inner->where('manager_id', $own->id));
        } elseif (! in_array($user->applicationRole(), ['company_admin', 'hr'], true)) {
            return [];
        }

        return $query->limit(50)->get()->map(fn (LeaveRequest $request) => $this->present($request, false))->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function policies(?int $companyId): array
    {
        if ($companyId === null) {
            return [];
        }

        return LeavePolicy::query()->inCompany($companyId)->with('type')->get()->map(fn (LeavePolicy $policy) => [
            'id' => $policy->id,
            'name' => $policy->name,
            'accrual' => $policy->accrual,
            'applicableAfterDays' => (int) $policy->applicable_after_days,
            'type' => $policy->type?->name,
        ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function upcoming(User $user): array
    {
        $employee = $this->employee($user);

        if ($employee === null || ! Schema::hasTable('holidays')) {
            return [];
        }

        $today = $this->zonedNow($employee)->toDateString();
        $query = Holiday::query()
            ->inCompany((int) $employee->tenant_id)
            ->where('calendar', strtolower((string) $employee->holiday_calendar))
            ->where('date', '>=', $today)
            ->where(function ($inner) use ($employee): void {
                $inner->whereNull('location_id');

                if ($employee->location_id) {
                    $inner->orWhere('location_id', $employee->location_id);
                }
            })
            ->orderBy('date')
            ->limit(3);

        if (Schema::hasColumn('holidays', 'status')) {
            $query->where('status', 'active');
        }

        return $query->get()->map(fn (Holiday $holiday) => [
            'id' => $holiday->id,
            'name' => $holiday->name,
            'date' => $holiday->date?->toDateString(),
            'weekday' => $holiday->date?->format('l'),
        ])->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(LeaveRequest $request, bool $own): array
    {
        $request->loadMissing('events');

        return [
            'id' => $request->id,
            'employee' => $own ? null : trim(($request->employee?->first_name ?? '').' '.($request->employee?->last_name ?? '')),
            'type' => $request->type?->name,
            'start' => $request->start_date?->toDateString(),
            'end' => $request->end_date?->toDateString(),
            'days' => $this->number((float) $request->days),
            'reason' => $request->reason,
            'status' => ucfirst((string) $request->status),
            'reviewerNote' => $request->reviewer_note,
            'reviewedAt' => $request->reviewed_at?->toDateTimeString(),
            'canCancel' => $own && $request->status === 'pending',
            'canReview' => ! $own && $request->status === 'pending',
            'history' => $request->events->map(fn ($event) => [
                'action' => ucfirst((string) $event->action),
                'note' => $event->note,
                'at' => Carbon::parse($event->created_at)->toDateTimeString(),
            ])->all(),
        ];
    }

    private function zonedNow(Employee $employee): Carbon
    {
        $officeZone = $employee->location_id
            ? Office::query()->whereKey($employee->location_id)->value('timezone')
            : null;
        $companyZone = Company::query()->whereKey($employee->tenant_id)->value('timezone');

        return now($officeZone ?: $companyZone ?: config('app.timezone'));
    }

    private function employee(User $user): ?Employee
    {
        $companyId = $user->companyId();

        if ($companyId === null) {
            return null;
        }

        return Employee::query()->where('user_id', $user->id)->where('tenant_id', $companyId)->first();
    }

    private function number(float $value): string
    {
        return number_format($value, 1, '.', '');
    }
}
