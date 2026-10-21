<?php

namespace App\Services\Hrms;

use App\Models\Employee;
use App\Models\SalaryStructure;
use App\Models\SalaryStructureEvent;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class SalaryService
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function record(User $actor, array $input): SalaryStructure
    {
        return DB::transaction(function () use ($actor, $input): SalaryStructure {
            $companyId = (int) $actor->companyId();
            $employee = Employee::query()->inCompany($companyId)->whereKey($input['employee_id'])->lockForUpdate()->first();

            if ($employee === null) {
                throw new PayrollRejected('Choose an employee in your company.');
            }

            $effective = (string) $input['effective_from'];
            $exists = SalaryStructure::query()
                ->where('employee_id', $employee->id)
                ->whereDate('effective_from', $effective)
                ->exists();

            if ($exists) {
                throw new PayrollRejected('A salary record already starts on that date. Add a new effective date instead of replacing it.');
            }

            $structure = new SalaryStructure;
            $structure->forceFill([
                'tenant_id' => $companyId,
                'employee_id' => $employee->id,
                'basic' => Money::format(Money::cents((string) $input['basic'])),
                'hra' => Money::format(Money::cents((string) $input['hra'])),
                'special_allowance' => Money::format(Money::cents((string) $input['special_allowance'])),
                'effective_from' => $effective,
                'currency' => strtoupper((string) $input['currency']),
            ])->save();

            $event = new SalaryStructureEvent;
            $event->forceFill([
                'tenant_id' => $companyId,
                'salary_structure_id' => $structure->id,
                'user_id' => $actor->id,
                'action' => 'created',
                'effective_from' => $effective,
                'created_at' => now(),
            ])->save();

            return $structure;
        });
    }
}
