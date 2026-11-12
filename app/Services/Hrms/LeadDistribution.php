<?php

namespace App\Services\Hrms;

use App\Auth\CompanyRole;
use App\Models\Lead;
use App\Models\User;
use App\Support\AuthenticatedWorkspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LeadDistribution
{
    public const PAGE_SIZE = 25;

    public const LIMIT = 5000;

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function page(User $actor, array $input): array
    {
        $context = $this->context($actor);
        $section = $this->section((string) ($input['section'] ?? 'pending'));
        $query = $this->sectionQuery($context['tenantId'], $context['employeeId'], $section);
        $matching = (clone $query)->count();
        $pages = max(1, (int) ceil($matching / self::PAGE_SIZE));
        $page = min(max(1, (int) ($input['page'] ?? 1)), $pages);
        $today = $this->today($context['tenantId']);
        $rows = (clone $query)->orderByDesc('leads.id')->forPage($page, self::PAGE_SIZE)->get([
            'leads.id',
            'leads.name',
            'leads.email',
            'leads.phone',
            'leads.state',
            'leads.pipeline_status',
            'leads.follow_up_on',
            'leads.remarks',
            'leads.assignee_employee_id',
        ]);
        $names = $this->employeeNames($context['tenantId'], $rows->pluck('assignee_employee_id')->filter()->map(fn ($id): int => (int) $id)->all());
        $summary = $this->summary($context['tenantId'], $context['employeeId']);

        return [
            'summary' => $summary,
            'section' => $section,
            'myPipeline' => $this->pipelineCounts($context['tenantId'], $context['employeeId'], 'retained'),
            'employees' => $this->employeeSummary($context['tenantId'], $context['employeeId']),
            'reports' => $this->reports($context['tenantId'], $context['employeeId']),
            'leads' => $rows->map(fn (Lead $lead): array => $this->row($lead, $names, $today, $section))->all(),
            'matching' => $matching,
            'page' => $page,
            'pages' => $pages,
            'selectedIds' => $this->selectedIds($context, $section, $input),
            'statuses' => $this->statuses(),
        ];
    }

    /**
     * Dashboard board for a Team Lead. My totals are retained leads only.
     * Team totals are retained + distributed + not-yet-distributed for this Team Lead only.
     *
     * @return array{
     *     myTotal: int,
     *     teamTotal: int,
     *     pending: int,
     *     distributed: int,
     *     myPipeline: list<array{value: string, label: string, count: int}>,
     *     myLeads: list<array<string, mixed>>,
     *     employees: list<array{id: int, name: string, active: bool, total: int, statuses: list<array{label: string, count: int}>}>,
     *     statuses: list<array{value: string, label: string}>
     * }
     */
    public function board(User $actor): array
    {
        $context = $this->context($actor);
        $summary = $this->summary($context['tenantId'], $context['employeeId']);
        $today = $this->today($context['tenantId']);
        $mine = $this->sectionQuery($context['tenantId'], $context['employeeId'], 'retained')
            ->orderByDesc('leads.id')
            ->limit(8)
            ->get([
                'leads.id',
                'leads.name',
                'leads.email',
                'leads.phone',
                'leads.state',
                'leads.pipeline_status',
                'leads.follow_up_on',
                'leads.remarks',
                'leads.assignee_employee_id',
            ]);
        $names = $this->employeeNames($context['tenantId'], [$context['employeeId']]);

        return [
            'myTotal' => $summary['myTotal'],
            'teamTotal' => $summary['teamTotal'],
            'pending' => $summary['pending'],
            'distributed' => $summary['distributed'],
            'myPipeline' => $this->pipelineCounts($context['tenantId'], $context['employeeId'], 'retained'),
            'myLeads' => $mine->map(fn (Lead $lead): array => $this->row($lead, $names, $today, 'retained'))->all(),
            'employees' => $this->employeeSummary($context['tenantId'], $context['employeeId']),
            'statuses' => $this->statuses(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function record(User $actor, int $leadId): array
    {
        $context = $this->context($actor);
        $lead = Lead::query()->inCompany($context['tenantId'])->whereKey($leadId)->first();
        abort_if($lead === null || (int) $lead->team_lead_employee_id !== $context['employeeId'], 404);

        return [
            'lead' => $this->leadPayload($lead),
            'batch' => $this->batch($context['tenantId'], $lead->import_batch_id === null ? null : (int) $lead->import_batch_id),
            'canAssign' => false,
            'canManage' => true,
            'backHref' => '/my-leads',
            'reports' => $this->reports($context['tenantId'], $context['employeeId']),
            ...$this->timeline($context['tenantId'], $leadId),
        ];
    }

    /**
     * @return array{placement: array<string, mixed>|null, assignments: list<array<string, mixed>>, followUps: list<array<string, mixed>>}
     */
    public function timeline(int $tenantId, int $leadId): array
    {
        $lead = Lead::query()->inCompany($tenantId)->whereKey($leadId)->first();

        if ($lead === null) {
            return ['placement' => null, 'assignments' => [], 'followUps' => []];
        }

        return [
            'placement' => $this->placement($tenantId, $lead),
            'assignments' => $this->assignmentHistory($tenantId, $leadId),
            'followUps' => $this->followUps($tenantId, $leadId),
        ];
    }

    /**
     * @param  list<int|string>  $ids
     * @return array<string, mixed>
     */
    public function reviewRetain(User $actor, array $ids): array
    {
        return $this->review($actor, $ids, 'retain', null);
    }

    /**
     * @param  list<int|string>  $ids
     * @return array<string, mixed>
     */
    public function reviewDistribute(User $actor, array $ids, int $employeeId): array
    {
        $context = $this->context($actor);

        return $this->review($actor, $ids, 'distribute', $this->requireReport($context, $employeeId));
    }

    /**
     * @param  list<int|string>  $ids
     * @return array<string, mixed>
     */
    public function reviewRecall(User $actor, array $ids): array
    {
        return $this->review($actor, $ids, 'recall', null);
    }

    /**
     * @param  list<int|string>  $ids
     * @return array{count: int, message: string}
     */
    public function retain(User $actor, array $ids): array
    {
        return $this->commit($actor, $ids, 'retain', null);
    }

    /**
     * @param  list<int|string>  $ids
     * @return array{count: int, message: string}
     */
    public function distribute(User $actor, array $ids, int $employeeId): array
    {
        $context = $this->context($actor);
        $report = $this->requireReport($context, $employeeId);

        return $this->commit($actor, $ids, 'distribute', $report);
    }

    /**
     * @param  list<int|string>  $ids
     * @return array{count: int, message: string}
     */
    public function recall(User $actor, array $ids): array
    {
        return $this->commit($actor, $ids, 'recall', null);
    }

    /**
     * @param  list<int|string>  $ids
     * @param  array{id: int, name: string}|null  $report
     * @return array<string, mixed>
     */
    private function review(User $actor, array $ids, string $action, ?array $report): array
    {
        $context = $this->context($actor);
        $requested = $this->ids($ids);
        $plan = $this->plan($context, $requested, false);
        $chosen = $this->chosenIds($plan, $action);

        if ($chosen === []) {
            throw new LeadDistributionRejected($this->emptyMessage($action, $plan));
        }

        return [
            'action' => $action,
            'ids' => $chosen,
            'count' => count($chosen),
            'employee' => $report,
            'skipped' => count($requested) - count($chosen),
        ];
    }

    /**
     * @param  list<int|string>  $ids
     * @param  array{id: int, name: string}|null  $report
     * @return array{count: int, message: string}
     */
    private function commit(User $actor, array $ids, string $action, ?array $report): array
    {
        $context = $this->context($actor);
        $requested = $this->ids($ids);

        if ($requested === []) {
            throw new LeadDistributionRejected($this->emptyMessage($action, ['pending' => [], 'retained' => [], 'distributed' => []]));
        }

        return DB::transaction(function () use ($context, $requested, $action, $report): array {
            if ($action === 'distribute') {
                $report = $this->requireReport($context, (int) ($report['id'] ?? 0));
            }

            $plan = $this->plan($context, $requested, true);

            if ($this->chosenIds($plan, $action) === []) {
                throw new LeadDistributionRejected($this->emptyMessage($action, $plan));
            }

            $now = now();
            $count = match ($action) {
                'retain' => $this->movePending($context, $plan['pending'], $context['employeeId'], 'Retained by the Team Lead', $now),
                'distribute' => $this->movePending($context, $plan['pending'], (int) $report['id'], 'Distributed to a direct report', $now),
                default => $this->takeBack($context, $plan['distributed'], $now),
            };

            return [
                'count' => $count,
                'message' => $this->successMessage($action, $count, is_array($report) ? $report['name'] : ''),
            ];
        });
    }

    /**
     * @param  array{tenantId: int, employeeId: int, userId: int}  $context
     * @param  list<int>  $leadIds
     */
    private function movePending(array $context, array $leadIds, int $assigneeId, string $note, mixed $now): int
    {
        $updated = Lead::query()
            ->inCompany($context['tenantId'])
            ->where('team_lead_employee_id', $context['employeeId'])
            ->whereIn('id', $leadIds)
            ->whereNull('assignee_employee_id')
            ->update([
                'assignee_employee_id' => $assigneeId,
                'assigned_at' => $now,
                'updated_by' => $context['userId'],
                'updated_at' => $now,
            ]);

        if ($updated !== count($leadIds)) {
            throw new LeadDistributionRejected('These leads changed before they could be updated. Review the selection and try again.');
        }

        $this->history($context, array_map(fn (int $id): array => [
            'id' => $id,
            'fromAssignee' => null,
            'toAssignee' => $assigneeId,
            'note' => $note,
        ], $leadIds), $now);

        return $updated;
    }

    /**
     * @param  array{tenantId: int, employeeId: int, userId: int}  $context
     * @param  array<int, int>  $moves
     */
    private function takeBack(array $context, array $moves, mixed $now): int
    {
        $written = [];

        foreach ($moves as $leadId => $fromAssignee) {
            $updated = Lead::query()
                ->inCompany($context['tenantId'])
                ->whereKey($leadId)
                ->where('team_lead_employee_id', $context['employeeId'])
                ->where('assignee_employee_id', $fromAssignee)
                ->update([
                    'assignee_employee_id' => $context['employeeId'],
                    'assigned_at' => $now,
                    'updated_by' => $context['userId'],
                    'updated_at' => $now,
                ]);

            if ($updated !== 1) {
                throw new LeadDistributionRejected('These leads changed before they could be updated. Review the selection and try again.');
            }

            $written[] = [
                'id' => $leadId,
                'fromAssignee' => $fromAssignee,
                'toAssignee' => $context['employeeId'],
                'note' => 'Taken back by the Team Lead',
            ];
        }

        $this->history($context, $written, $now);

        return count($written);
    }

    /**
     * @param  array{tenantId: int, employeeId: int, userId: int}  $context
     * @param  list<array{id: int, fromAssignee: int|null, toAssignee: int, note: string}>  $rows
     */
    private function history(array $context, array $rows, mixed $now): void
    {
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('lead_assignments')->insert(array_map(fn (array $row): array => [
                'tenant_id' => $context['tenantId'],
                'lead_id' => $row['id'],
                'from_team_lead_employee_id' => $context['employeeId'],
                'to_team_lead_employee_id' => $context['employeeId'],
                'from_assignee_employee_id' => $row['fromAssignee'],
                'to_assignee_employee_id' => $row['toAssignee'],
                'assigned_by' => $context['userId'],
                'note' => $row['note'],
                'created_at' => $now,
            ], $chunk));
        }
    }

    /**
     * @return array{tenantId: int, employeeId: int, userId: int}
     */
    private function context(User $actor): array
    {
        $role = AuthenticatedWorkspace::resolve($actor)['role'];
        $permissions = CompanyRole::permissions(is_string($role) ? $role : '');

        if ($role !== 'team_lead' || ($permissions['leadsDistribute'] ?? false) !== true || $actor->companyId() === null) {
            abort(403);
        }

        $tenantId = (int) $actor->companyId();
        $employeeId = DB::table('employees')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $actor->id)
            ->where('status', 'active')
            ->orderBy('id')
            ->value('id');
        abort_if($employeeId === null, 403);

        return [
            'tenantId' => $tenantId,
            'employeeId' => (int) $employeeId,
            'userId' => (int) $actor->id,
        ];
    }

    /**
     * @param  array{tenantId: int, employeeId: int, userId: int}  $context
     * @return array{id: int, name: string}
     */
    private function requireReport(array $context, int $employeeId): array
    {
        foreach ($this->reports($context['tenantId'], $context['employeeId']) as $report) {
            if ($report['id'] === $employeeId) {
                return $report;
            }
        }

        throw new LeadDistributionRejected('Choose an active employee who reports to you.');
    }

    /**
     * @param  array{tenantId: int, employeeId: int, userId: int}  $context
     * @param  list<int>  $ids
     * @return array{pending: list<int>, retained: list<int>, distributed: array<int, int>}
     */
    private function plan(array $context, array $ids, bool $lock): array
    {
        $pending = [];
        $retained = [];
        $distributed = [];

        foreach ($this->locked($context, $ids, $lock) as $lead) {
            $leadId = (int) $lead->id;
            $assignee = $lead->assignee_employee_id === null ? null : (int) $lead->assignee_employee_id;

            if ($assignee === null) {
                $pending[] = $leadId;
            } elseif ($assignee === $context['employeeId']) {
                $retained[] = $leadId;
            } else {
                $distributed[$leadId] = $assignee;
            }
        }

        return ['pending' => $pending, 'retained' => $retained, 'distributed' => $distributed];
    }

    /**
     * @param  array{pending: list<int>, retained: list<int>, distributed: array<int, int>}  $plan
     * @return list<int>
     */
    private function chosenIds(array $plan, string $action): array
    {
        if ($action === 'recall') {
            return array_values(array_map(fn (int|string $id): int => (int) $id, array_keys($plan['distributed'])));
        }

        return $plan['pending'];
    }

    /**
     * @param  array{pending: list<int>, retained: list<int>, distributed: array<int, int>}  $plan
     */
    private function emptyMessage(string $action, array $plan): string
    {
        if ($action === 'recall') {
            return 'Select at least one lead assigned to an employee.';
        }

        if ($plan['retained'] !== [] && $plan['distributed'] === []) {
            return 'These leads are already retained.';
        }

        return 'Select at least one lead that is not yet distributed.';
    }

    private function successMessage(string $action, int $count, string $name): string
    {
        $label = $count === 1 ? 'lead' : 'leads';

        return match ($action) {
            'retain' => 'Retained '.$count.' '.$label.'.',
            'distribute' => 'Distributed '.$count.' '.$label.' to '.$name.'.',
            default => 'Took '.$count.' '.$label.' back.',
        };
    }

    /**
     * @param  array{tenantId: int, employeeId: int, userId: int}  $context
     * @param  list<int>  $ids
     * @return Collection<int, Lead>
     */
    private function locked(array $context, array $ids, bool $lock): Collection
    {
        if ($ids === []) {
            return collect();
        }

        $query = Lead::query()
            ->inCompany($context['tenantId'])
            ->where('leads.team_lead_employee_id', $context['employeeId'])
            ->whereIn('leads.id', $ids)
            ->orderBy('leads.id');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get(['leads.id', 'leads.team_lead_employee_id', 'leads.assignee_employee_id']);
    }

    /**
     * @return array{pending: int, retained: int, distributed: int, myTotal: int, teamTotal: int}
     */
    private function summary(int $tenantId, int $employeeId): array
    {
        $pending = $this->sectionQuery($tenantId, $employeeId, 'pending')->count();
        $retained = $this->sectionQuery($tenantId, $employeeId, 'retained')->count();
        $distributed = $this->sectionQuery($tenantId, $employeeId, 'distributed')->count();

        return [
            'pending' => $pending,
            'retained' => $retained,
            'distributed' => $distributed,
            'myTotal' => $retained,
            'teamTotal' => $retained + $distributed + $pending,
        ];
    }

    /**
     * @return list<array{value: string, label: string, count: int}>
     */
    private function pipelineCounts(int $tenantId, int $employeeId, string $section): array
    {
        $rows = $this->sectionQuery($tenantId, $employeeId, $section)
            ->groupBy('leads.pipeline_status')
            ->selectRaw('leads.pipeline_status, COUNT(*) as total')
            ->pluck('total', 'pipeline_status');
        $pipeline = [];

        foreach (Lead::STATUSES as $value => $label) {
            $pipeline[] = [
                'value' => $value,
                'label' => $label,
                'count' => (int) ($rows[$value] ?? 0),
            ];
        }

        return $pipeline;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Lead>
     */
    private function sectionQuery(int $tenantId, int $employeeId, string $section): \Illuminate\Database\Eloquent\Builder
    {
        $query = Lead::query()->inCompany($tenantId)->where('leads.team_lead_employee_id', $employeeId);

        if ($section === 'retained') {
            return $query->where('leads.assignee_employee_id', $employeeId);
        }

        if ($section === 'distributed') {
            return $query->whereNotNull('leads.assignee_employee_id')->where('leads.assignee_employee_id', '!=', $employeeId);
        }

        return $query->whereNull('leads.assignee_employee_id');
    }

    /**
     * @param  array{tenantId: int, employeeId: int, userId: int}  $context
     * @param  array<string, mixed>  $input
     * @return list<int>|null
     */
    private function selectedIds(array $context, string $section, array $input): ?array
    {
        if ((string) ($input['selection'] ?? '') !== 'matching' || $section === 'retained') {
            return null;
        }

        return $this->sectionQuery($context['tenantId'], $context['employeeId'], $section)
            ->orderByDesc('leads.id')
            ->limit(self::LIMIT)
            ->pluck('leads.id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @return list<array{id: int, name: string, active: bool, total: int, statuses: list<array{label: string, count: int}>}>
     */
    private function employeeSummary(int $tenantId, int $employeeId): array
    {
        $reports = DB::table('employees')
            ->where('tenant_id', $tenantId)
            ->where('manager_id', $employeeId)
            ->where('id', '!=', $employeeId)
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'status']);
        $counts = $this->statusCounts($tenantId, $employeeId);
        $rows = [];

        foreach ($reports as $report) {
            $id = (int) $report->id;
            $statuses = $this->statusList($counts[$id] ?? []);
            $rows[] = [
                'id' => $id,
                'name' => $this->personName($report->first_name, $report->last_name),
                'active' => (string) $report->status === 'active',
                'total' => array_sum($counts[$id] ?? []),
                'statuses' => $statuses,
            ];
        }

        return $rows;
    }

    /**
     * @return array<int, array<string, int>>
     */
    private function statusCounts(int $tenantId, int $employeeId): array
    {
        $rows = DB::table('leads')
            ->where('tenant_id', $tenantId)
            ->where('team_lead_employee_id', $employeeId)
            ->whereNotNull('assignee_employee_id')
            ->where('assignee_employee_id', '!=', $employeeId)
            ->groupBy('assignee_employee_id', 'pipeline_status')
            ->selectRaw('assignee_employee_id, pipeline_status, COUNT(*) as total')
            ->get();
        $grouped = [];

        foreach ($rows as $row) {
            $grouped[(int) $row->assignee_employee_id][(string) $row->pipeline_status] = (int) $row->total;
        }

        return $grouped;
    }

    /**
     * @param  array<string, int>  $counts
     * @return list<array{label: string, count: int}>
     */
    private function statusList(array $counts): array
    {
        $list = [];

        foreach (Lead::STATUSES as $value => $label) {
            if (($counts[$value] ?? 0) > 0) {
                $list[] = ['label' => $label, 'count' => $counts[$value]];
            }
        }

        return $list;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function reports(int $tenantId, int $employeeId): array
    {
        return DB::table('employees')
            ->where('tenant_id', $tenantId)
            ->where('manager_id', $employeeId)
            ->where('id', '!=', $employeeId)
            ->where('status', 'active')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name'])
            ->map(fn ($row): array => [
                'id' => (int) $row->id,
                'name' => $this->personName($row->first_name, $row->last_name),
            ])
            ->all();
    }

    /**
     * @param  array<int, string>  $names
     * @return array<string, mixed>
     */
    private function row(Lead $lead, array $names, string $today, string $section): array
    {
        $status = (string) $lead->pipeline_status;
        $assigneeId = $lead->assignee_employee_id === null ? null : (int) $lead->assignee_employee_id;
        $followUpOn = $this->date($lead->follow_up_on);

        return [
            'id' => (int) $lead->id,
            'name' => (string) $lead->name,
            'email' => (string) ($lead->email ?? ''),
            'phone' => (string) ($lead->phone ?? ''),
            'state' => (string) ($lead->state ?? ''),
            'status' => $status,
            'statusLabel' => Lead::STATUSES[$status] ?? $status,
            'followUpOn' => $followUpOn,
            'remarks' => (string) ($lead->remarks ?? ''),
            'reminder' => $this->reminder($followUpOn, $today),
            'canFollowUp' => in_array($section, ['pending', 'retained', 'distributed'], true),
            'assigneeName' => $assigneeId === null ? '' : (string) ($names[$assigneeId] ?? ''),
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function statuses(): array
    {
        $statuses = [];

        foreach (Lead::STATUSES as $value => $label) {
            $statuses[] = ['value' => $value, 'label' => $label];
        }

        return $statuses;
    }

    private function today(int $tenantId): string
    {
        $timezone = DB::table('tenants')->where('id', $tenantId)->value('timezone');
        $zone = is_string($timezone) && $timezone !== '' ? $timezone : 'Asia/Kolkata';

        return now()->timezone($zone)->toDateString();
    }

    private function reminder(?string $date, string $today): string
    {
        if ($date === null || $date === '') {
            return 'none';
        }

        if ($date < $today) {
            return 'overdue';
        }

        return $date === $today ? 'due_today' : 'upcoming';
    }

    /**
     * @return array<string, mixed>
     */
    private function leadPayload(Lead $lead): array
    {
        return [
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
        ];
    }

    /**
     * @return array{section: string, label: string, teamLeadName: string, assigneeName: string}
     */
    private function placement(int $tenantId, Lead $lead): array
    {
        $teamLeadId = $lead->team_lead_employee_id === null ? null : (int) $lead->team_lead_employee_id;
        $assigneeId = $lead->assignee_employee_id === null ? null : (int) $lead->assignee_employee_id;
        $names = $this->employeeNames($tenantId, array_filter([$teamLeadId, $assigneeId]));
        $teamLeadName = $teamLeadId === null ? '' : (string) ($names[$teamLeadId] ?? '');
        $assigneeName = $assigneeId === null ? '' : (string) ($names[$assigneeId] ?? '');

        if ($teamLeadId === null && $assigneeId === null) {
            $section = 'unassigned';
            $label = 'Company pool';
        } elseif ($assigneeId === null) {
            $section = 'pending';
            $label = 'Not yet distributed';
        } elseif ($assigneeId === $teamLeadId) {
            $section = 'retained';
            $label = 'Retained by the Team Lead';
        } else {
            $section = 'distributed';
            $label = 'Distributed to an employee';
        }

        return [
            'section' => $section,
            'label' => $label,
            'teamLeadName' => $teamLeadName,
            'assigneeName' => $assigneeName,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function assignmentHistory(int $tenantId, int $leadId): array
    {
        $rows = DB::table('lead_assignments')
            ->where('tenant_id', $tenantId)
            ->where('lead_id', $leadId)
            ->orderByDesc('id')
            ->limit(100)
            ->get();
        $employeeIds = [];
        $userIds = [];

        foreach ($rows as $row) {
            foreach (['from_team_lead_employee_id', 'to_team_lead_employee_id', 'from_assignee_employee_id', 'to_assignee_employee_id'] as $column) {
                if ($row->{$column} !== null) {
                    $employeeIds[] = (int) $row->{$column};
                }
            }

            if ($row->assigned_by !== null) {
                $userIds[] = (int) $row->assigned_by;
            }
        }

        $employees = $this->employeeNames($tenantId, $employeeIds);
        $users = $this->userNames($tenantId, $userIds);
        $history = [];

        foreach ($rows as $row) {
            $history[] = [
                'id' => (int) $row->id,
                'fromTeamLead' => $this->named($employees, $row->from_team_lead_employee_id),
                'toTeamLead' => $this->named($employees, $row->to_team_lead_employee_id),
                'fromAssignee' => $this->named($employees, $row->from_assignee_employee_id),
                'toAssignee' => $this->named($employees, $row->to_assignee_employee_id),
                'note' => (string) ($row->note ?? ''),
                'actor' => $this->named($users, $row->assigned_by),
                'at' => $row->created_at === null ? '' : (string) $row->created_at,
            ];
        }

        return $history;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function followUps(int $tenantId, int $leadId): array
    {
        $rows = DB::table('lead_activities')
            ->where('tenant_id', $tenantId)
            ->where('lead_id', $leadId)
            ->orderByDesc('id')
            ->limit(100)
            ->get();
        $users = $this->userNames($tenantId, $rows->pluck('actor_id')->filter()->map(fn ($id): int => (int) $id)->all());
        $history = [];

        foreach ($rows as $row) {
            $status = (string) ($row->pipeline_status ?? '');
            $history[] = [
                'id' => (int) $row->id,
                'type' => (string) $row->type,
                'statusLabel' => Lead::STATUSES[$status] ?? $status,
                'followUpOn' => $this->date($row->follow_up_on),
                'body' => (string) ($row->body ?? ''),
                'actor' => $this->named($users, $row->actor_id),
                'at' => $row->created_at === null ? '' : (string) $row->created_at,
            ];
        }

        return $history;
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

    /**
     * @param  list<int|string>  $ids
     * @return list<int>
     */
    private function ids(array $ids): array
    {
        $clean = [];

        foreach (array_slice($ids, 0, self::LIMIT) as $id) {
            if (is_numeric($id) && (int) $id > 0) {
                $clean[] = (int) $id;
            }
        }

        return array_values(array_unique($clean));
    }

    private function section(string $section): string
    {
        return in_array($section, ['pending', 'retained', 'distributed'], true) ? $section : 'pending';
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
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function userNames(int $tenantId, array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));

        if ($ids === []) {
            return [];
        }

        $names = [];

        foreach (DB::table('users')->where('tenant_id', $tenantId)->whereIn('id', $ids)->get(['id', 'name']) as $row) {
            $names[(int) $row->id] = trim((string) $row->name);
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
