<?php

namespace App\Services\Hrms;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EmployeeProvisioner
{
    public function __construct(
        private readonly RoleAssignment $roles,
        private readonly EmployeeOnboarding $onboarding,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $actor, array $data): Employee
    {
        $companyId = $actor->companyId();
        abort_if($companyId === null, 403);

        return DB::transaction(function () use ($actor, $data, $companyId): Employee {
            $userId = $this->createLogin($actor, $data, $companyId);

            $employee = new Employee($this->attributes($data));
            $employee->forceFill([
                'tenant_id' => $companyId,
                'user_id' => $userId,
                'status' => $userId === null ? 'active' : 'invited',
            ]);
            $employee->save();
            $this->onboarding->open($actor, $employee);

            return $employee;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $actor, Employee $employee, array $data): Employee
    {
        $companyId = $actor->companyId();
        abort_if($companyId === null || (int) $employee->tenant_id !== $companyId, 404);

        return DB::transaction(function () use ($actor, $employee, $data, $companyId): Employee {
            $employee->fill($this->attributes($data));

                if ($employee->user_id === null && ($data['create_login'] ?? false)) {
                $employee->forceFill([
                    'user_id' => $this->createLogin($actor, $data, $companyId),
                    'status' => 'invited',
                ]);
            } elseif ($employee->user_id !== null) {
                $user = User::query()->whereKey($employee->user_id)->where('tenant_id', $companyId)->first();

                if ($user !== null) {
                    $user->forceFill([
                        'name' => trim($data['first_name'].' '.$data['last_name']),
                        'email' => $data['email'],
                    ])->save();

                    if (! empty($data['role'])) {
                        $this->roles->assign($actor, $user, $companyId, (string) $data['role']);
                    }
                }
            }

            $employee->save();

            return $employee;
        });
    }

    public function changeStatus(User $actor, Employee $employee, string $status): Employee
    {
        $companyId = $actor->companyId();
        abort_if($companyId === null || (int) $employee->tenant_id !== $companyId, 404);

        $employee->forceFill(['status' => $status])->save();

        return $employee;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        return [
            'employee_code' => $data['employee_code'],
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'department_id' => $data['department_id'] ?? null,
            'designation_id' => $data['designation_id'] ?? null,
            'location_id' => $data['location_id'] ?? null,
            'manager_id' => $data['manager_id'] ?? null,
            'date_of_joining' => $data['date_of_joining'],
            'holiday_calendar' => $data['holiday_calendar'],
            'employment_type' => $data['employment_type'],
            'work_mode' => $data['work_mode'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createLogin(User $actor, array $data, int $companyId): ?int
    {
        if (! ($data['create_login'] ?? false)) {
            return null;
        }

        $user = new User;
        $user->forceFill([
            'name' => trim($data['first_name'].' '.$data['last_name']),
            'email' => $data['email'],
            'password' => Str::password(40),
            'tenant_id' => $companyId,
            'is_platform_admin' => false,
            'invite_token' => Str::random(40),
            'invited_at' => now(),
            'invite_accepted_at' => null,
        ]);
        $user->save();
        $this->roles->assign($actor, $user, $companyId, (string) $data['role']);

        return (int) $user->id;
    }
}
