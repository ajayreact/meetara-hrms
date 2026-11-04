<?php

namespace App\Services\Hrms;

use App\Models\Department;
use App\Models\Designation;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class CompanyCatalog
{
    /**
     * @return list<array{id: int, name: string, code: string|null, active: bool}>
     */
    public function departments(int $companyId): array
    {
        return Department::query()
            ->inCompany($companyId)
            ->orderBy('name')
            ->get()
            ->map(fn (Department $department) => [
                'id' => (int) $department->id,
                'name' => (string) $department->name,
                'code' => Schema::hasColumn('departments', 'code') ? ($department->code ?: null) : null,
                'active' => $this->active($department),
            ])
            ->all();
    }

    /**
     * @return list<array{id: int, name: string, active: bool}>
     */
    public function designations(int $companyId): array
    {
        return Designation::query()
            ->inCompany($companyId)
            ->orderBy('name')
            ->get()
            ->map(fn (Designation $designation) => [
                'id' => (int) $designation->id,
                'name' => (string) $designation->name,
                'active' => $this->active($designation),
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveDepartment(User $actor, array $data, ?Department $department = null): Department
    {
        $companyId = $this->companyId($actor);
        $record = $department ?? new Department;
        abort_if($department !== null && (int) $department->tenant_id !== $companyId, 404);

        $attributes = [
            'name' => $this->name($data['name'] ?? null),
            'is_active' => $this->activeValue($data),
        ];

        if (Schema::hasColumn('departments', 'code')) {
            $attributes['code'] = $this->nullable($data['code'] ?? null);
        }

        if (! Schema::hasColumn('departments', 'is_active')) {
            unset($attributes['is_active']);
        }

        if ($department === null) {
            $record->forceFill(['tenant_id' => $companyId]);
        }

        $this->persist($record, $attributes, 'name', 'A department with this name already exists.');

        return $record;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveDesignation(User $actor, array $data, ?Designation $designation = null): Designation
    {
        $companyId = $this->companyId($actor);
        $record = $designation ?? new Designation;
        abort_if($designation !== null && (int) $designation->tenant_id !== $companyId, 404);

        $attributes = [
            'name' => $this->name($data['name'] ?? null),
            'is_active' => $this->activeValue($data),
        ];

        if (! Schema::hasColumn('designations', 'is_active')) {
            unset($attributes['is_active']);
        }

        if ($designation === null) {
            $record->forceFill(['tenant_id' => $companyId]);
        }

        $this->persist($record, $attributes, 'name', 'A designation with this name already exists.');

        return $record;
    }

    /**
     * @return list<array{value: string, label: string, description: string, available: bool, protected: bool}>
     */
    public function accessLevels(int $companyId): array
    {
        $present = DB::table('roles')
            ->where('tenant_id', $companyId)
            ->where('guard_name', 'web')
            ->pluck('name')
            ->all();

        $rows = [
            ['value' => 'Employee', 'label' => 'Employee', 'description' => 'Signs in to a personal workspace.', 'protected' => false],
            ['value' => 'Manager', 'label' => 'Team Lead', 'description' => 'Sees people who report to that employee.', 'protected' => false],
            ['value' => 'HR', 'label' => 'HR', 'description' => 'Manages people, attendance, and leave for this company.', 'protected' => false],
            ['value' => 'Owner', 'label' => 'Company Admin', 'description' => 'Protected administrative role. It cannot be removed or renamed.', 'protected' => true],
            ['value' => 'Accounts', 'label' => 'Accounts', 'description' => 'Optional. Enabling it does not assign it to anyone.', 'protected' => false],
        ];

        return array_map(fn (array $row) => [
            ...$row,
            'available' => in_array($row['value'], $present, true),
        ], $rows);
    }

    private function companyId(User $actor): int
    {
        $companyId = $actor->companyId();
        abort_if($companyId === null, 403);

        return $companyId;
    }

    private function name(mixed $value): string
    {
        return trim((string) $value);
    }

    private function nullable(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function activeValue(array $data): bool
    {
        if (! array_key_exists('is_active', $data)) {
            return true;
        }

        return filter_var($data['is_active'], FILTER_VALIDATE_BOOL);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function persist(Department|Designation $record, array $attributes, string $field, string $message): void
    {
        $record->fill($attributes);

        try {
            $record->save();
        } catch (QueryException $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'unique')) {
                throw ValidationException::withMessages([$field => $message]);
            }

            throw $exception;
        }
    }

    private function active(Department|Designation $record): bool
    {
        if (! Schema::hasColumn($record->getTable(), 'is_active')) {
            return true;
        }

        return (bool) $record->is_active;
    }
}
