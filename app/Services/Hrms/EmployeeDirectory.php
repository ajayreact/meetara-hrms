<?php

namespace App\Services\Hrms;

use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\Office;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class EmployeeDirectory
{
    /**
     * @return list<array<string, mixed>>
     */
    public function list(User $actor, string $search = ''): array
    {
        $companyId = $actor->companyId();

        if ($companyId === null) {
            return [];
        }

        $query = Employee::query()
            ->inCompany($companyId)
            ->with(['department', 'designation', 'office', 'manager', 'user'])
            ->orderBy('first_name')
            ->orderBy('last_name');

        if ($actor->applicationRole() === 'team_lead') {
            $actorEmployee = $this->actorEmployee($actor);

            $query->where(function ($inner) use ($actorEmployee): void {
                $inner->where('id', $actorEmployee?->id ?? 0)
                    ->orWhere('manager_id', $actorEmployee?->id ?? 0);
            });
        }

        if ($actor->applicationRole() === 'employee') {
            $query->where('user_id', $actor->id);
        }

        if ($search !== '') {
            $term = '%'.addcslashes($search, '%_\\').'%';
            $query->where(function ($inner) use ($term): void {
                $inner->where('first_name', 'like', $term)
                    ->orWhere('last_name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('employee_code', 'like', $term);
            });
        }

        return $query->limit(200)->get()->map(fn (Employee $employee) => $this->summarize($employee))->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(Employee $employee): array
    {
        $employee->load(['department', 'designation', 'office', 'manager', 'user']);

        return array_merge($this->summarize($employee), [
            'phone' => $employee->phone,
            'employmentType' => (string) $employee->employment_type,
            'workMode' => (string) $employee->work_mode,
            'holidayCalendar' => (string) $employee->holiday_calendar,
            'departmentId' => $employee->department_id,
            'designationId' => $employee->designation_id,
            'officeId' => $employee->location_id,
            'managerId' => $employee->manager_id,
            'login' => $this->loginState($employee),
            'updatedAt' => optional($employee->updated_at)->toIso8601String(),
        ]);
    }

    /**
     * @return array{departments: list<array{id: int, name: string}>, designations: list<array{id: int, name: string}>, offices: list<array{id: int, name: string}>, managers: list<array{id: int, name: string}>, roles: list<array{value: string, label: string}>}
     */
    public function options(User $actor, ?int $exceptEmployeeId = null): array
    {
        $companyId = $actor->companyId();

        if ($companyId === null) {
            return [
                'departments' => [],
                'designations' => [],
                'offices' => [],
                'managers' => [],
                'roles' => [],
            ];
        }

        $managers = Employee::query()
            ->inCompany($companyId)
            ->when($exceptEmployeeId, fn ($query) => $query->whereKeyNot($exceptEmployeeId))
            ->orderBy('first_name')
            ->limit(200)
            ->get();

        return [
            'departments' => $this->named(Department::query()->inCompany($companyId)->orderBy('name')->get()),
            'designations' => $this->named(Designation::query()->inCompany($companyId)->orderBy('name')->get()),
            'offices' => $this->named(Office::query()->inCompany($companyId)->orderBy('name')->get()),
            'managers' => $managers->map(fn (Employee $employee) => [
                'id' => (int) $employee->id,
                'name' => trim($employee->first_name.' '.$employee->last_name),
            ])->values()->all(),
            'roles' => $this->roles($actor),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function own(User $user): ?array
    {
        $companyId = $user->companyId();

        if ($companyId === null) {
            return null;
        }

        $employee = Employee::query()
            ->inCompany($companyId)
            ->where('user_id', $user->id)
            ->with(['department', 'designation', 'office'])
            ->first();

        return $employee ? $this->summarize($employee) : null;
    }

    /**
     * The signed-in employee's own profile. Other employee records are not loaded.
     *
     * @return array<string, mixed>|null
     */
    public function selfProfile(User $user): ?array
    {
        $companyId = $user->companyId();

        if ($companyId === null) {
            return null;
        }

        $employee = Employee::query()
            ->inCompany($companyId)
            ->where('user_id', $user->id)
            ->with(['department', 'designation', 'office', 'manager'])
            ->first();

        if ($employee === null) {
            return null;
        }

        $summary = $this->summarize($employee);

        return [
            'personal' => [
                'name' => $summary['name'],
                'email' => $summary['email'],
                'phone' => $employee->phone ?: null,
                'address' => null,
                'dateOfBirth' => Schema::hasColumn('employees', 'date_of_birth') && $employee->date_of_birth !== null
                    ? $employee->date_of_birth->toDateString()
                    : null,
            ],
            'employment' => [
                'name' => $summary['name'],
                'code' => $summary['code'],
                'designation' => $summary['designation'],
                'department' => $summary['department'],
                'joined' => $summary['joined'],
                'status' => $summary['status'],
                'manager' => $summary['manager'],
                'office' => $summary['office'],
            ],
            'payroll' => [
                'bank' => null,
                'note' => 'Bank account details are not stored on the employee record.',
            ],
        ];
    }

    public function exportCsv(User $actor): string
    {
        $rows = $this->list($actor);
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['Employee ID', 'Name', 'Email', 'Department', 'Designation', 'Manager', 'Office', 'Joined', 'Status']);

        foreach ($rows as $row) {
            fputcsv($handle, [
                $this->csv($row['code']),
                $this->csv($row['name']),
                $this->csv($row['email']),
                $this->csv($row['department'] ?? ''),
                $this->csv($row['designation'] ?? ''),
                $this->csv($row['manager'] ?? ''),
                $this->csv($row['office'] ?? ''),
                $this->csv($row['joined'] ?? ''),
                $this->csv($row['status']),
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv === false ? '' : $csv;
    }

    /**
     * @return array<string, mixed>
     */
    private function summarize(Employee $employee): array
    {
        $companyId = (int) $employee->tenant_id;

        return [
            'id' => (int) $employee->id,
            'code' => (string) $employee->employee_code,
            'name' => trim($employee->first_name.' '.$employee->last_name),
            'firstName' => (string) $employee->first_name,
            'lastName' => (string) $employee->last_name,
            'email' => (string) $employee->email,
            'phone' => $employee->phone,
            'department' => $this->sameCompanyName($employee->department, $companyId),
            'designation' => $this->sameCompanyName($employee->designation, $companyId),
            'manager' => $employee->manager && (int) $employee->manager->tenant_id === $companyId
                ? trim($employee->manager->first_name.' '.$employee->manager->last_name)
                : null,
            'office' => $this->sameCompanyName($employee->office, $companyId),
            'joined' => optional($employee->date_of_joining)->toDateString(),
            'status' => $this->statusLabel($employee),
            'statusValue' => (string) $employee->status,
            'invitation' => $this->invitation($employee),
        ];
    }

    private function sameCompanyName(?object $related, int $companyId): ?string
    {
        if ($related === null || ! isset($related->tenant_id, $related->name) || (int) $related->tenant_id !== $companyId) {
            return null;
        }

        return (string) $related->name;
    }

    private function statusLabel(Employee $employee): string
    {
        $status = (string) $employee->status;

        if (in_array($status, ['inactive', 'deactivated', 'terminated', 'exited', 'suspended'], true)) {
            return 'Inactive';
        }

        if ($status === 'invited') {
            return $this->invitation($employee)['state'] === 'expired' ? 'Invitation Expired' : 'Invitation Pending';
        }

        if ($status === 'active') {
            return 'Active';
        }

        return ucfirst($status);
    }

    /**
     * @return array{state: string, expiresAt: string|null, sent: bool}
     */
    private function invitation(Employee $employee): array
    {
        $user = $employee->user;
        if ($employee->user_id === null || $user === null || (int) $user->tenant_id !== (int) $employee->tenant_id) {
            return ['state' => 'none', 'expiresAt' => null, 'sent' => false];
        }

        if ($user->invite_accepted_at !== null) {
            return ['state' => 'accepted', 'expiresAt' => null, 'sent' => $user->invite_sent_at !== null];
        }

        if ($user->invited_at === null) {
            return ['state' => 'none', 'expiresAt' => null, 'sent' => false];
        }

        if ($user->invite_token === null || $user->invite_expires_at === null) {
            return ['state' => 'not_sent', 'expiresAt' => null, 'sent' => false];
        }

        $expiresAt = $user->invite_expires_at->timezone((string) config('app.timezone'))->format('d M Y, H:i T');
        if (now()->greaterThan($user->invite_expires_at)) {
            return ['state' => 'expired', 'expiresAt' => $expiresAt, 'sent' => $user->invite_sent_at !== null];
        }

        return ['state' => 'pending', 'expiresAt' => $expiresAt, 'sent' => $user->invite_sent_at !== null];
    }

    private function loginState(Employee $employee): string
    {
        $invitation = $this->invitation($employee);

        return match ($invitation['state']) {
            'none' => $employee->user_id === null ? 'Not linked' : 'Login linked',
            'accepted' => 'Login linked',
            'expired' => 'Invitation expired',
            'not_sent' => 'Invitation not sent',
            default => 'Invitation pending',
        };
    }

    private function actorEmployee(User $actor): ?Employee
    {
        $companyId = $actor->companyId();

        if ($companyId === null) {
            return null;
        }

        return Employee::query()->inCompany($companyId)->where('user_id', $actor->id)->first();
    }

    /**
     * @param  Collection<int, Department|Designation|Office>  $rows
     * @return list<array{id: int, name: string}>
     */
    private function named(Collection $rows): array
    {
        return $rows->map(fn ($row) => [
            'id' => (int) $row->id,
            'name' => (string) $row->name,
        ])->values()->all();
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function roles(User $actor): array
    {
        $allowed = match ($actor->applicationRole()) {
            'company_admin' => ['Employee' => 'Employee', 'Manager' => 'Team Lead', 'HR' => 'HR', 'Accounts' => 'Accounts', 'Owner' => 'Company Admin'],
            'hr' => ['Employee' => 'Employee', 'Manager' => 'Team Lead', 'HR' => 'HR'],
            default => [],
        };

        $available = $actor->getConnection()->table('roles')
            ->where('tenant_id', $actor->companyId())
            ->where('guard_name', 'web')
            ->whereIn('name', array_keys($allowed))
            ->pluck('name')
            ->all();

        $roles = [];

        foreach ($allowed as $value => $label) {
            if (in_array($value, $available, true)) {
                $roles[] = ['value' => $value, 'label' => $label];
            }
        }

        return $roles;
    }

    private function csv(string $value): string
    {
        return preg_match('/^[=+\-@]/', $value) === 1 ? "'".$value : $value;
    }
}
