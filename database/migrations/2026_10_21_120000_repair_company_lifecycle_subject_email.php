<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('company_lifecycle_events') || ! Schema::hasTable('company_admin_invitations')) {
            if ($this->sqliteHistoryHasNeitherTable()) {
                return;
            }

            $missing = array_values(array_filter(
                ['company_lifecycle_events', 'company_admin_invitations'],
                fn (string $table): bool => ! Schema::hasTable($table),
            ));

            throw new \RuntimeException(
                '2026_10_21_120000_repair_company_lifecycle_subject_email requires company_lifecycle_events and company_admin_invitations. Missing: '.implode(', ', $missing).'. It does not create either table.'
            );
        }

        $columns = collect(Schema::getColumns('company_lifecycle_events'));

        foreach (['id', 'tenant_id', 'actor_id', 'action', 'created_at'] as $required) {
            if (! $columns->contains(fn (array $column): bool => $column['name'] === $required)) {
                throw new \RuntimeException(
                    '2026_10_21_120000_repair_company_lifecycle_subject_email requires company_lifecycle_events.'.$required.' before subject_email can be added.'
                );
            }
        }

        $email = $columns->firstWhere('name', 'subject_email');

        if (is_array($email)) {
            if (! $this->definitionMatches($email)) {
                throw new \RuntimeException(
                    '2026_10_21_120000_repair_company_lifecycle_subject_email found company_lifecycle_events.subject_email with an incompatible definition. It will not change the column.'
                );
            }

            return;
        }

        Schema::table('company_lifecycle_events', function (Blueprint $table) {
            $table->string('subject_email', 191)->nullable();
        });

        $added = collect(Schema::getColumns('company_lifecycle_events'))->firstWhere('name', 'subject_email');

        if (! is_array($added) || ! $this->definitionMatches($added)) {
            throw new \RuntimeException(
                '2026_10_21_120000_repair_company_lifecycle_subject_email did not leave company_lifecycle_events.subject_email as nullable varchar(191). MySQL commits each DDL statement, so read the column before retrying.'
            );
        }
    }

    public function down(): void
    {
        throw new \RuntimeException(
            '2026_10_21_120000_repair_company_lifecycle_subject_email must not be rolled back. Dropping subject_email is not recovery.'
        );
    }

    /**
     * @param  array{name: string, type: string, type_name: string, nullable: bool}  $column
     */
    private function definitionMatches(array $column): bool
    {
        if (! $column['nullable'] || strtolower($column['type_name']) !== 'varchar') {
            return false;
        }

        $type = strtolower($column['type']);

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            return $type === 'varchar(191)';
        }

        return $type === 'varchar' || $type === 'varchar(191)';
    }

    private function sqliteHistoryHasNeitherTable(): bool
    {
        if (Schema::hasTable('company_lifecycle_events') || Schema::hasTable('company_admin_invitations')) {
            return false;
        }

        $repository = app('migrator')->getRepository();

        if (! $repository->repositoryExists()) {
            return false;
        }

        return in_array('2026_10_20_120000_add_company_lifecycle_events', $repository->getRan(), true)
            && ! Schema::hasTable('company_lifecycle_events');
    }
};
