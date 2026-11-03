<?php

namespace App\Services\Hrms;

use App\Models\Employee;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AttendanceCalendar
{
    public static function holiday(Employee $employee, ?int $officeId, string $date): ?object
    {
        if (! Schema::hasTable('holidays')) {
            return null;
        }

        $query = DB::table('holidays')
            ->where('tenant_id', $employee->tenant_id)
            ->whereDate('date', $date)
            ->where('calendar', $employee->holiday_calendar ?: 'india');

        if (Schema::hasColumn('holidays', 'status')) {
            $query->where(function ($inner): void {
                $inner->whereNull('status')->orWhere('status', 'active');
            });
        }

        $query->where(function ($inner) use ($officeId): void {
            $inner->whereNull('location_id');
            if ($officeId) {
                $inner->orWhere('location_id', $officeId);
            }
        });

        return $query->orderByRaw('case when location_id is null then 1 else 0 end')->first();
    }

    public static function authorized(int $companyId, int $employeeId, string $date, string $kind): bool
    {
        if (! Schema::hasTable('holiday_work_authorizations')) {
            return false;
        }

        return DB::table('holiday_work_authorizations')
            ->where('tenant_id', $companyId)
            ->where('employee_id', $employeeId)
            ->whereDate('work_date', $date)
            ->where('kind', $kind)
            ->exists();
    }

    public static function label(?string $storedStatus, bool $openBreak, bool $late, bool $early, bool $expected, ?string $holidayName, bool $clockedIn): string
    {
        if ($storedStatus === 'recovered') {
            return 'Recovered';
        }

        if ($storedStatus === 'holiday_work') {
            return 'Holiday Work';
        }

        if ($openBreak) {
            return 'On break';
        }

        if ($early) {
            return 'Early out';
        }

        if ($late) {
            return 'Late';
        }

        if ($clockedIn) {
            return 'Present';
        }

        if ($holidayName !== null) {
            return 'Holiday';
        }

        if (! $expected) {
            return 'Weekly Off';
        }

        return 'Absent';
    }
}
