<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->uniquePair('users');
        $this->uniquePair('id_card_company_profiles');
        $this->uniquePair('standalone_id_cards');
        $this->uniquePair('standalone_id_card_issues');

        $this->tenantForeign('standalone_id_cards', 'company_profile_id', 'id_card_company_profiles', 'standalone_id_cards_tenant_profile_foreign');
        $this->tenantForeign('standalone_id_cards', 'reissued_from_id', 'standalone_id_cards', 'standalone_id_cards_tenant_reissued_from_foreign');
        $this->tenantForeign('standalone_id_cards', 'created_by', 'users', 'standalone_id_cards_tenant_created_by_foreign');
        $this->tenantForeign('standalone_id_cards', 'updated_by', 'users', 'standalone_id_cards_tenant_updated_by_foreign');
        $this->tenantForeign('standalone_id_card_issues', 'id_card_id', 'standalone_id_cards', 'standalone_id_card_issues_tenant_card_foreign');
        $this->tenantForeign('standalone_id_card_issues', 'issued_by', 'users', 'standalone_id_card_issues_tenant_issued_by_foreign');
    }

    public function down(): void
    {
        foreach ([
            ['standalone_id_card_issues', ['tenant_id', 'id_card_id']],
            ['standalone_id_card_issues', ['id_card_id']],
            ['standalone_id_card_issues', ['tenant_id', 'issued_by']],
            ['standalone_id_card_issues', ['issued_by']],
            ['standalone_id_cards', ['tenant_id', 'company_profile_id']],
            ['standalone_id_cards', ['company_profile_id']],
            ['standalone_id_cards', ['tenant_id', 'reissued_from_id']],
            ['standalone_id_cards', ['reissued_from_id']],
            ['standalone_id_cards', ['tenant_id', 'created_by']],
            ['standalone_id_cards', ['created_by']],
            ['standalone_id_cards', ['tenant_id', 'updated_by']],
            ['standalone_id_cards', ['updated_by']],
        ] as [$table, $columns]) {
            $this->dropColumnsForeign($table, $columns);
        }

        foreach (['standalone_id_card_issues', 'standalone_id_cards'] as $table) {
            $this->dropUniquePair($table);
        }

        $this->simpleForeign('standalone_id_card_issues', 'id_card_id', 'standalone_id_cards');
        $this->simpleForeign('standalone_id_card_issues', 'issued_by', 'users');
        $this->simpleForeign('standalone_id_cards', 'company_profile_id', 'id_card_company_profiles');
        $this->simpleForeign('standalone_id_cards', 'reissued_from_id', 'standalone_id_cards');
        $this->simpleForeign('standalone_id_cards', 'created_by', 'users');
        $this->simpleForeign('standalone_id_cards', 'updated_by', 'users');
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

        $this->dropColumnsForeign($table, [$column]);

        $this->change($table, function () use ($table, $column, $references, $name): void {
            Schema::table($table, function (Blueprint $blueprint) use ($column, $references, $name): void {
                $blueprint->foreign(['tenant_id', $column], $name)->references(['tenant_id', 'id'])->on($references);
            });
        });
    }

    /**
     * @param  list<string>  $columns
     */
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

    /**
     * @return list<array{0: string, 1: array<string, mixed>}>
     */
    private function detachReferences(string $table): array
    {
        $removed = [];
        $children = ['standalone_id_card_issues', 'standalone_id_cards'];

        foreach ($children as $child) {
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

    /**
     * @param  list<array{0: string, 1: array<string, mixed>}>  $removed
     */
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

    /**
     * @param  list<string>  $columns
     */
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
