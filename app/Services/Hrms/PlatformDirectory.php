<?php

namespace App\Services\Hrms;

use App\Models\User;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PlatformDirectory
{
    private const PAGE_SIZE = 20;

    private const AUDIT_PAGE_SIZE = 25;

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    private function eventSources(): array
    {
        return [
            'leave_request_events' => ['Leave', 'user_id'],
            'payroll_events' => ['Payroll', 'user_id'],
            'salary_structure_events' => ['Salary', 'user_id'],
            'role_assignment_events' => ['Access', 'actor_id'],
            'ticket_events' => ['Help desk', 'actor_id'],
            'overtime_events' => ['Overtime', 'actor_id'],
            'company_payroll_setting_events' => ['Payroll settings', 'actor_id'],
            'company_lifecycle_events' => ['Company', 'actor_id'],
        ];
    }

    /**
     * @return array{companies: list<array<string, mixed>>, page: int, pages: int, search: string, status: string, detail: array<string, mixed>|null}
     */
    public function companies(User $actor, string $search, int $page, string $status = 'all', int $selectedId = 0): array
    {
        abort_unless($actor->isPlatformAdmin(), 403);
        $page = max(1, $page);
        $status = in_array($status, ['active', 'inactive'], true) ? $status : 'all';
        $search = trim($search);
        $query = DB::table('tenants')->orderBy('name');
        $this->applyCompanyFilters($query, $search, $status);

        $total = (clone $query)->count();
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min($page, $pages);
        $rows = $query->forPage($page, self::PAGE_SIZE)->get();
        $ids = $rows->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($selectedId > 0 && ! in_array($selectedId, $ids, true)) {
            $ids[] = $selectedId;
        }
        $headcount = $this->headcount($ids);
        $administrators = $this->administrators($ids);

        $detail = null;
        if ($selectedId > 0) {
            $selected = DB::table('tenants')->where('id', $selectedId)->first();
            if ($selected !== null) {
                $detail = $this->presentCompany($selected, $headcount, $administrators, true);
            }
        }

        return [
            'companies' => $rows->map(fn ($company) => $this->presentCompany($company, $headcount, $administrators, false))->all(),
            'page' => $page,
            'pages' => $pages,
            'search' => $search,
            'status' => $status,
            'detail' => $detail,
        ];
    }

    /**
     * @return array{companies: int, active: int, inactive: int, employees: int, events: list<array<string, mixed>>}
     */
    public function overview(User $actor): array
    {
        abort_unless($actor->isPlatformAdmin(), 403);
        $companies = Schema::hasTable('tenants') ? DB::table('tenants') : null;
        $total = $companies ? (clone $companies)->count() : 0;
        $active = $companies ? (clone $companies)->where('status', 'active')->count() : 0;

        return [
            'companies' => $total,
            'active' => $active,
            'inactive' => max(0, $total - $active),
            'employees' => Schema::hasTable('employees') ? (int) DB::table('employees')->count() : 0,
            'events' => $this->listEvents([], 1, 8)['events'],
        ];
    }

    /**
     * @return array{events: list<array<string, mixed>>, page: int, pages: int, filters: array<string, string>, companies: list<array{id: int, name: string}>}
     */
    public function audit(User $actor, string $search, string $source, int $companyId, string $from, string $to, int $page): array
    {
        abort_unless($actor->isPlatformAdmin(), 403);
        $filters = [
            'search' => trim($search),
            'source' => $this->knownSource($source),
            'company' => $companyId > 0 ? (string) $companyId : '',
            'from' => $this->day($from),
            'to' => $this->day($to),
        ];
        $listing = $this->listEvents($filters, max(1, $page), self::AUDIT_PAGE_SIZE);

        return [
            'events' => $listing['events'],
            'page' => $listing['page'],
            'pages' => $listing['pages'],
            'filters' => $filters,
            'companies' => Schema::hasTable('tenants')
                ? DB::table('tenants')->orderBy('name')->get(['id', 'name'])->map(fn ($company) => [
                    'id' => (int) $company->id,
                    'name' => (string) $company->name,
                ])->all()
                : [],
        ];
    }

    /**
     * @param  array<string, string>  $filters
     * @return array{events: list<array<string, mixed>>, page: int, pages: int}
     */
    private function listEvents(array $filters, int $page, int $perPage): array
    {
        $union = $this->eventUnion($filters);
        if ($union === null) {
            return ['events' => [], 'page' => 1, 'pages' => 1];
        }

        $total = (int) DB::query()->fromSub($union, 'audit_events')->count();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $pages);
        $rows = DB::query()->fromSub($this->eventUnion($filters), 'audit_events')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->forPage($page, $perPage)
            ->get();
        $companyNames = Schema::hasTable('tenants')
            ? DB::table('tenants')->whereIn('id', $rows->pluck('tenant_id')->unique()->all())->pluck('name', 'id')
            : collect();
        $actorNames = Schema::hasTable('users')
            ? DB::table('users')->whereIn('id', $rows->pluck('actor_id')->unique()->filter()->all())->pluck('name', 'id')
            : collect();

        return [
            'events' => $rows->map(fn ($event) => [
                'source' => (string) $event->source,
                'action' => str_replace('_', ' ', (string) $event->action),
                'company' => (string) ($companyNames[$event->tenant_id] ?? 'Company'),
                'actor' => (string) ($actorNames[$event->actor_id] ?? 'Not recorded'),
                'at' => (string) $event->created_at,
                'subjectEmail' => isset($event->subject_email) && $event->subject_email !== null ? (string) $event->subject_email : '',
            ])->all(),
            'page' => $page,
            'pages' => $pages,
        ];
    }

    /**
     * @param  array<string, string>  $filters
     */
    private function eventUnion(array $filters): ?Builder
    {
        $queries = [];
        foreach ($this->eventSources() as $table => [$source, $actor]) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            if (($filters['source'] ?? '') !== '' && $filters['source'] !== $source) {
                continue;
            }

            $emailColumn = $table === 'company_lifecycle_events' && Schema::hasColumn($table, 'subject_email')
                ? 'subject_email'
                : 'CAST(NULL AS CHAR)';
            $query = DB::table($table)->selectRaw(
                '? as source, action, tenant_id, '.$actor.' as actor_id, created_at, id, '.$emailColumn.' as subject_email',
                [$source]
            );
            $this->constrainEvent($query, $filters, $actor);
            $queries[] = $query;
        }

        if ($queries === []) {
            return null;
        }

        $union = array_shift($queries);
        foreach ($queries as $query) {
            $union = $union->unionAll($query);
        }

        return $union;
    }

    /**
     * @param  array<string, string>  $filters
     */
    private function constrainEvent(Builder $query, array $filters, string $actor): void
    {
        $companyId = (int) ($filters['company'] ?? 0);
        if ($companyId > 0) {
            $query->where('tenant_id', $companyId);
        }
        if (($filters['from'] ?? '') !== '') {
            $query->where('created_at', '>=', $filters['from'].' 00:00:00');
        }
        if (($filters['to'] ?? '') !== '') {
            $query->where('created_at', '<=', $filters['to'].' 23:59:59');
        }
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search === '') {
            return;
        }

        $like = '%'.$this->like($search).'%';
        $query->where(function (Builder $inner) use ($like, $actor): void {
            $inner->where('action', 'like', $like)
                ->orWhereIn($actor, function (Builder $users) use ($like): void {
                    $users->select('id')->from('users')->where('name', 'like', $like);
                });
        });
    }

    private function applyCompanyFilters(Builder $query, string $search, string $status): void
    {
        if ($search !== '') {
            $query->where('name', 'like', '%'.$this->like($search).'%');
        }
        if ($status === 'active') {
            $query->where('status', 'active');
        }
        if ($status === 'inactive') {
            $query->where('status', '!=', 'active');
        }
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    private function headcount(array $ids): array
    {
        if ($ids === [] || ! Schema::hasTable('employees')) {
            return [];
        }

        return DB::table('employees')
            ->select('tenant_id', DB::raw('count(*) as total'))
            ->whereIn('tenant_id', $ids)
            ->groupBy('tenant_id')
            ->pluck('total', 'tenant_id')
            ->map(fn ($total) => (int) $total)
            ->all();
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, list<array{name: string, accepted: bool}>>
     */
    private function administrators(array $ids): array
    {
        if ($ids === [] || ! Schema::hasTable('model_has_roles') || ! Schema::hasTable('roles') || ! Schema::hasTable('users')) {
            return [];
        }

        $rows = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->join('users', 'users.id', '=', 'model_has_roles.model_id')
            ->where('model_has_roles.model_type', User::class)
            ->where('roles.name', 'Owner')
            ->whereIn('model_has_roles.tenant_id', $ids)
            ->orderBy('users.name')
            ->get(['model_has_roles.tenant_id', 'users.name', 'users.invite_accepted_at']);
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row->tenant_id][] = [
                'name' => (string) $row->name,
                'accepted' => $row->invite_accepted_at !== null,
            ];
        }

        return $grouped;
    }

    /**
     * @param  array<int, int>  $headcount
     * @param  array<int, list<array{name: string, accepted: bool}>>  $administrators
     * @return array<string, mixed>
     */
    private function presentCompany(object $company, array $headcount, array $administrators, bool $detail): array
    {
        $id = (int) $company->id;
        $admins = $administrators[$id] ?? [];
        $row = [
            'id' => $id,
            'name' => (string) $company->name,
            'status' => (string) $company->status === 'active' ? 'Active' : 'Inactive',
            'statusKey' => (string) $company->status === 'active' ? 'active' : 'inactive',
            'slug' => (string) ($company->slug ?? ''),
            'timezone' => (string) ($company->timezone ?: 'Not recorded'),
            'headcount' => (int) ($headcount[$id] ?? 0),
            'administrators' => array_map(
                fn (array $admin) => $admin['name'].($admin['accepted'] ? '' : ' (invite not accepted)'),
                $admins
            ),
        ];

        if (! $detail) {
            return $row;
        }

        $weekOff = property_exists($company, 'week_off') ? $company->week_off : null;
        if (is_string($weekOff)) {
            $decoded = json_decode($weekOff, true);
            $weekOff = is_array($decoded) ? $decoded : null;
        }

        return [
            ...$row,
            'email' => $this->optionalText($company, 'email'),
            'phone' => $this->optionalText($company, 'phone'),
            'address' => $this->optionalText($company, 'address'),
            'weekOff' => is_array($weekOff) && $weekOff !== [] ? implode(', ', array_map('strval', $weekOff)) : 'Not recorded',
            'weekOffDays' => is_array($weekOff) ? array_values(array_map('strval', $weekOff)) : [],
            'createdAt' => property_exists($company, 'created_at') && $company->created_at ? (string) $company->created_at : 'Not recorded',
        ];
    }

    private function optionalText(object $row, string $field): string
    {
        if (! property_exists($row, $field) || $row->{$field} === null || $row->{$field} === '') {
            return 'Not recorded';
        }

        return (string) $row->{$field};
    }

    private function knownSource(string $source): string
    {
        $known = array_column($this->eventSources(), 0);

        return in_array($source, $known, true) ? $source : '';
    }

    private function day(string $value): string
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed instanceof DateTimeImmutable && $parsed->format('Y-m-d') === $value ? $value : '';
    }

    private function like(string $value): string
    {
        return str_replace(['%', '_'], ['\\%', '\\_'], $value);
    }
}
