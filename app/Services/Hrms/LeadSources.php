<?php

namespace App\Services\Hrms;

use App\Auth\CompanyRole;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\User;
use App\Support\AuthenticatedWorkspace;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class LeadSources
{
    public function manages(User $actor): bool
    {
        $role = AuthenticatedWorkspace::resolve($actor)['role'];
        $permissions = CompanyRole::permissions(is_string($role) ? $role : '');

        return ($permissions['leadsManageSources'] ?? false) === true && $actor->companyId() !== null;
    }

    public function assertManager(User $actor): int
    {
        if (! $this->manages($actor)) {
            abort(403);
        }

        return (int) $actor->companyId();
    }

    /**
     * @return array{sources: list<array<string, mixed>>}
     */
    public function page(User $actor): array
    {
        $tenantId = $this->assertManager($actor);

        return ['sources' => $this->rows($tenantId)];
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function choices(int $tenantId): array
    {
        if (! Schema::hasTable('lead_sources')) {
            return [];
        }

        return LeadSource::query()
            ->inCompany($tenantId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (LeadSource $source): array => [
                'id' => (int) $source->id,
                'name' => (string) $source->name,
            ])
            ->all();
    }

    public function create(User $actor, string $name): LeadSource
    {
        $tenantId = $this->assertManager($actor);
        $name = $this->name($name);
        $this->assertAvailable($tenantId, $name);

        try {
            $source = new LeadSource;
            $source->forceFill([
                'tenant_id' => $tenantId,
                'name' => $name,
                'is_active' => true,
                'created_by' => $actor->id,
            ])->save();
        } catch (QueryException) {
            throw ValidationException::withMessages(['name' => 'That lead source already exists.']);
        }

        return $source;
    }

    public function update(User $actor, int $sourceId, string $name, bool $active): void
    {
        $tenantId = $this->assertManager($actor);
        $source = $this->find($tenantId, $sourceId);
        $name = $this->name($name);
        $this->assertAvailable($tenantId, $name, (int) $source->id);

        try {
            $source->forceFill([
                'name' => $name,
                'is_active' => $active,
            ])->save();
        } catch (QueryException) {
            throw ValidationException::withMessages(['name' => 'That lead source already exists.']);
        }
    }

    public function attach(User $actor, int $leadId, int $sourceId): void
    {
        $tenantId = $this->assertManager($actor);
        $source = $this->find($tenantId, $sourceId);

        if (! $source->is_active) {
            throw ValidationException::withMessages(['source_id' => 'Choose an active lead source.']);
        }

        $lead = Lead::query()->inCompany($tenantId)->whereKey($leadId)->first();
        abort_if($lead === null, 404);

        if (trim((string) ($lead->source_name ?? '')) !== '') {
            throw ValidationException::withMessages(['source_id' => 'This lead already has its original source.']);
        }

        $lead->forceFill([
            'lead_source_id' => $source->id,
            'source_name' => (string) $source->name,
            'updated_by' => $actor->id,
        ])->save();
    }

    /**
     * Find an existing source or create one during an authorized import.
     * The stored name is the first spelling, so later rows do not create a duplicate.
     */
    public function findOrCreate(int $tenantId, ?int $userId, string $name): ?int
    {
        if (! Schema::hasTable('lead_sources')) {
            return null;
        }

        $name = $this->name($name);
        $existing = $this->match($tenantId, $name);

        if ($existing !== null) {
            return (int) $existing->id;
        }

        try {
            $source = new LeadSource;
            $source->forceFill([
                'tenant_id' => $tenantId,
                'name' => $name,
                'is_active' => true,
                'created_by' => $userId,
            ])->save();
        } catch (QueryException) {
            $existing = $this->match($tenantId, $name);

            return $existing === null ? null : (int) $existing->id;
        }

        return (int) $source->id;
    }

    private function find(int $tenantId, int $sourceId): LeadSource
    {
        $source = LeadSource::query()->inCompany($tenantId)->whereKey($sourceId)->first();
        abort_if($source === null, 404);

        return $source;
    }

    private function name(string $name): string
    {
        $name = mb_substr(trim(preg_replace('/\s+/u', ' ', $name) ?? ''), 0, 120);

        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Enter a lead source name.']);
        }

        return $name;
    }

    private function assertAvailable(int $tenantId, string $name, ?int $ignoreId = null): void
    {
        $existing = $this->match($tenantId, $name);

        if ($existing !== null && (int) $existing->id !== $ignoreId) {
            throw ValidationException::withMessages(['name' => 'That lead source already exists.']);
        }
    }

    private function match(int $tenantId, string $name): ?LeadSource
    {
        return LeadSource::query()
            ->inCompany($tenantId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(int $tenantId): array
    {
        $counts = Schema::hasColumn('leads', 'lead_source_id')
            ? DB::table('leads')
                ->where('tenant_id', $tenantId)
                ->whereNotNull('lead_source_id')
                ->selectRaw("lead_source_id, COUNT(*) as total, SUM(CASE WHEN pipeline_status = 'converted' THEN 1 ELSE 0 END) as converted")
                ->groupBy('lead_source_id')
                ->get()
                ->keyBy(fn ($row): int => (int) $row->lead_source_id)
            : collect();

        return LeadSource::query()
            ->inCompany($tenantId)
            ->orderBy('name')
            ->get()
            ->map(function (LeadSource $source) use ($counts): array {
                $count = $counts->get((int) $source->id);
                $total = $count === null ? 0 : (int) $count->total;
                $converted = $count === null ? 0 : (int) $count->converted;

                return [
                    'id' => (int) $source->id,
                    'name' => (string) $source->name,
                    'active' => (bool) $source->is_active,
                    'leads' => $total,
                    'converted' => $converted,
                    'conversionRate' => $total === 0 ? 0 : (int) round(($converted / $total) * 100),
                ];
            })
            ->all();
    }
}
