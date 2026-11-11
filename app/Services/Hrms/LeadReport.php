<?php

namespace App\Services\Hrms;

use App\Auth\CompanyRole;
use App\Models\Lead;
use App\Models\User;
use App\Support\AuthenticatedWorkspace;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class LeadReport
{
    /**
     * @param  array{from: ?string, to: ?string}  $input
     * @return array<string, mixed>
     */
    public function company(User $actor, array $input): array
    {
        $tenantId = $this->companyContext($actor);
        $range = $this->range($tenantId, $input);
        $today = $this->today($tenantId);
        $leads = $this->leads($tenantId, $range);
        $tally = $this->tally(clone $leads, $today);

        return [
            'filters' => $this->filters($range),
            'summary' => [
                'total' => $tally['total'],
                'unassigned' => $tally['unassigned'],
                'assignedToTeamLeads' => $tally['assigned_to_team_leads'],
                'assignedToEmployees' => $tally['assigned_to_employees'],
                'overdue' => $tally['overdue'],
                'dueToday' => $tally['due_today'],
                'activities' => $this->activityTotal($tenantId, $range),
            ],
            'pipeline' => $this->pipeline(clone $leads),
            'teamLeads' => $this->companyTeamLeads($tenantId, clone $leads, $range, $today),
            'employees' => $this->companyEmployees($tenantId, clone $leads, $range, $today),
            'states' => $this->breakdown(clone $leads, 'state'),
            'universities' => $this->breakdown(clone $leads, 'university'),
            'graduationTerms' => $this->breakdown(clone $leads, 'graduation_term'),
            'designations' => $this->breakdown(clone $leads, 'designations'),
        ];
    }

    public function authorizeCompany(User $actor): void
    {
        $this->companyContext($actor);
    }

    /**
     * @param  array{from: ?string, to: ?string}  $input
     * @param  resource  $output
     */
    public function writeCsv(User $actor, array $input, $output): void
    {
        $tenantId = $this->companyContext($actor);
        $range = $this->range($tenantId, $input);
        $zone = $this->zone($tenantId);
        fputcsv($output, ['Name', 'Email', 'Phone', 'State', 'University', 'Graduation term', 'Designation', 'Experience', 'Pipeline status', 'Follow-up', 'Team lead', 'Assignee', 'Created']);

        $query = $this->leads($tenantId, $range)
            ->leftJoin('employees as report_team_leads', function ($join) use ($tenantId): void {
                $join->on('report_team_leads.id', '=', 'leads.team_lead_employee_id')->where('report_team_leads.tenant_id', '=', $tenantId);
            })
            ->leftJoin('employees as report_assignees', function ($join) use ($tenantId): void {
                $join->on('report_assignees.id', '=', 'leads.assignee_employee_id')->where('report_assignees.tenant_id', '=', $tenantId);
            })
            ->select([
                'leads.id',
                'leads.name',
                'leads.email',
                'leads.phone',
                'leads.state',
                'leads.university',
                'leads.graduation_term',
                'leads.designations',
                'leads.experience',
                'leads.pipeline_status',
                'leads.follow_up_on',
                'leads.created_at',
                'report_team_leads.first_name as team_first',
                'report_team_leads.last_name as team_last',
                'report_assignees.first_name as assignee_first',
                'report_assignees.last_name as assignee_last',
            ])
            ->orderBy('leads.id');

        foreach ($query->lazyById(200, 'leads.id', 'id') as $lead) {
            fputcsv($output, [
                $this->csvCell($lead->name),
                $this->csvCell($lead->email),
                $this->csvCell($lead->phone),
                $this->csvCell($lead->state),
                $this->csvCell($lead->university),
                $this->csvCell($lead->graduation_term),
                $this->csvCell($lead->designations),
                $this->csvCell($lead->experience),
                $this->csvCell(Lead::STATUSES[(string) $lead->pipeline_status] ?? (string) $lead->pipeline_status),
                $this->csvCell($lead->follow_up_on instanceof \DateTimeInterface ? $lead->follow_up_on->format('Y-m-d') : ''),
                $this->csvCell($this->personName($lead->getAttribute('team_first'), $lead->getAttribute('team_last'))),
                $this->csvCell($this->personName($lead->getAttribute('assignee_first'), $lead->getAttribute('assignee_last'))),
                $this->csvCell($lead->created_at instanceof \DateTimeInterface ? Carbon::parse($lead->created_at)->timezone($zone)->format('Y-m-d H:i') : ''),
            ]);
        }
    }

    /**
     * @param  array{from: ?string, to: ?string}  $input
     */
    public function filename(array $input): string
    {
        $from = $input['from'] ?? '';
        $to = $input['to'] ?? '';

        if (($from === null || $from === '') && ($to === null || $to === '')) {
            return 'lead-report.csv';
        }

        return 'lead-report-'.($from !== null && $from !== '' ? $from : 'start').'-'.($to !== null && $to !== '' ? $to : 'end').'.csv';
    }

    /**
     * @param  array{from: ?string, to: ?string}  $input
     * @return array<string, mixed>
     */
    public function team(User $actor, array $input): array
    {
        $context = $this->teamContext($actor);
        $range = $this->range($context['tenantId'], $input);
        $today = $this->today($context['tenantId']);
        $leads = $this->leads($context['tenantId'], $range)->where('leads.team_lead_employee_id', $context['employeeId']);
        $tally = $this->tally(clone $leads, $today);

        return [
            'filters' => $this->filters($range),
            'summary' => [
                'total' => $tally['total'],
                'retained' => $tally['retained'],
                'distributed' => $tally['assigned_to_employees'],
                'undistributed' => $tally['undistributed'],
                'converted' => $tally['converted'],
                'overdue' => $tally['overdue'],
                'dueToday' => $tally['due_today'],
                'activities' => $this->activityTotal($context['tenantId'], $range, $context['employeeId']),
            ],
            'employees' => $this->teamEmployees($context['tenantId'], $context['employeeId'], clone $leads, $range, $today),
        ];
    }

    /**
     * @param  array{from: ?string, to: ?string}  $input
     * @return array<string, mixed>
     */
    public function employee(User $actor, array $input): array
    {
        $context = $this->employeeContext($actor);
        $range = $this->range($context['tenantId'], $input);
        $today = $this->today($context['tenantId']);
        $leads = $this->leads($context['tenantId'], $range)->where('leads.assignee_employee_id', $context['employeeId']);
        $tally = $this->tally(clone $leads, $today);
        $activity = $this->personalActivity($context['tenantId'], $range, $context['employeeId'], $context['userId']);

        return [
            'filters' => $this->filters($range),
            'summary' => [
                'total' => $tally['total'],
                'converted' => $tally['converted'],
                'pending' => $tally['total'] - $tally['converted'],
                'overdue' => $tally['overdue'],
                'dueToday' => $tally['due_today'],
                'upcoming' => $tally['upcoming'],
                'activities' => $activity['total'],
                'latestActivity' => $activity['latest'],
            ],
            'pipeline' => $this->pipeline(clone $leads),
            'activityByStatus' => $activity['statuses'],
        ];
    }

    /**
     * @param  array{from: ?string, to: ?string}  $input
     * @return array{from: string, to: string, start: ?string, end: ?string}
     */
    private function range(int $tenantId, array $input): array
    {
        $from = is_string($input['from'] ?? null) && $input['from'] !== '' ? $input['from'] : '';
        $to = is_string($input['to'] ?? null) && $input['to'] !== '' ? $input['to'] : '';
        $zone = $this->zone($tenantId);

        return [
            'from' => $from,
            'to' => $to,
            'start' => $from === '' ? null : Carbon::parse($from, $zone)->startOfDay()->utc()->toDateTimeString(),
            'end' => $to === '' ? null : Carbon::parse($to, $zone)->endOfDay()->utc()->toDateTimeString(),
        ];
    }

    /**
     * @param  array{from: string, to: string, start: ?string, end: ?string}  $range
     * @return array{from: string, to: string}
     */
    private function filters(array $range): array
    {
        return ['from' => $range['from'], 'to' => $range['to']];
    }

    /**
     * @param  array{from: string, to: string, start: ?string, end: ?string}  $range
     * @return Builder<Lead>
     */
    private function leads(int $tenantId, array $range): Builder
    {
        $query = Lead::query()->inCompany($tenantId);

        if ($range['start'] !== null) {
            $query->where('leads.created_at', '>=', $range['start']);
        }

        if ($range['end'] !== null) {
            $query->where('leads.created_at', '<=', $range['end']);
        }

        return $query;
    }

    /**
     * @param  Builder<Lead>  $query
     * @return array{total: int, unassigned: int, assigned_to_team_leads: int, assigned_to_employees: int, retained: int, undistributed: int, converted: int, overdue: int, due_today: int, upcoming: int}
     */
    private function tally(Builder $query, string $today): array
    {
        $row = $query->toBase()->selectRaw("
            COUNT(*) as total,
            SUM(CASE WHEN leads.team_lead_employee_id IS NULL AND leads.assignee_employee_id IS NULL THEN 1 ELSE 0 END) as unassigned,
            SUM(CASE WHEN leads.team_lead_employee_id IS NOT NULL THEN 1 ELSE 0 END) as assigned_to_team_leads,
            SUM(CASE WHEN leads.assignee_employee_id IS NOT NULL AND (leads.team_lead_employee_id IS NULL OR leads.assignee_employee_id != leads.team_lead_employee_id) THEN 1 ELSE 0 END) as assigned_to_employees,
            SUM(CASE WHEN leads.assignee_employee_id IS NOT NULL AND leads.assignee_employee_id = leads.team_lead_employee_id THEN 1 ELSE 0 END) as retained,
            SUM(CASE WHEN leads.team_lead_employee_id IS NOT NULL AND leads.assignee_employee_id IS NULL THEN 1 ELSE 0 END) as undistributed,
            SUM(CASE WHEN leads.pipeline_status = 'converted' THEN 1 ELSE 0 END) as converted,
            {$this->followWindow($today)}
        ")->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'unassigned' => (int) ($row->unassigned ?? 0),
            'assigned_to_team_leads' => (int) ($row->assigned_to_team_leads ?? 0),
            'assigned_to_employees' => (int) ($row->assigned_to_employees ?? 0),
            'retained' => (int) ($row->retained ?? 0),
            'undistributed' => (int) ($row->undistributed ?? 0),
            'converted' => (int) ($row->converted ?? 0),
            'overdue' => (int) ($row->overdue ?? 0),
            'due_today' => (int) ($row->due_today ?? 0),
            'upcoming' => (int) ($row->upcoming ?? 0),
        ];
    }

    /**
     * @param  Builder<Lead>  $query
     * @return list<array{value: string, label: string, count: int}>
     */
    private function pipeline(Builder $query): array
    {
        $counts = $query->toBase()
            ->select('leads.pipeline_status')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('leads.pipeline_status')
            ->pluck('total', 'pipeline_status');
        $rows = [];

        foreach (Lead::STATUSES as $value => $label) {
            $rows[] = ['value' => $value, 'label' => $label, 'count' => (int) ($counts[$value] ?? 0)];
        }

        return $rows;
    }

    /**
     * @param  Builder<Lead>  $query
     * @return list<array{label: string, total: int, converted: int}>
     */
    private function breakdown(Builder $query, string $column): array
    {
        $expression = match ($column) {
            'state' => "COALESCE(NULLIF(leads.state, ''), 'Not set')",
            'university' => "COALESCE(NULLIF(leads.university, ''), 'Not set')",
            'graduation_term' => "COALESCE(NULLIF(leads.graduation_term, ''), 'Not set')",
            'designations' => "COALESCE(NULLIF(leads.designations, ''), 'Not set')",
            default => throw new \InvalidArgumentException('Unknown report column.'),
        };

        return $query->toBase()
            ->selectRaw("{$expression} as label, COUNT(*) as total, SUM(CASE WHEN leads.pipeline_status = 'converted' THEN 1 ELSE 0 END) as converted")
            ->groupByRaw($expression)
            ->orderByRaw('total DESC')
            ->orderByRaw("{$expression} ASC")
            ->get()
            ->map(fn ($row): array => [
                'label' => (string) $row->label,
                'total' => (int) $row->total,
                'converted' => (int) $row->converted,
            ])->all();
    }

    /**
     * @param  Builder<Lead>  $leads
     * @param  array{from: string, to: string, start: ?string, end: ?string}  $range
     * @return list<array<string, mixed>>
     */
    private function companyTeamLeads(int $tenantId, Builder $leads, array $range, string $today): array
    {
        $rows = $leads->toBase()
            ->whereNotNull('leads.team_lead_employee_id')
            ->selectRaw("
                leads.team_lead_employee_id as team_lead_id,
                COUNT(*) as total,
                SUM(CASE WHEN leads.assignee_employee_id IS NULL THEN 1 ELSE 0 END) as undistributed,
                SUM(CASE WHEN leads.assignee_employee_id IS NOT NULL AND leads.assignee_employee_id = leads.team_lead_employee_id THEN 1 ELSE 0 END) as retained,
                SUM(CASE WHEN leads.assignee_employee_id IS NOT NULL AND leads.assignee_employee_id != leads.team_lead_employee_id THEN 1 ELSE 0 END) as distributed,
                SUM(CASE WHEN leads.pipeline_status = 'converted' THEN 1 ELSE 0 END) as converted,
                {$this->followWindow($today)}
            ")
            ->groupBy('leads.team_lead_employee_id')
            ->get();
        $activities = $this->activityByTeamLead($tenantId, $range);
        $names = $this->names($tenantId, $rows->pluck('team_lead_id')->map(fn ($id): int => (int) $id)->all());
        $report = [];

        foreach ($rows as $row) {
            $id = (int) $row->team_lead_id;
            $total = (int) $row->total;
            $converted = (int) $row->converted;
            $report[] = [
                'id' => $id,
                'name' => $names[$id] ?? 'Employee',
                'total' => $total,
                'undistributed' => (int) $row->undistributed,
                'retained' => (int) $row->retained,
                'distributed' => (int) $row->distributed,
                'converted' => $converted,
                'conversionRate' => $this->rate($converted, $total),
                'overdue' => (int) $row->overdue,
                'dueToday' => (int) $row->due_today,
                'activities' => $activities[$id] ?? 0,
            ];
        }

        usort($report, fn (array $left, array $right): int => $right['total'] <=> $left['total'] ?: strcmp($left['name'], $right['name']));

        return $report;
    }

    /**
     * @param  Builder<Lead>  $leads
     * @param  array{from: string, to: string, start: ?string, end: ?string}  $range
     * @return list<array<string, mixed>>
     */
    private function companyEmployees(int $tenantId, Builder $leads, array $range, string $today): array
    {
        $rows = $leads->toBase()
            ->whereNotNull('leads.assignee_employee_id')
            ->selectRaw("
                leads.assignee_employee_id as assignee_id,
                leads.team_lead_employee_id as team_lead_id,
                COUNT(*) as total,
                SUM(CASE WHEN leads.pipeline_status = 'converted' THEN 1 ELSE 0 END) as converted,
                {$this->followWindow($today)}
            ")
            ->groupBy('leads.assignee_employee_id', 'leads.team_lead_employee_id')
            ->get();
        $activities = $this->activityByAssignee($tenantId, $range, null);
        $ids = [];

        foreach ($rows as $row) {
            $ids[] = (int) $row->assignee_id;
            if ($row->team_lead_id !== null) {
                $ids[] = (int) $row->team_lead_id;
            }
        }

        $names = $this->names($tenantId, $ids);
        $report = [];

        foreach ($rows as $row) {
            $assigneeId = (int) $row->assignee_id;
            $teamLeadId = $row->team_lead_id === null ? 0 : (int) $row->team_lead_id;
            $total = (int) $row->total;
            $converted = (int) $row->converted;
            $report[] = [
                'id' => $assigneeId,
                'name' => $names[$assigneeId] ?? 'Employee',
                'teamLead' => $teamLeadId === 0 ? '' : ($names[$teamLeadId] ?? 'Employee'),
                'leads' => $total,
                'converted' => $converted,
                'conversionRate' => $this->rate($converted, $total),
                'overdue' => (int) $row->overdue,
                'dueToday' => (int) $row->due_today,
                'activities' => $activities[$assigneeId.':'.$teamLeadId] ?? 0,
            ];
        }

        usort($report, fn (array $left, array $right): int => $right['leads'] <=> $left['leads'] ?: strcmp($left['name'], $right['name']));

        return $report;
    }

    /**
     * @param  Builder<Lead>  $leads
     * @param  array{from: string, to: string, start: ?string, end: ?string}  $range
     * @return list<array<string, mixed>>
     */
    private function teamEmployees(int $tenantId, int $teamLeadId, Builder $leads, array $range, string $today): array
    {
        $rows = (clone $leads)->toBase()
            ->whereNotNull('leads.assignee_employee_id')
            ->where('leads.assignee_employee_id', '!=', $teamLeadId)
            ->selectRaw("
                leads.assignee_employee_id as assignee_id,
                COUNT(*) as total,
                SUM(CASE WHEN leads.pipeline_status = 'converted' THEN 1 ELSE 0 END) as converted,
                {$this->followWindow($today)}
            ")
            ->groupBy('leads.assignee_employee_id')
            ->get()
            ->keyBy(fn ($row): int => (int) $row->assignee_id);
        $statuses = [];

        foreach ((clone $leads)->toBase()
            ->whereNotNull('leads.assignee_employee_id')
            ->where('leads.assignee_employee_id', '!=', $teamLeadId)
            ->selectRaw('leads.assignee_employee_id as assignee_id, leads.pipeline_status, COUNT(*) as total')
            ->groupBy('leads.assignee_employee_id', 'leads.pipeline_status')
            ->get() as $row) {
            $statuses[(int) $row->assignee_id][(string) $row->pipeline_status] = (int) $row->total;
        }

        $activities = $this->activityByAssignee($tenantId, $range, $teamLeadId);
        $people = [];

        foreach (DB::table('employees')->where('tenant_id', $tenantId)->where('manager_id', $teamLeadId)->where('id', '!=', $teamLeadId)->get(['id']) as $person) {
            $people[(int) $person->id] = true;
        }

        foreach ($rows as $id => $row) {
            $people[(int) $id] = true;
        }

        $names = $this->names($tenantId, array_keys($people));
        $report = [];

        foreach (array_keys($people) as $id) {
            $row = $rows->get($id);
            $total = $row === null ? 0 : (int) $row->total;
            $converted = $row === null ? 0 : (int) $row->converted;
            $report[] = [
                'id' => $id,
                'name' => $names[$id] ?? 'Employee',
                'leads' => $total,
                'statuses' => $this->statusList($statuses[$id] ?? []),
                'converted' => $converted,
                'conversionRate' => $this->rate($converted, $total),
                'overdue' => $row === null ? 0 : (int) $row->overdue,
                'dueToday' => $row === null ? 0 : (int) $row->due_today,
                'activities' => $activities[$id.':'.$teamLeadId] ?? 0,
            ];
        }

        usort($report, fn (array $left, array $right): int => $right['leads'] <=> $left['leads'] ?: strcmp($left['name'], $right['name']));

        return $report;
    }

    /**
     * @param  array{from: string, to: string, start: ?string, end: ?string}  $range
     * @return array<int, int>
     */
    private function activityByTeamLead(int $tenantId, array $range): array
    {
        $query = $this->activities($tenantId, $range, null)
            ->whereNotNull('leads.team_lead_employee_id')
            ->selectRaw('leads.team_lead_employee_id as group_id, COUNT(*) as total')
            ->groupBy('leads.team_lead_employee_id');
        $counts = [];

        foreach ($query->get() as $row) {
            $counts[(int) $row->group_id] = (int) $row->total;
        }

        return $counts;
    }

    /**
     * @param  array{from: string, to: string, start: ?string, end: ?string}  $range
     * @return array<string, int>
     */
    private function activityByAssignee(int $tenantId, array $range, ?int $teamLeadId): array
    {
        $query = $this->activities($tenantId, $range, $teamLeadId)
            ->whereNotNull('leads.assignee_employee_id')
            ->selectRaw('leads.assignee_employee_id as assignee_id, leads.team_lead_employee_id as team_lead_id, COUNT(*) as total')
            ->groupBy('leads.assignee_employee_id', 'leads.team_lead_employee_id');
        $counts = [];

        foreach ($query->get() as $row) {
            $teamLead = $row->team_lead_id === null ? 0 : (int) $row->team_lead_id;
            $counts[(int) $row->assignee_id.':'.$teamLead] = (int) $row->total;
        }

        return $counts;
    }

    /**
     * @param  array{from: string, to: string, start: ?string, end: ?string}  $range
     */
    private function activityTotal(int $tenantId, array $range, ?int $teamLeadId = null, ?int $assigneeId = null, ?int $actorId = null): int
    {
        $query = $this->activities($tenantId, $range, $teamLeadId, $assigneeId);

        if ($actorId !== null) {
            $query->where('lead_activities.actor_id', $actorId);
        }

        return (int) $query->count();
    }

    /**
     * @param  array{from: string, to: string, start: ?string, end: ?string}  $range
     * @return array{total: int, latest: string, statuses: list<array{label: string, count: int}>}
     */
    private function personalActivity(int $tenantId, array $range, int $assigneeId, int $actorId): array
    {
        $query = $this->activities($tenantId, $range, null, $assigneeId)->where('lead_activities.actor_id', $actorId);
        $summary = (clone $query)->selectRaw('COUNT(*) as total, MAX(lead_activities.created_at) as latest')->first();
        $counts = [];

        foreach ((clone $query)->selectRaw('lead_activities.pipeline_status as status, COUNT(*) as total')->groupBy('lead_activities.pipeline_status')->get() as $row) {
            $counts[(string) $row->status] = (int) $row->total;
        }

        $latest = '';

        if ($summary !== null && $summary->latest !== null && $summary->latest !== '') {
            $latest = Carbon::parse((string) $summary->latest)->timezone($this->zone($tenantId))->format('Y-m-d H:i');
        }

        return [
            'total' => (int) ($summary->total ?? 0),
            'latest' => $latest,
            'statuses' => $this->statusList($counts),
        ];
    }

    /**
     * @param  array{from: string, to: string, start: ?string, end: ?string}  $range
     */
    private function activities(int $tenantId, array $range, ?int $teamLeadId, ?int $assigneeId = null): \Illuminate\Database\Query\Builder
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
            ->where('lead_activities.tenant_id', $tenantId)
            ->where('lead_activities.type', 'follow_up');

        if ($range['start'] !== null) {
            $query->where('lead_activities.created_at', '>=', $range['start']);
        }

        if ($range['end'] !== null) {
            $query->where('lead_activities.created_at', '<=', $range['end']);
        }

        return $query;
    }

    /**
     * @param  array<string, int>  $counts
     * @return list<array{label: string, count: int}>
     */
    private function statusList(array $counts): array
    {
        $rows = [];

        foreach (Lead::STATUSES as $value => $label) {
            $count = (int) ($counts[$value] ?? 0);

            if ($count > 0) {
                $rows[] = ['label' => $label, 'count' => $count];
            }
        }

        return $rows;
    }

    private function rate(int $converted, int $total): int
    {
        if ($total === 0) {
            return 0;
        }

        return (int) round(($converted * 100) / $total);
    }

    private function companyContext(User $actor): int
    {
        $permissions = $this->permissions($actor);

        if (($permissions['leadsViewAll'] ?? false) !== true || ($permissions['leadsReport'] ?? false) !== true || $actor->companyId() === null) {
            abort(403);
        }

        return (int) $actor->companyId();
    }

    /**
     * @return array{tenantId: int, employeeId: int, userId: int}
     */
    private function teamContext(User $actor): array
    {
        $role = AuthenticatedWorkspace::resolve($actor)['role'];
        $permissions = $this->permissions($actor);

        if ($role !== 'team_lead' || ($permissions['leadsReport'] ?? false) !== true || $actor->companyId() === null) {
            abort(403);
        }

        return $this->activeEmployee((int) $actor->companyId(), (int) $actor->id);
    }

    /**
     * @return array{tenantId: int, employeeId: int, userId: int}
     */
    private function employeeContext(User $actor): array
    {
        $role = AuthenticatedWorkspace::resolve($actor)['role'];
        $permissions = $this->permissions($actor);

        if ($role !== 'employee' || ($permissions['leadsFollowUpOwn'] ?? false) !== true || $actor->companyId() === null) {
            abort(403);
        }

        return $this->activeEmployee((int) $actor->companyId(), (int) $actor->id);
    }

    /**
     * @return array<string, bool>
     */
    private function permissions(User $actor): array
    {
        $role = AuthenticatedWorkspace::resolve($actor)['role'];

        return CompanyRole::permissions(is_string($role) ? $role : '');
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

    /**
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function names(int $tenantId, array $ids): array
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

    private function csvCell(mixed $value): string
    {
        $text = (string) ($value ?? '');

        if ($text !== '' && preg_match('/^\s*[=+\-@]/', $text) === 1) {
            return "'".$text;
        }

        return $text;
    }

    private function personName(mixed $first, mixed $last): string
    {
        $name = trim((string) $first.' '.(string) $last);

        return $name !== '' ? $name : '';
    }

    private function today(int $tenantId): string
    {
        return now()->timezone($this->zone($tenantId))->toDateString();
    }

    private function zone(int $tenantId): string
    {
        $timezone = DB::table('tenants')->where('id', $tenantId)->value('timezone');

        return is_string($timezone) && $timezone !== '' ? $timezone : 'Asia/Kolkata';
    }

    private function day(string $today): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $today) === 1 ? $today : '1970-01-01';
    }

    private function followWindow(string $today): string
    {
        $day = $this->day($today);
        $next = $this->day(Carbon::parse($day)->addDay()->toDateString());

        return "SUM(CASE WHEN leads.follow_up_on IS NOT NULL AND leads.follow_up_on < '{$day}' THEN 1 ELSE 0 END) as overdue,
            SUM(CASE WHEN leads.follow_up_on IS NOT NULL AND leads.follow_up_on >= '{$day}' AND leads.follow_up_on < '{$next}' THEN 1 ELSE 0 END) as due_today,
            SUM(CASE WHEN leads.follow_up_on IS NOT NULL AND leads.follow_up_on >= '{$next}' THEN 1 ELSE 0 END) as upcoming";
    }
}
