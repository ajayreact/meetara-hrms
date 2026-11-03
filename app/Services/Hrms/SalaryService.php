<?php

namespace App\Services\Hrms;

use App\Models\Employee;
use App\Models\SalaryStructure;
use App\Models\SalaryStructureEvent;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\QueryException;
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

    /**
     * Update one saved salary record. Other effective dates for the employee stay in place.
     *
     * @param  array<string, mixed>  $input
     */
    public function revise(User $actor, SalaryStructure $structure, array $input): SalaryStructure
    {
        return DB::transaction(function () use ($actor, $structure, $input): SalaryStructure {
            $companyId = (int) $actor->companyId();
            $locked = SalaryStructure::query()->whereKey($structure->id)->lockForUpdate()->first();

            if ($locked === null || (int) $locked->tenant_id !== $companyId) {
                throw new PayrollRejected('Choose a salary record in your company.');
            }

            $employee = Employee::query()->inCompany($companyId)->whereKey($locked->employee_id)->lockForUpdate()->first();

            if ($employee === null) {
                throw new PayrollRejected('Choose an employee in your company.');
            }

            $effective = (string) $input['effective_from'];
            $clash = SalaryStructure::query()
                ->where('employee_id', $locked->employee_id)
                ->whereKeyNot($locked->id)
                ->whereDate('effective_from', $effective)
                ->exists();

            if ($clash) {
                throw new PayrollRejected('Another salary record already starts on that date. This record was not changed.');
            }

            $locked->forceFill([
                'basic' => Money::format(Money::cents((string) $input['basic'])),
                'hra' => Money::format(Money::cents((string) $input['hra'])),
                'special_allowance' => Money::format(Money::cents((string) $input['special_allowance'])),
                'effective_from' => $effective,
                'currency' => strtoupper((string) $input['currency']),
            ]);

            try {
                $locked->save();
            } catch (QueryException $exception) {
                if (($exception->errorInfo[0] ?? null) === '23000') {
                    throw new PayrollRejected('Another salary record already starts on that date. This record was not changed.');
                }

                throw $exception;
            }

            $event = new SalaryStructureEvent;
            $event->forceFill([
                'tenant_id' => $companyId,
                'salary_structure_id' => $locked->id,
                'user_id' => $actor->id,
                'action' => 'updated',
                'effective_from' => $effective,
                'created_at' => now(),
            ])->save();

            return $locked;
        });
    }
}
