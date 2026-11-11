<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->uniquePair('employees');
        $this->uniquePair('users');
        $this->uniquePair('lead_import_batches');
        $this->uniquePair('leads');

        $this->tenantForeign('lead_import_batches', 'uploaded_by', 'users', 'lead_import_batches_tenant_uploader_foreign');
        $this->tenantForeign('leads', 'import_batch_id', 'lead_import_batches', 'leads_tenant_batch_foreign');
        $this->tenantForeign('leads', 'team_lead_employee_id', 'employees', 'leads_tenant_team_lead_foreign');
        $this->tenantForeign('leads', 'assignee_employee_id', 'employees', 'leads_tenant_assignee_foreign');
        $this->tenantForeign('leads', 'created_by', 'users', 'leads_tenant_created_by_foreign');
        $this->tenantForeign('leads', 'updated_by', 'users', 'leads_tenant_updated_by_foreign');
        $this->tenantForeign('lead_assignments', 'lead_id', 'leads', 'lead_assignments_tenant_lead_foreign');
        $this->tenantForeign('lead_assignments', 'from_team_lead_employee_id', 'employees', 'lead_assignments_tenant_from_team_lead_foreign');
        $this->tenantForeign('lead_assignments', 'to_team_lead_employee_id', 'employees', 'lead_assignments_tenant_to_team_lead_foreign');
        $this->tenantForeign('lead_assignments', 'from_assignee_employee_id', 'employees', 'lead_assignments_tenant_from_assignee_foreign');
        $this->tenantForeign('lead_assignments', 'to_assignee_employee_id', 'employees', 'lead_assignments_tenant_to_assignee_foreign');
        $this->tenantForeign('lead_assignments', 'assigned_by', 'users', 'lead_assignments_tenant_assigned_by_foreign');
        $this->tenantForeign('lead_activities', 'lead_id', 'leads', 'lead_activities_tenant_lead_foreign');
        $this->tenantForeign('lead_activities', 'actor_id', 'users', 'lead_activities_tenant_actor_foreign');
    }

    public function down(): void
    {
        foreach ([
            ['lead_activities', ['tenant_id', 'lead_id']],
            ['lead_activities', ['lead_id']],
            ['lead_activities', ['tenant_id', 'actor_id']],
            ['lead_assignments', ['tenant_id', 'lead_id']],
            ['lead_assignments', ['lead_id']],
            ['lead_assignments', ['tenant_id', 'from_team_lead_employee_id']],
            ['lead_assignments', ['tenant_id', 'to_team_lead_employee_id']],
            ['lead_assignments', ['tenant_id', 'from_assignee_employee_id']],
            ['lead_assignments', ['tenant_id', 'to_assignee_employee_id']],
            ['lead_assignments', ['tenant_id', 'assigned_by']],
        ] as [$table, $columns]) {
            $this->dropColumnsForeign($table, $columns);
        }

        foreach ([
            ['leads', ['tenant_id', 'import_batch_id']],
            ['leads', ['import_batch_id']],
            ['leads', ['tenant_id', 'team_lead_employee_id']],
            ['leads', ['tenant_id', 'assignee_employee_id']],
            ['leads', ['tenant_id', 'created_by']],
            ['leads', ['tenant_id', 'updated_by']],
        ] as [$table, $columns]) {
            $this->dropColumnsForeign($table, $columns);
        }

        $this->dropColumnsForeign('lead_import_batches', ['tenant_id', 'uploaded_by']);

        foreach (['lead_activities', 'lead_assignments', 'leads', 'lead_import_batches', 'users', 'employees'] as $table) {
            $this->dropUniquePair($table);
        }

        $this->simpleForeign('lead_activities', 'lead_id', 'leads');
        $this->simpleForeign('lead_assignments', 'lead_id', 'leads');
        $this->simpleForeign('leads', 'import_batch_id', 'lead_import_batches');
    }

    private function uniquePair(string $table): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'tenant_id') || ! Schema::hasColumn($table, 'id')) {
            return;
        }

        $name = $table.'_tenant_id_id_unique';

        if ($this->hasIndex($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($name): void {
            $blueprint->unique(['tenant_id', 'id'], $name);
        });
    }

    private function dropUniquePair(string $table): void
    {
        $name = $table.'_tenant_id_id_unique';

        if (! Schema::hasTable($table) || ! $this->hasIndex($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($name): void {
            $blueprint->dropUnique($name);
        });
    }

    private function tenantForeign(string $table, string $column, string $references, string $name): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasTable($references) || ! Schema::hasColumn($table, 'tenant_id') || ! Schema::hasColumn($table, $column)) {
            return;
        }

        if (! Schema::hasColumn($references, 'tenant_id') || ! $this->hasIndex($references, $references.'_tenant_id_id_unique')) {
            return;
        }

        if ($this->hasForeign($table, $name, ['tenant_id', $column])) {
            return;
        }

        $this->change($table, function () use ($table, $column, $references, $name): void {
            Schema::table($table, function (Blueprint $blueprint) use ($column, $references, $name): void {
                $blueprint->foreign(['tenant_id', $column], $name)->references(['tenant_id', 'id'])->on($references);
            });
        });
    }

    private function dropColumnsForeign(string $table, array $columns): void
    {
        if (! Schema::hasTable($table) || ! $this->hasForeign($table, '', $columns)) {
            return;
        }

        $this->change($table, function () use ($table, $columns): void {
            Schema::table($table, function (Blueprint $blueprint) use ($columns): void {
                $blueprint->dropForeign($columns);
            });
        });
    }

    private function simpleForeign(string $table, string $column, string $references): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasTable($references) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        if ($this->hasForeign($table, '', [$column])) {
            return;
        }

        $this->change($table, function () use ($table, $column, $references): void {
            Schema::table($table, function (Blueprint $blueprint) use ($column, $references): void {
                $blueprint->foreign($column)->references('id')->on($references);
            });
        });
    }

    private function change(string $table, callable $callback): void
    {
        $removed = $this->detachReferences($table);

        try {
            $callback();
        } finally {
            $this->restoreReferences($removed);
        }
    }

    private function detachReferences(string $table): array
    {
        $removed = [];

        foreach (['lead_activities', 'lead_assignments', 'leads', 'lead_import_batches'] as $child) {
            if ($child === $table || ! Schema::hasTable($child)) {
                continue;
            }

            $foreigns = array_values(array_filter(
                Schema::getForeignKeys($child),
                fn (array $foreign): bool => ($foreign['foreign_table'] ?? '') === $table,
            ));

            if ($foreigns === []) {
                continue;
            }

            $removed = array_merge($this->detachReferences($child), $removed);

            foreach ($foreigns as $foreign) {
                $this->dropColumnsForeign($child, $foreign['columns']);
                $removed[] = [$child, $foreign];
            }
        }

        return $removed;
    }

    private function restoreReferences(array $removed): void
    {
        foreach (array_reverse($removed) as [$child, $foreign]) {
            $columns = $foreign['columns'];

            if ($this->hasForeign($child, (string) ($foreign['name'] ?? ''), $columns)) {
                continue;
            }

            $this->change($child, function () use ($child, $foreign, $columns): void {
                Schema::table($child, function (Blueprint $blueprint) use ($foreign, $columns): void {
                    $name = $foreign['name'] ?? null;
                    $definition = $name ? $blueprint->foreign($columns, $name) : $blueprint->foreign($columns);
                    $definition->references($foreign['foreign_columns'])->on($foreign['foreign_table']);
                });
            });
        }
    }

    private function hasIndex(string $table, string $name): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['name'] ?? '') === $name) {
                return true;
            }
        }

        return false;
    }

    private function hasForeign(string $table, string $name, array $columns): bool
    {
        foreach (Schema::getForeignKeys($table) as $foreign) {
            if ($name !== '' && ($foreign['name'] ?? '') === $name) {
                return true;
            }

            if (($foreign['columns'] ?? []) === $columns) {
                return true;
            }
        }

        return false;
    }
};
