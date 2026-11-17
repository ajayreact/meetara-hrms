<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->uniquePair('users');
        $this->uniquePair('standalone_id_card_import_batches');
        $this->uniquePair('standalone_id_cards');

        $this->tenantForeign('standalone_id_card_import_batches', 'uploaded_by', 'users', 'standalone_id_card_import_batches_tenant_uploader_foreign');
        $this->tenantForeign('standalone_id_cards', 'import_batch_id', 'standalone_id_card_import_batches', 'standalone_id_cards_tenant_import_batch_foreign');
    }

    public function down(): void
    {
        foreach ([
            ['standalone_id_cards', ['tenant_id', 'import_batch_id']],
            ['standalone_id_cards', ['import_batch_id']],
            ['standalone_id_card_import_batches', ['tenant_id', 'uploaded_by']],
            ['standalone_id_card_import_batches', ['uploaded_by']],
        ] as [$table, $columns]) {
            $this->dropColumnsForeign($table, $columns);
        }

        $this->dropUniquePair('standalone_id_card_import_batches');

        $this->simpleForeign('standalone_id_cards', 'import_batch_id', 'standalone_id_card_import_batches');
        $this->simpleForeign('standalone_id_card_import_batches', 'uploaded_by', 'users');
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
        $children = ['standalone_id_card_issues', 'standalone_id_cards', 'standalone_id_card_import_batches'];

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
