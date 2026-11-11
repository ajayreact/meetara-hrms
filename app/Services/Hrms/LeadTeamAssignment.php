<?php

namespace App\Services\Hrms;

use App\Auth\CompanyRole;
use App\Models\Lead;
use App\Models\User;
use App\Support\AuthenticatedWorkspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LeadTeamAssignment
{
    public const LIMIT = 5000;

    public function assertAssigner(User $actor): void
    {
        $role = AuthenticatedWorkspace::resolve($actor)['role'];
        $permissions = CompanyRole::permissions(is_string($role) ? $role : '');

        if (($permissions['leadsAssignTeam'] ?? false) !== true || $actor->companyId() === null) {
            abort(403);
        }
    }

    /**
     * @param  list<int|string>  $ids
     * @return array<string, mixed>
     */
    public function prepare(User $actor, array $ids): array
    {
        $this->assertAssigner($actor);
        $tenantId = (int) $actor->companyId();
        $requested = $this->ids($ids);
        $found = $this->lockedLeads($tenantId, $requested, false);
        $assignable = 0;
        $held = 0;

        foreach ($found as $lead) {
            if ($lead->team_lead_employee_id === null && $lead->assignee_employee_id === null) {
                $assignable++;
            } elseif ($lead->team_lead_employee_id !== null) {
                $held++;
            }
        }

        return [
            'ids' => $found->map(fn (Lead $lead): int => (int) $lead->id)->all(),
            'selectedCount' => $found->count(),
            'assignableCount' => $assignable,
            'heldCount' => $held,
            'unavailable' => count($requested) - $found->count(),
            'teamLeads' => $this->teamLeads($tenantId),
        ];
    }

    /**
     * @param  list<int|string>  $ids
     * @return array<string, mixed>
     */
    public function review(User $actor, array $ids, int $teamLeadId): array
    {
        $this->assertAssigner($actor);
        $tenantId = (int) $actor->companyId();
        $teamLead = $this->requireTeamLead($tenantId, $teamLeadId);
        $plan = $this->plan($tenantId, $this->ids($ids), $teamLeadId, false);

        if ($plan['found'] === 0) {
            throw new LeadAssignmentRejected('Select at least one lead in this company.');
        }

        return [
            'ids' => $plan['ids'],
            'count' => count($plan['assignable']),
            'heldByOthers' => count($plan['others']),
            'alreadyWithTeamLead' => $plan['duplicates'],
            'unavailable' => $plan['unavailable'],
            'teamLead' => $teamLead,
            'reassignAvailable' => $plan['others'] !== [],
        ];
    }

    /**
     * @param  list<int|string>  $ids
     * @return array{assigned: int, reassigned: int, message: string}
     */
    public function confirm(User $actor, array $ids, int $teamLeadId, bool $reassign): array
    {
        $this->assertAssigner($actor);
        $tenantId = (int) $actor->companyId();
        $requested = $this->ids($ids);

        if ($requested === []) {
            throw new LeadAssignmentRejected('Select at least one lead in this company.');
        }

        return DB::transaction(function () use ($actor, $tenantId, $requested, $teamLeadId, $reassign): array {
            $teamLead = $this->requireTeamLead($tenantId, $teamLeadId);
            $plan = $this->plan($tenantId, $requested, $teamLeadId, true);
            $moves = $reassign ? $plan['others'] : [];

            if ($plan['assignable'] === [] && $moves === []) {
                throw new LeadAssignmentRejected($this->nothingMessage($plan['duplicates'], count($plan['others'])));
            }

            $now = now();
            $assigned = $this->assignPool($tenantId, (int) $actor->id, $teamLeadId, $plan['assignable'], $now);
            $reassigned = $this->reassign($tenantId, (int) $actor->id, $teamLeadId, $moves, $now);
            $blocked = $reassign ? 0 : count($plan['others']);

            return [
                'assigned' => $assigned,
                'reassigned' => $reassigned,
                'message' => $this->successMessage($teamLead['name'], $assigned, $reassigned, $blocked, $plan['duplicates']),
            ];
        });
    }

    /**
     * Active employees whose access level is Manager or Company Manager.
     * A job designation named Team Lead does not qualify.
     *
     * @return list<array{id: int, name: string, accessLevel: string, leadCount: int}>
     */
    public function teamLeads(int $tenantId): array
    {
        $rows = DB::table('employees')
            ->join('users', function ($join) use ($tenantId): void {
                $join->on('users.id', '=', 'employees.user_id')
                    ->where('users.tenant_id', '=', $tenantId);
            })
            ->join('model_has_roles', function ($join) use ($tenantId): void {
                $join->on('model_has_roles.model_id', '=', 'users.id')
                    ->where('model_has_roles.model_type', '=', User::class)
                    ->where('model_has_roles.tenant_id', '=', $tenantId);
            })
            ->join('roles', function ($join) use ($tenantId): void {
                $join->on('roles.id', '=', 'model_has_roles.role_id')
                    ->where('roles.tenant_id', '=', $tenantId)
                    ->where('roles.guard_name', '=', 'web')
                    ->whereIn('roles.name', ['Manager', RoleAssignment::COMPANY_MANAGER]);
            })
            ->where('employees.tenant_id', $tenantId)
            ->where('employees.status', 'active')
            ->get(['employees.id', 'employees.first_name', 'employees.last_name', 'roles.name as role_name']);

        $grouped = [];

        foreach ($rows as $row) {
            $employeeId = (int) $row->id;
            $grouped[$employeeId]['name'] = trim((string) $row->first_name.' '.(string) $row->last_name);
            $grouped[$employeeId]['roles'][] = (string) $row->role_name;
        }

        if ($grouped === []) {
            return [];
        }

        $counts = DB::table('leads')
            ->where('tenant_id', $tenantId)
            ->whereIn('team_lead_employee_id', array_keys($grouped))
            ->groupBy('team_lead_employee_id')
            ->selectRaw('team_lead_employee_id, COUNT(*) as total')
            ->pluck('total', 'team_lead_employee_id');

        $teamLeads = [];

        foreach ($grouped as $employeeId => $employee) {
            $name = $employee['name'] !== '' ? $employee['name'] : 'Team Lead';
            $teamLeads[] = [
                'id' => $employeeId,
                'name' => $name,
                'accessLevel' => in_array(RoleAssignment::COMPANY_MANAGER, $employee['roles'], true) ? 'Company Manager' : 'Team Lead',
                'leadCount' => (int) ($counts[$employeeId] ?? 0),
            ];
        }

        usort($teamLeads, fn (array $left, array $right): int => strcasecmp($left['name'], $right['name']));

        return $teamLeads;
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

    /**
     * @param  list<int>  $ids
     * @return Collection<int, Lead>
     */
    private function lockedLeads(int $tenantId, array $ids, bool $lock): Collection
    {
        if ($ids === []) {
            return collect();
        }

        $query = Lead::query()->inCompany($tenantId)->whereIn('leads.id', $ids)->orderBy('leads.id');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get(['leads.id', 'leads.team_lead_employee_id', 'leads.assignee_employee_id']);
    }

    /**
     * @param  list<int>  $ids
     * @return array{ids: list<int>, found: int, unavailable: int, assignable: list<int>, others: list<array{id: int, fromTeamLead: int, fromAssignee: int|null}>, duplicates: int}
     */
    private function plan(int $tenantId, array $ids, int $teamLeadId, bool $lock): array
    {
        $found = $this->lockedLeads($tenantId, $ids, $lock);
        $assignable = [];
        $others = [];
        $duplicates = 0;

        foreach ($found as $lead) {
            $current = $lead->team_lead_employee_id === null ? null : (int) $lead->team_lead_employee_id;
            $assignee = $lead->assignee_employee_id === null ? null : (int) $lead->assignee_employee_id;
            $leadId = (int) $lead->id;

            if ($current === null && $assignee === null) {
                $assignable[] = $leadId;
            } elseif ($current === $teamLeadId) {
                $duplicates++;
            } elseif ($current !== null) {
                $others[] = [
                    'id' => $leadId,
                    'fromTeamLead' => $current,
                    'fromAssignee' => $assignee,
                ];
            }
        }

        return [
            'ids' => $found->map(fn (Lead $lead): int => (int) $lead->id)->all(),
            'found' => $found->count(),
            'unavailable' => count($ids) - $found->count(),
            'assignable' => $assignable,
            'others' => $others,
            'duplicates' => $duplicates,
        ];
    }

    /**
     * @return array{id: int, name: string, accessLevel: string, leadCount: int}
     */
    private function requireTeamLead(int $tenantId, int $teamLeadId): array
    {
        foreach ($this->teamLeads($tenantId) as $teamLead) {
            if ($teamLead['id'] === $teamLeadId) {
                return $teamLead;
            }
        }

        throw new LeadAssignmentRejected('Choose an active Team Lead in this company.');
    }

    /**
     * @param  list<int>  $leadIds
     */
    private function assignPool(int $tenantId, int $actorId, int $teamLeadId, array $leadIds, mixed $now): int
    {
        if ($leadIds === []) {
            return 0;
        }

        $updated = Lead::query()
            ->inCompany($tenantId)
            ->whereIn('id', $leadIds)
            ->whereNull('team_lead_employee_id')
            ->whereNull('assignee_employee_id')
            ->update([
                'team_lead_employee_id' => $teamLeadId,
                'assignee_employee_id' => null,
                'assigned_at' => $now,
                'updated_by' => $actorId,
                'updated_at' => $now,
            ]);

        if ($updated !== count($leadIds)) {
            throw new LeadAssignmentRejected('These leads changed before they could be assigned. Review the selection and try again.');
        }

        $this->history($tenantId, $actorId, $teamLeadId, array_map(fn (int $id): array => [
            'id' => $id,
            'fromTeamLead' => null,
            'fromAssignee' => null,
            'note' => 'Assigned from the company pool',
        ], $leadIds), $now);

        return $updated;
    }

    /**
     * @param  list<array{id: int, fromTeamLead: int, fromAssignee: int|null}>  $moves
     */
    private function reassign(int $tenantId, int $actorId, int $teamLeadId, array $moves, mixed $now): int
    {
        if ($moves === []) {
            return 0;
        }

        $written = [];

        foreach ($moves as $move) {
            $updated = Lead::query()
                ->inCompany($tenantId)
                ->whereKey($move['id'])
                ->where('team_lead_employee_id', $move['fromTeamLead'])
                ->update([
                    'team_lead_employee_id' => $teamLeadId,
                    'assignee_employee_id' => null,
                    'assigned_at' => $now,
                    'updated_by' => $actorId,
                    'updated_at' => $now,
                ]);

            if ($updated !== 1) {
                throw new LeadAssignmentRejected('These leads changed before they could be assigned. Review the selection and try again.');
            }

            $written[] = [
                'id' => $move['id'],
                'fromTeamLead' => $move['fromTeamLead'],
                'fromAssignee' => $move['fromAssignee'],
                'note' => 'Reassigned to another Team Lead',
            ];
        }

        $this->history($tenantId, $actorId, $teamLeadId, $written, $now);

        return count($written);
    }

    /**
     * @param  list<array{id: int, fromTeamLead: int|null, fromAssignee: int|null, note: string}>  $rows
     */
    private function history(int $tenantId, int $actorId, int $teamLeadId, array $rows, mixed $now): void
    {
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('lead_assignments')->insert(array_map(fn (array $row): array => [
                'tenant_id' => $tenantId,
                'lead_id' => $row['id'],
                'from_team_lead_employee_id' => $row['fromTeamLead'],
                'to_team_lead_employee_id' => $teamLeadId,
                'from_assignee_employee_id' => $row['fromAssignee'],
                'to_assignee_employee_id' => null,
                'assigned_by' => $actorId,
                'note' => $row['note'],
                'created_at' => $now,
            ], $chunk));
        }
    }

    private function nothingMessage(int $duplicates, int $blocked): string
    {
        if ($blocked > 0 && $duplicates === 0) {
            return 'These leads already belong to another Team Lead. Reassign them only from the confirmation screen.';
        }

        if ($duplicates > 0 && $blocked === 0) {
            return 'These leads are already assigned to this Team Lead.';
        }

        return 'No unassigned leads were selected.';
    }

    private function successMessage(string $name, int $assigned, int $reassigned, int $blocked, int $duplicates): string
    {
        $parts = [];

        if ($assigned > 0) {
            $parts[] = 'Assigned '.$assigned.' '.$this->plural($assigned).' to '.$name.'.';
        }

        if ($reassigned > 0) {
            $parts[] = 'Reassigned '.$reassigned.' '.$this->plural($reassigned).' to '.$name.'.';
        }

        if ($blocked > 0) {
            $parts[] = $blocked.' '.$this->plural($blocked).' already assigned to another Team Lead '.($blocked === 1 ? 'was' : 'were').' left unchanged.';
        }

        if ($duplicates > 0) {
            $parts[] = $duplicates.' '.$this->plural($duplicates).' already assigned to this Team Lead '.($duplicates === 1 ? 'was' : 'were').' left unchanged.';
        }

        return implode(' ', $parts);
    }

    private function plural(int $count): string
    {
        return $count === 1 ? 'lead' : 'leads';
    }
}
