<?php

namespace App\Services\Hrms;

use App\Auth\CompanyRole;
use App\Models\Lead;
use App\Models\User;
use App\Support\AuthenticatedWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class LeadFollowUp
{
    public const PAGE_SIZE = 25;

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function employeePage(User $actor, array $input): array
    {
        $context = $this->employeeContext($actor);
        $today = $this->today($context['tenantId']);
        $queue = $this->queue((string) ($input['queue'] ?? 'all'));
        $query = $this->assigned($context['tenantId'], $context['employeeId']);
        $counts = $this->counts(clone $query, $today);
        $filtered = $this->queued(clone $query, $queue, $today);
        $matching = (clone $filtered)->count();
        $pages = max(1, (int) ceil($matching / self::PAGE_SIZE));
        $page = min(max(1, (int) ($input['page'] ?? 1)), $pages);
        $rows = $filtered->orderBy('leads.follow_up_on')->orderByDesc('leads.id')->forPage($page, self::PAGE_SIZE)->get([
            'leads.id',
            'leads.name',
            'leads.email',
            'leads.phone',
            'leads.linkedin_url',
            'leads.university',
            'leads.graduation_term',
            'leads.experience',
            'leads.pipeline_status',
            'leads.follow_up_on',
        ]);

        return [
            'queue' => $queue,
            'counts' => $counts,
            'leads' => $rows->map(fn (Lead $lead): array => [
                'id' => (int) $lead->id,
                'name' => (string) $lead->name,
                'email' => (string) ($lead->email ?? ''),
                'phone' => (string) ($lead->phone ?? ''),
                'linkedin' => (string) ($lead->linkedin_url ?? ''),
                'university' => (string) ($lead->university ?? ''),
                'graduationTerm' => (string) ($lead->graduation_term ?? ''),
                'experience' => (string) ($lead->experience ?? ''),
                'status' => (string) $lead->pipeline_status,
                'statusLabel' => $this->label((string) $lead->pipeline_status),
                'followUpOn' => $this->date($lead->follow_up_on),
                'timing' => $this->timing($this->date($lead->follow_up_on), $today),
            ])->all(),
            'matching' => $matching,
            'page' => $page,
            'pages' => $pages,
            'statuses' => $this->statuses(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function employeeRecord(User $actor, int $leadId): array
    {
        $context = $this->employeeContext($actor);
        $lead = $this->assignedLead($context['tenantId'], $context['employeeId'], $leadId);

        return $this->present($context['tenantId'], $lead, '/leads/mine/'.$leadId.'/follow-ups', '/leads/mine');
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function companyPage(User $actor, array $input): array
    {
        $tenantId = $this->companyContext($actor);
        $today = $this->today($tenantId);
        $queue = $this->queue((string) ($input['queue'] ?? 'overdue'));
        if ($queue === 'all') {
            $queue = 'overdue';
        }
        $base = Lead::query()->inCompany($tenantId)->whereNotNull('leads.follow_up_on');
        $counts = $this->counts(clone $base, $today);
        $rows = $this->queued(clone $base, $queue, $today)
            ->orderBy('leads.follow_up_on')
            ->orderBy('leads.id')
            ->limit(self::PAGE_SIZE)
            ->get(['leads.id', 'leads.name', 'leads.pipeline_status', 'leads.follow_up_on', 'leads.assignee_employee_id']);
        $names = $this->employeeNames($tenantId, $rows->pluck('assignee_employee_id')->filter()->map(fn ($id): int => (int) $id)->all());

        return [
            'queue' => $queue,
            'counts' => $counts,
            'leads' => $rows->map(fn (Lead $lead): array => [
                'id' => (int) $lead->id,
                'name' => (string) $lead->name,
                'assigneeName' => $this->named($names, $lead->assignee_employee_id),
                'statusLabel' => $this->label((string) $lead->pipeline_status),
                'followUpOn' => $this->date($lead->follow_up_on),
            ])->all(),
            'recentFollowUps' => $this->activities($tenantId, null, null),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function monitor(User $actor, array $input): array
    {
        $context = $this->teamContext($actor);
        $today = $this->today($context['tenantId']);
        $queue = $this->queue((string) ($input['attention'] ?? 'overdue'));
        if ($queue === 'all') {
            $queue = 'overdue';
        }
        $base = Lead::query()->inCompany($context['tenantId'])->where('leads.team_lead_employee_id', $context['employeeId'])->whereNotNull('leads.follow_up_on');
        $rows = $this->queued(clone $base, $queue, $today)
            ->orderBy('leads.follow_up_on')
            ->orderBy('leads.id')
            ->limit(self::PAGE_SIZE)
            ->get(['leads.id', 'leads.name', 'leads.pipeline_status', 'leads.follow_up_on', 'leads.assignee_employee_id']);
        $names = $this->employeeNames($context['tenantId'], $rows->pluck('assignee_employee_id')->filter()->map(fn ($id): int => (int) $id)->all());

        return [
            'attention' => [
                'queue' => $queue,
                ...$this->counts(clone $base, $today),
                'leads' => $rows->map(fn (Lead $lead): array => [
                    'id' => (int) $lead->id,
                    'name' => (string) $lead->name,
                    'assigneeName' => $this->named($names, $lead->assignee_employee_id),
                    'statusLabel' => $this->label((string) $lead->pipeline_status),
                    'followUpOn' => (string) $this->date($lead->follow_up_on),
                ])->all(),
            ],
            'byEmployee' => $this->byEmployee($context['tenantId'], $context['employeeId'], $today),
            'recentFollowUps' => $this->activities($context['tenantId'], $context['employeeId'], null),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $employees
     * @param  array<int, array{overdue: int, today: int, upcoming: int}>  $byEmployee
     * @return list<array<string, mixed>>
     */
    public function applyMonitor(array $employees, array $byEmployee): array
    {
        foreach ($employees as $index => $employee) {
            $id = (int) $employee['id'];
            $employees[$index]['followUp'] = $byEmployee[$id] ?? ['overdue' => 0, 'today' => 0, 'upcoming' => 0];
        }

        return $employees;
    }

    /**
     * @return array<string, mixed>
     */
    public function employeeHistory(User $actor, int $employeeId): array
    {
        $context = $this->teamContext($actor);
        $person = DB::table('employees')->where('tenant_id', $context['tenantId'])->where('id', $employeeId)->first();
        $onTeam = $person !== null && (int) $person->id !== $context['employeeId'] && (
            (int) $person->manager_id === $context['employeeId']
            || Lead::query()->inCompany($context['tenantId'])->where('team_lead_employee_id', $context['employeeId'])->where('assignee_employee_id', $employeeId)->exists()
        );
        abort_if(! $onTeam, 404);

        $leads = Lead::query()
            ->inCompany($context['tenantId'])
            ->where('team_lead_employee_id', $context['employeeId'])
            ->where('assignee_employee_id', $employeeId)
            ->orderByDesc('id')
            ->get(['id', 'name', 'pipeline_status', 'follow_up_on']);
        $today = $this->today($context['tenantId']);

        return [
            'employee' => [
                'id' => $employeeId,
                'name' => $this->personName($person->first_name, $person->last_name),
            ],
            'leads' => $leads->map(fn (Lead $lead): array => [
                'id' => (int) $lead->id,
                'name' => (string) $lead->name,
                'statusLabel' => $this->label((string) $lead->pipeline_status),
                'followUpOn' => $this->date($lead->follow_up_on),
                'timing' => $this->timing($this->date($lead->follow_up_on), $today),
            ])->all(),
            'followUps' => $this->activities($context['tenantId'], $context['employeeId'], $employeeId),
        ];
    }

    /**
     * @return array{pipeline_status: string, follow_up_on: ?string, remarks: string}
     */
    public function storeForEmployee(User $actor, int $leadId, string $status, ?string $followUpOn, string $remarks): array
    {
        $context = $this->employeeContext($actor);

        return DB::transaction(function () use ($context, $leadId, $status, $followUpOn, $remarks): array {
            $lead = $this->assigned($context['tenantId'], $context['employeeId'])->whereKey($leadId)->lockForUpdate()->first();
            abort_if($lead === null, 404);

            return $this->write($context['tenantId'], $context['userId'], $lead, $status, $followUpOn, $remarks);
        });
    }

    /**
     * @return array{pipeline_status: string, follow_up_on: ?string, remarks: string}
     */
    public function storeForTeam(User $actor, int $leadId, string $status, ?string $followUpOn, string $remarks): array
    {
        $context = $this->teamContext($actor);

        return DB::transaction(function () use ($context, $leadId, $status, $followUpOn, $remarks): array {
            $lead = Lead::query()->inCompany($context['tenantId'])->whereKey($leadId)->lockForUpdate()->first();
            abort_if($lead === null || (int) $lead->team_lead_employee_id !== $context['employeeId'], 404);
            if ($lead->assignee_employee_id === null) {
                throw new LeadFollowUpRejected('Follow-up starts after this lead is retained or distributed.');
            }

            return $this->write($context['tenantId'], $context['userId'], $lead, $status, $followUpOn, $remarks);
        });
    }

    /**
     * @return array{pipeline_status: string, follow_up_on: ?string, remarks: string}
     */
    public function storeForCompany(User $actor, int $leadId, string $status, ?string $followUpOn, string $remarks): array
    {
        $tenantId = $this->companyContext($actor);

        return DB::transaction(function () use ($tenantId, $actor, $leadId, $status, $followUpOn, $remarks): array {
            $lead = Lead::query()->inCompany($tenantId)->whereKey($leadId)->lockForUpdate()->first();
            abort_if($lead === null, 404);

            return $this->write($tenantId, (int) $actor->id, $lead, $status, $followUpOn, $remarks);
        });
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function statuses(): array
    {
        $statuses = [];

        foreach (Lead::STATUSES as $value => $label) {
            $statuses[] = ['value' => $value, 'label' => $label];
        }

        return $statuses;
    }

    /**
     * @return array{pipeline_status: string, follow_up_on: ?string, remarks: string}
     */
    private function write(int $tenantId, int $userId, Lead $lead, string $status, ?string $followUpOn, string $remarks): array
    {
        if (! array_key_exists($status, Lead::STATUSES)) {
            throw new LeadFollowUpRejected('Choose a pipeline status.');
        }

        $teamLeadId = $lead->team_lead_employee_id;
        $assigneeId = $lead->assignee_employee_id;
        $now = now();

        Lead::query()->inCompany($tenantId)->whereKey($lead->id)->update([
            'pipeline_status' => $status,
            'follow_up_on' => $followUpOn,
            'remarks' => $remarks,
            'updated_by' => $userId,
            'updated_at' => $now,
        ]);

        DB::table('lead_activities')->insert([
            'tenant_id' => $tenantId,
            'lead_id' => (int) $lead->id,
            'actor_id' => $userId,
            'type' => 'follow_up',
            'pipeline_status' => $status,
            'follow_up_on' => $followUpOn,
            'body' => $remarks,
            'created_at' => $now,
        ]);

        $saved = Lead::query()->inCompany($tenantId)->whereKey($lead->id)->first();
        if ($saved === null || $saved->team_lead_employee_id != $teamLeadId || $saved->assignee_employee_id != $assigneeId) {
            throw new LeadFollowUpRejected('The follow-up could not be saved.');
        }

        return [
            'pipeline_status' => $status,
            'follow_up_on' => $followUpOn,
            'remarks' => $remarks,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(int $tenantId, Lead $lead, string $followUpUrl, string $backHref): array
    {
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
                'statusLabel' => $this->label((string) $lead->pipeline_status),
                'importedOn' => $this->date($lead->imported_on),
                'sourceRow' => $lead->source_row === null ? null : (int) $lead->source_row,
                'followUpOn' => $this->date($lead->follow_up_on),
                'remarks' => (string) ($lead->remarks ?? ''),
                'unassigned' => false,
            ],
            'batch' => null,
            'canAssign' => false,
            'canManage' => false,
            'canFollowUp' => true,
            'followUpUrl' => $followUpUrl,
            'statuses' => $this->statuses(),
            'backHref' => $backHref,
            'reports' => [],
            ...(new LeadDistribution)->timeline($tenantId, (int) $lead->id),
        ];
    }

    /**
     * @return array{tenantId: int, employeeId: int, userId: int}
     */
    private function employeeContext(User $actor): array
    {
        $role = AuthenticatedWorkspace::resolve($actor)['role'];
        $permissions = CompanyRole::permissions(is_string($role) ? $role : '');

        if ($role !== 'employee' || ($permissions['leadsFollowUpOwn'] ?? false) !== true || $actor->companyId() === null) {
            abort(403);
        }

        return $this->activeEmployee((int) $actor->companyId(), (int) $actor->id);
    }

    /**
     * @return array{tenantId: int, employeeId: int, userId: int}
     */
    private function teamContext(User $actor): array
    {
        $role = AuthenticatedWorkspace::resolve($actor)['role'];
        $permissions = CompanyRole::permissions(is_string($role) ? $role : '');

        if ($role !== 'team_lead' || ($permissions['leadsFollowUpTeam'] ?? false) !== true || $actor->companyId() === null) {
            abort(403);
        }

        return $this->activeEmployee((int) $actor->companyId(), (int) $actor->id);
    }

    private function companyContext(User $actor): int
    {
        $role = AuthenticatedWorkspace::resolve($actor)['role'];
        $permissions = CompanyRole::permissions(is_string($role) ? $role : '');

        if (($permissions['leadsViewAll'] ?? false) !== true || ($permissions['leadsFollowUpTeam'] ?? false) !== true || $actor->companyId() === null) {
            abort(403);
        }

        return (int) $actor->companyId();
    }

    /**
     * @return array{tenantId: int, employeeId: int, userId: int}
     */
    private function activeEmployee(int $tenantId, int $userId): array
    {
        $employeeId = DB::table('employees')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->orderBy('id')
            ->value('id');
        abort_if($employeeId === null, 403);

        return ['tenantId' => $tenantId, 'employeeId' => (int) $employeeId, 'userId' => $userId];
    }

    private function assignedLead(int $tenantId, int $employeeId, int $leadId): Lead
    {
        $lead = $this->assigned($tenantId, $employeeId)->whereKey($leadId)->first();
        abort_if($lead === null, 404);

        return $lead;
    }

    /**
     * @return Builder<Lead>
     */
    private function assigned(int $tenantId, int $employeeId): Builder
    {
        return Lead::query()->inCompany($tenantId)->where('leads.assignee_employee_id', $employeeId);
    }

    /**
     * @param  Builder<Lead>  $query
     * @return array{overdue: int, today: int, upcoming: int}
     */
    private function counts(Builder $query, string $today): array
    {
        return [
            'overdue' => (clone $query)->whereDate('leads.follow_up_on', '<', $today)->count(),
            'today' => (clone $query)->whereDate('leads.follow_up_on', $today)->count(),
            'upcoming' => (clone $query)->whereDate('leads.follow_up_on', '>', $today)->count(),
        ];
    }

    /**
     * @param  Builder<Lead>  $query
     * @return Builder<Lead>
     */
    private function queued(Builder $query, string $queue, string $today): Builder
    {
        if ($queue === 'overdue') {
            return $query->whereDate('leads.follow_up_on', '<', $today);
        }

        if ($queue === 'today') {
            return $query->whereDate('leads.follow_up_on', $today);
        }

        if ($queue === 'upcoming') {
            return $query->whereDate('leads.follow_up_on', '>', $today);
        }

        return $query;
    }

    /**
     * @return array<int, array{overdue: int, today: int, upcoming: int}>
     */
    private function byEmployee(int $tenantId, int $teamLeadId, string $today): array
    {
        $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $today) === 1 ? $today : '1970-01-01';
        $next = (new \DateTimeImmutable($day))->modify('+1 day')->format('Y-m-d');
        $rows = DB::table('leads')
            ->where('tenant_id', $tenantId)
            ->where('team_lead_employee_id', $teamLeadId)
            ->whereNotNull('assignee_employee_id')
            ->where('assignee_employee_id', '!=', $teamLeadId)
            ->whereNotNull('follow_up_on')
            ->groupBy('assignee_employee_id')
            ->selectRaw("
                assignee_employee_id,
                SUM(CASE WHEN follow_up_on < '{$day}' THEN 1 ELSE 0 END) as overdue,
                SUM(CASE WHEN follow_up_on >= '{$day}' AND follow_up_on < '{$next}' THEN 1 ELSE 0 END) as today,
                SUM(CASE WHEN follow_up_on >= '{$next}' THEN 1 ELSE 0 END) as upcoming
            ")
            ->get();
        $grouped = [];

        foreach ($rows as $row) {
            $grouped[(int) $row->assignee_employee_id] = [
                'overdue' => (int) $row->overdue,
                'today' => (int) $row->today,
                'upcoming' => (int) $row->upcoming,
            ];
        }

        return $grouped;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function activities(int $tenantId, ?int $teamLeadId, ?int $assigneeId): array
    {
        $query = DB::table('lead_activities')
            ->join('leads', function ($join) use ($tenantId, $teamLeadId, $assigneeId): void {
                $join->on('leads.id', '=', 'lead_activities.lead_id')->where('leads.tenant_id', '=', $tenantId);
                if ($teamLeadId !== null) {
                    $join->where('leads.team_lead_employee_id', '=', $teamLeadId);
                }
                if ($assigneeId !== null) {
                    $join->where('leads.assignee_employee_id', '=', $assigneeId);
                }
            })
            ->leftJoin('employees as activity_assignees', function ($join) use ($tenantId): void {
                $join->on('activity_assignees.id', '=', 'leads.assignee_employee_id')->where('activity_assignees.tenant_id', '=', $tenantId);
            })
            ->leftJoin('users as activity_actors', function ($join) use ($tenantId): void {
                $join->on('activity_actors.id', '=', 'lead_activities.actor_id')->where('activity_actors.tenant_id', '=', $tenantId);
            })
            ->where('lead_activities.tenant_id', $tenantId)
            ->orderByDesc('lead_activities.id')
            ->limit(100);

        $history = [];

        foreach ($query->get([
            'lead_activities.id',
            'lead_activities.lead_id',
            'lead_activities.pipeline_status',
            'lead_activities.follow_up_on',
            'lead_activities.body',
            'lead_activities.created_at',
            'leads.name as lead_name',
            'activity_assignees.first_name',
            'activity_assignees.last_name',
            'activity_actors.name as actor_name',
        ]) as $row) {
            $history[] = [
                'id' => (int) $row->id,
                'leadId' => (int) $row->lead_id,
                'leadName' => (string) $row->lead_name,
                'assigneeName' => $this->personName($row->first_name, $row->last_name),
                'body' => (string) ($row->body ?? ''),
                'statusLabel' => $this->label((string) ($row->pipeline_status ?? '')),
                'followUpOn' => $this->date($row->follow_up_on),
                'actor' => trim((string) ($row->actor_name ?? '')),
                'at' => $row->created_at === null ? '' : (string) $row->created_at,
            ];
        }

        return $history;
    }

    private function queue(string $queue): string
    {
        return in_array($queue, ['all', 'overdue', 'today', 'upcoming'], true) ? $queue : 'all';
    }

    private function today(int $tenantId): string
    {
        $timezone = DB::table('tenants')->where('id', $tenantId)->value('timezone');
        $zone = is_string($timezone) && $timezone !== '' ? $timezone : 'Asia/Kolkata';

        return now()->timezone($zone)->toDateString();
    }

    private function timing(?string $date, string $today): string
    {
        if ($date === null || $date === '') {
            return '';
        }

        if ($date < $today) {
            return 'overdue';
        }

        return $date === $today ? 'today' : 'upcoming';
    }

    private function label(string $status): string
    {
        return Lead::STATUSES[$status] ?? $status;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function employeeNames(int $tenantId, array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));

        if ($ids === []) {
            return [];
        }

        $names = [];

        foreach (DB::table('employees')->where('tenant_id', $tenantId)->whereIn('id', $ids)->get(['id', 'first_name', 'last_name']) as $row) {
            $names[(int) $row->id] = $this->personName($row->first_name, $row->last_name);
        }

        return $names;
    }

    /**
     * @param  array<int, string>  $names
     */
    private function named(array $names, mixed $id): string
    {
        if ($id === null) {
            return '';
        }

        return (string) ($names[(int) $id] ?? '');
    }

    private function personName(mixed $first, mixed $last): string
    {
        $name = trim((string) $first.' '.(string) $last);

        return $name !== '' ? $name : 'Employee';
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
