<?php

namespace App\Services\Hrms;

use App\Auth\CompanyRole;
use App\Models\Lead;
use App\Models\User;
use App\Support\AuthenticatedWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class LeadDirectory
{
    public const PAGE_SIZE = 25;

    private const SELECTION_LIMIT = 5000;

    public function assertViewer(User $actor): void
    {
        $role = AuthenticatedWorkspace::resolve($actor)['role'];
        $permissions = CompanyRole::permissions(is_string($role) ? $role : '');

        if (($permissions['leadsViewAll'] ?? false) !== true || $actor->companyId() === null) {
            abort(403);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function page(User $actor, array $input): array
    {
        $this->assertViewer($actor);
        $tenantId = (int) $actor->companyId();
        $filters = $this->filters($input);
        $query = $this->filtered($tenantId, $filters);
        $matching = (clone $query)->count();
        $pages = max(1, (int) ceil($matching / self::PAGE_SIZE));
        $page = min(max(1, (int) ($input['page'] ?? 1)), $pages);
        $rows = (clone $query)
            ->leftJoin('lead_import_batches', function ($join) use ($tenantId): void {
                $join->on('lead_import_batches.id', '=', 'leads.import_batch_id')
                    ->where('lead_import_batches.tenant_id', '=', $tenantId);
            })
            ->leftJoin('employees as lead_assignees', function ($join) use ($tenantId): void {
                $join->on('lead_assignees.id', '=', 'leads.assignee_employee_id')
                    ->where('lead_assignees.tenant_id', '=', $tenantId);
            })
            ->orderByDesc('leads.id')
            ->forPage($page, self::PAGE_SIZE)
            ->get([
                'leads.id',
                'leads.name',
                'leads.email',
                'leads.phone',
                'leads.state',
                'leads.university',
                'leads.graduation_term',
                'leads.pipeline_status',
                'leads.import_batch_id',
                'leads.team_lead_employee_id',
                'leads.assignee_employee_id',
                'lead_import_batches.original_name as batch_name',
                'lead_assignees.first_name as assignee_first_name',
                'lead_assignees.last_name as assignee_last_name',
            ]);

        return [
            'summary' => $this->summary($tenantId),
            'filters' => [
                'q' => $filters['q'],
                'state' => $filters['state'],
                'university' => $filters['university'],
                'term' => $filters['term'],
                'status' => $filters['status'],
                'pool' => $filters['pool'],
            ],
            'options' => $this->options($tenantId),
            'leads' => $rows->map(fn (Lead $lead): array => $this->row($lead))->all(),
            'matching' => $matching,
            'page' => $page,
            'pages' => $pages,
            'selectedIds' => $this->selectedIds($tenantId, $filters, $input),
            'selectable' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(User $actor, int $leadId): array
    {
        $this->assertViewer($actor);
        $tenantId = (int) $actor->companyId();
        $lead = Lead::query()->inCompany($tenantId)->whereKey($leadId)->first();
        abort_if($lead === null, 404);

        return [
            'lead' => [
                'id' => (int) $lead->id,
                'name' => (string) $lead->name,
                'email' => (string) ($lead->email ?? ''),
                'phone' => (string) ($lead->phone ?? ''),
                'linkedin' => (string) ($lead->linkedin_url ?? ''),
                'state' => (string) ($lead->state ?? ''),
                'university' => (string) ($lead->university ?? ''),
                'graduationTerm' => (string) ($lead->graduation_term ?? ''),
                'experience' => (string) ($lead->experience ?? ''),
                'designations' => (string) ($lead->designations ?? ''),
                'status' => (string) $lead->pipeline_status,
                'statusLabel' => Lead::STATUSES[$lead->pipeline_status] ?? (string) $lead->pipeline_status,
                'importedOn' => $this->date($lead->imported_on),
                'sourceRow' => $lead->source_row === null ? null : (int) $lead->source_row,
                'followUpOn' => $this->date($lead->follow_up_on),
                'remarks' => (string) ($lead->remarks ?? ''),
                'unassigned' => $lead->team_lead_employee_id === null && $lead->assignee_employee_id === null,
            ],
            'batch' => $this->batch($tenantId, $lead->import_batch_id === null ? null : (int) $lead->import_batch_id),
        ];
    }

    /**
     * @return array{total: int, unassigned: int, imported: int}
     */
    private function summary(int $tenantId): array
    {
        $base = Lead::query()->inCompany($tenantId);

        return [
            'total' => (clone $base)->count(),
            'unassigned' => (clone $base)->whereNull('team_lead_employee_id')->whereNull('assignee_employee_id')->count(),
            'imported' => (clone $base)->whereNotNull('import_batch_id')->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{q: string, state: string, university: string, term: string, status: string, pool: string}
     */
    private function filters(array $input): array
    {
        $status = trim((string) ($input['status'] ?? ''));
        $pool = (string) ($input['pool'] ?? 'all');

        return [
            'q' => mb_substr(trim((string) ($input['q'] ?? '')), 0, 120),
            'state' => mb_substr(trim((string) ($input['state'] ?? '')), 0, 255),
            'university' => mb_substr(trim((string) ($input['university'] ?? '')), 0, 255),
            'term' => mb_substr(trim((string) ($input['term'] ?? '')), 0, 255),
            'status' => array_key_exists($status, Lead::STATUSES) ? $status : '',
            'pool' => $pool === 'unassigned' ? 'unassigned' : 'all',
        ];
    }

    /**
     * @param  array{q: string, state: string, university: string, term: string, status: string, pool: string}  $filters
     * @return Builder<Lead>
     */
    private function filtered(int $tenantId, array $filters): Builder
    {
        $query = Lead::query()->inCompany($tenantId);

        if ($filters['pool'] === 'unassigned') {
            $query->whereNull('leads.team_lead_employee_id')->whereNull('leads.assignee_employee_id');
        }

        foreach (['state' => 'state', 'university' => 'university', 'term' => 'graduation_term'] as $filter => $column) {
            if ($filters[$filter] !== '') {
                $query->where('leads.'.$column, $filters[$filter]);
            }
        }

        if ($filters['status'] !== '') {
            $query->where('leads.pipeline_status', $filters['status']);
        }

        if ($filters['q'] !== '') {
            $like = '%'.addcslashes($filters['q'], '%_\\').'%';
            $digits = preg_replace('/\D+/', '', $filters['q']) ?? '';
            $query->where(function (Builder $inner) use ($like, $digits): void {
                $inner->where('leads.name', 'like', $like)
                    ->orWhere('leads.email', 'like', $like)
                    ->orWhere('leads.email_normalized', 'like', $like)
                    ->orWhere('leads.phone', 'like', $like);

                if (strlen($digits) >= 3) {
                    $inner->orWhere('leads.phone_digits', 'like', '%'.$digits.'%');
                }
            });
        }

        return $query;
    }

    /**
     * @param  array{q: string, state: string, university: string, term: string, status: string, pool: string}  $filters
     * @param  array<string, mixed>  $input
     * @return list<int>|null
     */
    private function selectedIds(int $tenantId, array $filters, array $input): ?array
    {
        $selection = (string) ($input['selection'] ?? '');

        if ($selection === 'matching') {
            return $this->filtered($tenantId, $filters)
                ->orderByDesc('leads.id')
                ->limit(self::SELECTION_LIMIT)
                ->pluck('leads.id')
                ->map(fn ($id): int => (int) $id)
                ->all();
        }

        if ($selection !== 'chosen') {
            return null;
        }

        $requested = $this->idList($input['ids'] ?? []);

        if ($requested === []) {
            return [];
        }

        return $this->filtered($tenantId, $filters)
            ->whereIn('leads.id', $requested)
            ->orderByDesc('leads.id')
            ->pluck('leads.id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @return list<int>
     */
    private function idList(mixed $ids): array
    {
        if (is_string($ids)) {
            $ids = explode(',', $ids);
        }

        if (! is_array($ids)) {
            return [];
        }

        $clean = [];

        foreach (array_slice($ids, 0, self::SELECTION_LIMIT) as $id) {
            if (is_numeric($id) && (int) $id > 0) {
                $clean[] = (int) $id;
            }
        }

        return array_values(array_unique($clean));
    }

    /**
     * @return array{states: list<string>, universities: list<string>, terms: list<string>, statuses: list<array{value: string, label: string}>}
     */
    private function options(int $tenantId): array
    {
        $statuses = [];

        foreach (Lead::STATUSES as $value => $label) {
            $statuses[] = ['value' => $value, 'label' => $label];
        }

        return [
            'states' => $this->distinct($tenantId, 'state'),
            'universities' => $this->distinct($tenantId, 'university'),
            'terms' => $this->distinct($tenantId, 'graduation_term'),
            'statuses' => $statuses,
        ];
    }

    /**
     * @return list<string>
     */
    private function distinct(int $tenantId, string $column): array
    {
        return Lead::query()
            ->inCompany($tenantId)
            ->whereNotNull('leads.'.$column)
            ->where('leads.'.$column, '!=', '')
            ->distinct()
            ->orderBy('leads.'.$column)
            ->limit(100)
            ->pluck('leads.'.$column)
            ->map(fn ($value): string => (string) $value)
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Lead $lead): array
    {
        $status = (string) $lead->pipeline_status;

        return [
            'id' => (int) $lead->id,
            'name' => (string) $lead->name,
            'email' => (string) ($lead->email ?? ''),
            'phone' => (string) ($lead->phone ?? ''),
            'state' => (string) ($lead->state ?? ''),
            'university' => (string) ($lead->university ?? ''),
            'graduationTerm' => (string) ($lead->graduation_term ?? ''),
            'status' => $status,
            'statusLabel' => Lead::STATUSES[$status] ?? $status,
            'batchId' => $lead->import_batch_id === null ? null : (int) $lead->import_batch_id,
            'batchName' => is_string($lead->getAttribute('batch_name')) ? $lead->getAttribute('batch_name') : '',
            'unassigned' => $lead->team_lead_employee_id === null && $lead->assignee_employee_id === null,
            'heldBy' => $this->heldBy($lead),
        ];
    }

    private function heldBy(Lead $lead): string
    {
        $assigneeId = $lead->assignee_employee_id === null ? null : (int) $lead->assignee_employee_id;
        $teamLeadId = $lead->team_lead_employee_id === null ? null : (int) $lead->team_lead_employee_id;

        if ($teamLeadId === null && $assigneeId === null) {
            return 'Company pool';
        }

        if ($assigneeId === null) {
            return 'Not distributed';
        }

        $name = trim((string) $lead->getAttribute('assignee_first_name').' '.(string) $lead->getAttribute('assignee_last_name'));

        if ($assigneeId === $teamLeadId) {
            return 'Retained by '.($name !== '' ? $name : 'Team Lead');
        }

        return $name !== '' ? $name : 'Employee';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function batch(int $tenantId, ?int $batchId): ?array
    {
        if ($batchId === null) {
            return null;
        }

        $batch = DB::table('lead_import_batches')->where('tenant_id', $tenantId)->where('id', $batchId)->first();

        if ($batch === null) {
            return null;
        }

        $uploader = null;

        if ($batch->uploaded_by !== null) {
            $name = DB::table('users')->where('id', $batch->uploaded_by)->where('tenant_id', $tenantId)->value('name');
            $uploader = is_string($name) ? $name : null;
        }

        return [
            'id' => (int) $batch->id,
            'fileName' => (string) $batch->original_name,
            'status' => (string) $batch->status,
            'rowCount' => (int) $batch->row_count,
            'createdCount' => (int) $batch->created_count,
            'duplicateCount' => (int) $batch->duplicate_count,
            'invalidCount' => (int) $batch->invalid_count,
            'uploadedBy' => $uploader,
            'uploadedAt' => $batch->created_at === null ? null : (string) $batch->created_at,
        ];
    }

    private function date(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        return substr($value, 0, 10);
    }
}
