<?php

namespace App\Services\Hrms;

use App\Auth\CompanyRole;
use App\Mail\LeadFollowUpReminderMail;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

class LeadFollowUpReminder
{
    /**
     * @return array{sent: int, skipped: int}
     */
    public function sendDue(): array
    {
        if (! Schema::hasTable('leads') || ! Schema::hasColumn('leads', 'follow_up_reminded_on')) {
            return ['sent' => 0, 'skipped' => 0];
        }

        $sent = 0;
        $skipped = 0;

        foreach (DB::table('tenants')->orderBy('id')->get(['id', 'name', 'timezone']) as $tenant) {
            $tenantId = (int) $tenant->id;
            $zone = is_string($tenant->timezone) && $tenant->timezone !== '' ? $tenant->timezone : 'Asia/Kolkata';
            $today = now()->timezone($zone)->toDateString();
            $companyName = is_string($tenant->name) && $tenant->name !== '' ? $tenant->name : 'Veltrix HRMS';

            $leads = Lead::query()
                ->inCompany($tenantId)
                ->whereNotNull('follow_up_on')
                ->whereDate('follow_up_on', '<=', $today)
                ->orderBy('id')
                ->get([
                    'id',
                    'name',
                    'phone',
                    'pipeline_status',
                    'follow_up_on',
                    'follow_up_reminded_on',
                    'team_lead_employee_id',
                    'assignee_employee_id',
                ]);

            foreach ($leads as $lead) {
                $followUpOn = $this->date($lead->follow_up_on);
                if ($followUpOn === null || $this->date($lead->follow_up_reminded_on) === $followUpOn) {
                    $skipped++;

                    continue;
                }
                $recipients = $this->recipients($tenantId, $lead);

                if ($recipients === []) {
                    $skipped++;

                    continue;
                }

                $status = (string) $lead->pipeline_status;
                $url = $this->url($lead);

                foreach ($recipients as $recipient) {
                    Mail::to($recipient['email'])->send(new LeadFollowUpReminderMail(
                        $recipient['name'],
                        $companyName,
                        (string) $lead->name,
                        (string) ($lead->phone ?? ''),
                        Lead::STATUSES[$status] ?? $status,
                        $followUpOn,
                        $url,
                    ));
                }

                Lead::query()->inCompany($tenantId)->whereKey($lead->id)->update([
                    'follow_up_reminded_on' => $followUpOn,
                ]);
                $sent++;
            }
        }

        return ['sent' => $sent, 'skipped' => $skipped];
    }

    /**
     * @return list<array{email: string, name: string}>
     */
    private function recipients(int $tenantId, Lead $lead): array
    {
        $assigneeId = $lead->assignee_employee_id === null ? null : (int) $lead->assignee_employee_id;
        $teamLeadId = $lead->team_lead_employee_id === null ? null : (int) $lead->team_lead_employee_id;

        if ($assigneeId !== null) {
            $person = $this->employeeUser($tenantId, $assigneeId);

            return $person === null ? [] : [$person];
        }

        if ($teamLeadId !== null) {
            $person = $this->employeeUser($tenantId, $teamLeadId);

            return $person === null ? [] : [$person];
        }

        return $this->companyFollowUpUsers($tenantId);
    }

    /**
     * @return array{email: string, name: string}|null
     */
    private function employeeUser(int $tenantId, int $employeeId): ?array
    {
        $row = DB::table('employees')
            ->join('users', 'users.id', '=', 'employees.user_id')
            ->where('employees.tenant_id', $tenantId)
            ->where('users.tenant_id', $tenantId)
            ->where('employees.id', $employeeId)
            ->whereNotNull('users.email')
            ->where('users.email', '!=', '')
            ->first(['users.email', 'users.name', 'employees.first_name', 'employees.last_name']);

        if ($row === null) {
            return null;
        }

        $name = trim((string) $row->first_name.' '.(string) $row->last_name);
        if ($name === '') {
            $name = is_string($row->name) && $row->name !== '' ? $row->name : 'Colleague';
        }

        return ['email' => (string) $row->email, 'name' => $name];
    }

    /**
     * @return list<array{email: string, name: string}>
     */
    private function companyFollowUpUsers(int $tenantId): array
    {
        $recipients = [];

        foreach (DB::table('users')->where('tenant_id', $tenantId)->whereNotNull('email')->where('email', '!=', '')->get(['id', 'email', 'name']) as $user) {
            $role = DB::table('roles')
                ->join('model_has_roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->where('model_has_roles.model_id', $user->id)
                ->where('model_has_roles.model_type', User::class)
                ->where('model_has_roles.tenant_id', $tenantId)
                ->value('roles.name');
            $mapped = CompanyRole::fromStored(is_string($role) ? $role : '', false);
            $permissions = CompanyRole::permissions((string) ($mapped['role'] ?? ''));

            if (($permissions['leadsFollowUpTeam'] ?? false) !== true || ($permissions['leadsViewAll'] ?? false) !== true) {
                continue;
            }

            $recipients[] = [
                'email' => (string) $user->email,
                'name' => is_string($user->name) && $user->name !== '' ? $user->name : 'Colleague',
            ];
        }

        return $recipients;
    }

    private function url(Lead $lead): string
    {
        if ($lead->assignee_employee_id !== null && $lead->team_lead_employee_id !== null
            && (int) $lead->assignee_employee_id !== (int) $lead->team_lead_employee_id) {
            return url('/leads/mine/'.(int) $lead->id);
        }

        if ($lead->team_lead_employee_id !== null) {
            return url('/my-leads/'.(int) $lead->id);
        }

        return url('/leads/'.(int) $lead->id);
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
