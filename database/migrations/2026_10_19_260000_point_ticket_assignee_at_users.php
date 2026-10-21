<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->retarget('users');
    }

    public function down(): void
    {
        $this->retarget('employees');
    }

    private function retarget(string $target): void
    {
        if (DB::getDriverName() !== 'mysql' || ! Schema::hasTable('tickets') || ! Schema::hasColumn('tickets', 'assignee_id')) {
            return;
        }

        $current = collect(Schema::getForeignKeys('tickets'))
            ->first(fn (array $key) => ($key['columns'][0] ?? null) === 'assignee_id');
        $currentTable = $current['foreign_table'] ?? null;

        if ($currentTable === $target) {
            return;
        }

        $rows = DB::table('tickets')->whereNotNull('assignee_id')->get(['id', 'tenant_id', 'assignee_id']);
        $replacements = [];

        foreach ($rows as $row) {
            $replacements[(int) $row->id] = $target === 'users'
                ? $this->userForEmployeeAssignment($row)
                : $this->employeeForUserAssignment($row);
        }

        if ($current !== null) {
            Schema::table('tickets', function (Blueprint $blueprint) use ($current) {
                $blueprint->dropForeign($current['name']);
            });
        }

        try {
            foreach ($replacements as $ticketId => $assigneeId) {
                DB::table('tickets')->where('id', $ticketId)->update([
                    'assignee_id' => $assigneeId,
                    'updated_at' => now(),
                ]);
            }

            Schema::table('tickets', function (Blueprint $blueprint) use ($target) {
                $blueprint->foreign('assignee_id')->references('id')->on($target)->nullOnDelete();
            });
        } catch (\Throwable $exception) {
            foreach ($rows as $row) {
                DB::table('tickets')->where('id', $row->id)->update([
                    'assignee_id' => $row->assignee_id,
                    'updated_at' => now(),
                ]);
            }

            if ($currentTable !== null) {
                Schema::table('tickets', function (Blueprint $blueprint) use ($currentTable) {
                    $blueprint->foreign('assignee_id')->references('id')->on($currentTable)->nullOnDelete();
                });
            }

            throw $exception;
        }
    }

    private function userForEmployeeAssignment(object $ticket): int
    {
        $employee = DB::table('employees')->where('id', $ticket->assignee_id)->first();

        if ($employee === null || $employee->user_id === null || (int) $employee->tenant_id !== (int) $ticket->tenant_id) {
            throw new RuntimeException('A ticket assignment cannot be moved because the assignee is not an employee with a user in the same company.');
        }

        $userTenant = DB::table('users')->where('id', $employee->user_id)->value('tenant_id');

        if ((int) $userTenant !== (int) $ticket->tenant_id) {
            throw new RuntimeException('A ticket assignment cannot be moved because the assignee is not an employee with a user in the same company.');
        }

        return (int) $employee->user_id;
    }

    private function employeeForUserAssignment(object $ticket): int
    {
        $employees = DB::table('employees')
            ->where('tenant_id', $ticket->tenant_id)
            ->where('user_id', $ticket->assignee_id)
            ->pluck('id');

        if ($employees->count() !== 1) {
            throw new RuntimeException('Refusing to point ticket assignments back at employees because the mapping is not one employee.');
        }

        return (int) $employees->first();
    }
};
