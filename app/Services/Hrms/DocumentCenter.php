<?php

namespace App\Services\Hrms;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DocumentCenter
{
    public function __construct(private readonly DocumentCatalog $catalog) {}

    /**
     * @param  array{employee?: int, department?: int, category?: string, status?: string}  $filters
     * @return array<string, mixed>
     */
    public function dashboard(User $actor, array $filters): array
    {
        $companyId = (int) $actor->companyId();
        $categories = $this->catalog->all($companyId);
        $employees = $this->employees($companyId);
        $documents = $this->documents($companyId);
        $latest = $this->latest($documents);
        $required = array_values(array_filter($categories, fn (array $category) => $category['required'] && $category['active']));
        $checklists = [];

        foreach ($employees as $employee) {
            $checklists[] = $this->checklist($employee, $required, $latest);
        }

        $rows = $this->rows($employees, $categories, $latest, $filters);

        return [
            'summary' => [
                'total' => count($documents),
                'pending' => $this->countStatus($latest, 'pending'),
                'verified' => $this->countStatus($latest, 'verified'),
                'rejected' => $this->countStatus($latest, 'rejected'),
                'missing' => array_sum(array_column($checklists, 'missing')),
                'incompleteEmployees' => count(array_filter($checklists, fn (array $row) => $row['complete'] === false)),
            ],
            'filters' => [
                'employee' => $filters['employee'] ?? null,
                'department' => $filters['department'] ?? null,
                'category' => $filters['category'] ?? '',
                'status' => $filters['status'] ?? '',
            ],
            'employees' => array_map(fn (array $employee) => [
                'id' => $employee['id'],
                'name' => $employee['name'],
                'department' => $employee['department'],
                'departmentId' => $employee['departmentId'],
            ], $employees),
            'categories' => $categories,
            'groups' => DocumentCatalog::GROUPS,
            'documents' => $rows,
            'queue' => array_values(array_filter($rows, fn (array $row) => $row['status'] === 'Pending Verification')),
            'checklists' => $checklists,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function employee(User $actor, Employee $employee): array
    {
        abort_if((int) $actor->companyId() !== (int) $employee->tenant_id, 404);
        $companyId = (int) $employee->tenant_id;
        $categories = $this->catalog->all($companyId);
        $required = array_values(array_filter($categories, fn (array $category) => $category['required'] && $category['active']));
        $documents = $this->documents($companyId, (int) $employee->id);
        $latest = $this->latest($documents);
        $person = $this->employees($companyId, (int) $employee->id)[0] ?? null;
        abort_if($person === null, 404);

        return [
            'employee' => $person,
            'checklist' => $this->checklist($person, $required, $latest),
            'items' => $this->history($documents, $companyId),
            'categories' => $categories,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function mine(User $actor): array
    {
        $employee = $this->self($actor);
        $companyId = (int) $actor->companyId();
        $categories = $this->catalog->uploadChoices($companyId, false);
        $required = array_values(array_filter($this->catalog->all($companyId), fn (array $category) => $category['required'] && $category['active']));
        $documents = $employee === null ? [] : $this->documents($companyId, (int) $employee->id);
        $latest = $this->latest($documents);
        $person = $employee === null ? null : ($this->employees($companyId, (int) $employee->id)[0] ?? null);

        return [
            'linked' => $employee !== null,
            'employeeId' => $employee === null ? null : (int) $employee->id,
            'checklist' => $person === null ? null : $this->checklist($person, $required, $latest),
            'items' => $this->history($documents, $companyId),
            'categories' => $categories,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(User $actor, Employee $employee, int $documentId): array
    {
        abort_unless($actor->can('update', $employee) || (int) $employee->user_id === (int) $actor->id, 403);
        $companyId = (int) $employee->tenant_id;
        $documents = $this->documents($companyId, (int) $employee->id);
        $selected = null;

        foreach ($documents as $document) {
            if ((int) $document->id === $documentId) {
                $selected = $document;
            }
        }

        abort_if($selected === null, 404);
        $category = (string) $selected->category;
        $versions = array_values(array_filter($documents, fn ($document) => (string) $document->category === $category));
        $latestId = $versions === [] ? 0 : max(array_map(fn ($document) => (int) $document->id, $versions));

        return [
            'employee' => [
                'id' => (int) $employee->id,
                'name' => trim($employee->first_name.' '.$employee->last_name),
                'code' => (string) $employee->employee_code,
            ],
            'document' => $this->item($selected, $companyId, $latestId),
            'versions' => array_map(fn ($document) => $this->item($document, $companyId, $latestId), $versions),
            'canReview' => $actor->can('update', $employee) && (int) $employee->user_id !== (int) $actor->id,
        ];
    }

    private function self(User $actor): ?Employee
    {
        if ($actor->companyId() === null) {
            return null;
        }

        return Employee::query()->where('tenant_id', $actor->companyId())->where('user_id', $actor->id)->first();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function employees(int $companyId, ?int $employeeId = null): array
    {
        $query = Employee::query()->where('tenant_id', $companyId)->with('department')->orderBy('first_name');

        if ($employeeId !== null) {
            $query->whereKey($employeeId);
        }

        return $query->get()->map(fn (Employee $employee) => [
            'id' => (int) $employee->id,
            'name' => trim($employee->first_name.' '.$employee->last_name),
            'code' => (string) $employee->employee_code,
            'department' => $employee->department && (int) $employee->department->tenant_id === $companyId ? (string) $employee->department->name : null,
            'departmentId' => $employee->department_id === null ? null : (int) $employee->department_id,
        ])->all();
    }

    /**
     * @return list<object>
     */
    private function documents(int $companyId, ?int $employeeId = null): array
    {
        $query = DB::table('employee_documents')->where('tenant_id', $companyId)->orderBy('id');

        if ($employeeId !== null) {
            $query->where('employee_id', $employeeId);
        }

        return $query->get()->all();
    }

    /**
     * @param  list<object>  $documents
     * @return array<string, object>
     */
    private function latest(array $documents): array
    {
        $latest = [];

        foreach ($documents as $document) {
            $latest[$document->employee_id.'-'.$document->category] = $document;
        }

        return $latest;
    }

    /**
     * @param  list<array<string, mixed>>  $employees
     * @param  list<array<string, mixed>>  $required
     * @param  array<string, object>  $latest
     * @return array<string, mixed>
     */
    private function checklist(array $employee, array $required, array $latest): array
    {
        $items = [];
        $verified = 0;

        foreach ($required as $category) {
            $document = $latest[$employee['id'].'-'.$category['key']] ?? null;
            $status = $this->status($document);
            $items[] = [
                'key' => $category['key'],
                'name' => $category['name'],
                'status' => $status,
                'documentId' => $document === null ? null : (int) $document->id,
            ];

            if ($status === 'Verified') {
                $verified++;
            }
        }

        $total = count($required);

        return [
            'employeeId' => $employee['id'],
            'name' => $employee['name'],
            'code' => $employee['code'],
            'department' => $employee['department'],
            'required' => $total,
            'verified' => $verified,
            'missing' => count(array_filter($items, fn (array $item) => $item['status'] === 'Missing')),
            'pending' => count(array_filter($items, fn (array $item) => $item['status'] === 'Pending Verification')),
            'rejected' => count(array_filter($items, fn (array $item) => $item['status'] === 'Rejected')),
            'percentage' => $total === 0 ? 100 : (int) round(($verified / $total) * 100),
            'complete' => $total === 0 || $verified === $total,
            'items' => $items,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $employees
     * @param  list<array<string, mixed>>  $categories
     * @param  array<string, object>  $latest
     * @param  array{employee?: int, department?: int, category?: string, status?: string}  $filters
     * @return list<array<string, mixed>>
     */
    private function rows(array $employees, array $categories, array $latest, array $filters): array
    {
        $names = [];

        foreach ($categories as $category) {
            $names[$category['key']] = $category['name'];
        }

        $rows = [];

        foreach ($latest as $document) {
            $employee = null;

            foreach ($employees as $person) {
                if ($person['id'] === (int) $document->employee_id) {
                    $employee = $person;
                }
            }

            if ($employee === null) {
                continue;
            }

            $status = $this->status($document);

            if (($filters['employee'] ?? 0) > 0 && $employee['id'] !== (int) $filters['employee']) {
                continue;
            }

            if (($filters['department'] ?? 0) > 0 && $employee['departmentId'] !== (int) $filters['department']) {
                continue;
            }

            if (($filters['category'] ?? '') !== '' && (string) $document->category !== $filters['category']) {
                continue;
            }

            if (($filters['status'] ?? '') !== '' && $status !== $filters['status']) {
                continue;
            }

            $rows[] = [
                'id' => (int) $document->id,
                'employeeId' => $employee['id'],
                'employee' => $employee['name'],
                'department' => $employee['department'],
                'category' => $names[$document->category] ?? EmployeeDocuments::label((string) $document->category),
                'categoryKey' => (string) $document->category,
                'status' => $status,
                'uploadedAt' => (string) $document->created_at,
            ];
        }

        return array_reverse($rows);
    }

    /**
     * @param  list<object>  $documents
     * @return list<array<string, mixed>>
     */
    private function history(array $documents, int $companyId): array
    {
        $latestIds = [];

        foreach ($this->latest($documents) as $document) {
            $latestIds[(int) $document->id] = true;
        }

        return array_map(fn ($document) => $this->item($document, $companyId, isset($latestIds[(int) $document->id]) ? (int) $document->id : 0), array_reverse($documents));
    }

    /**
     * @return array<string, mixed>
     */
    private function item(object $document, int $companyId, int $latestId): array
    {
        $reviewer = $document->reviewed_by
            ? (string) DB::table('users')->where('id', $document->reviewed_by)->where('tenant_id', $companyId)->value('name')
            : '';

        return [
            'id' => (int) $document->id,
            'category' => $this->catalog->name($companyId, (string) $document->category),
            'categoryKey' => (string) $document->category,
            'status' => $this->status($document),
            'reference' => EmployeeDocuments::mask((string) ($document->reference ?? '')),
            'description' => EmployeeDocuments::mask((string) ($document->description ?? '')),
            'fileName' => EmployeeDocuments::mask((string) $document->original_name),
            'uploadedAt' => (string) $document->created_at,
            'reviewer' => $reviewer,
            'reviewedAt' => $document->reviewed_at ? (string) $document->reviewed_at : null,
            'note' => EmployeeDocuments::mask((string) ($document->review_note ?? '')),
            'replacesId' => $document->replaces_document_id === null ? null : (int) $document->replaces_document_id,
            'latest' => (int) $document->id === $latestId,
            'preview' => in_array(strtolower(pathinfo((string) $document->file_path, PATHINFO_EXTENSION)), ['pdf', 'jpg', 'png'], true),
        ];
    }

    /**
     * @param  array<string, object>  $latest
     */
    private function countStatus(array $latest, string $status): int
    {
        return count(array_filter($latest, fn ($document) => (string) $document->review_status === $status || ($status === 'pending' && $document->review_status === 'under_review')));
    }

    private function status(?object $document): string
    {
        if ($document === null) {
            return 'Missing';
        }

        return match ((string) $document->review_status) {
            'verified' => 'Verified',
            'rejected' => 'Rejected',
            default => 'Pending Verification',
        };
    }
}
