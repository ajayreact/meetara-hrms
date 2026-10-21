<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Workflow tables created while the server default engine was MyISAM.
     * Conversion keeps columns, indexes, and rows. It does not add foreign keys.
     *
     * @var list<string>
     */
    private array $tables = [
        'payroll_payments',
        'payroll_events',
        'salary_structure_events',
        'leave_request_events',
        'overtime_events',
        'ticket_comments',
        'ticket_events',
        'company_payroll_settings',
        'company_payroll_setting_events',
        'role_assignment_events',
        'attendance_breaks',
        'location_networks',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach ($this->tables as $table) {
            if ($this->engine($table) === 'MyISAM') {
                DB::statement('ALTER TABLE `'.$table.'` ENGINE=InnoDB');
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach ($this->tables as $table) {
            if ($this->engine($table) === 'InnoDB' && $this->foreignKeys($table) === 0) {
                DB::statement('ALTER TABLE `'.$table.'` ENGINE=MyISAM');
            }
        }
    }

    private function engine(string $table): ?string
    {
        $row = DB::selectOne(
            'select engine from information_schema.tables where table_schema = database() and table_name = ?',
            [$table]
        );

        $engine = $row->engine ?? $row->ENGINE ?? null;

        return $engine === null ? null : (string) $engine;
    }

    private function foreignKeys(string $table): int
    {
        $row = DB::selectOne(
            'select count(*) as aggregate from information_schema.referential_constraints where constraint_schema = database() and table_name = ?',
            [$table]
        );

        return (int) ($row->aggregate ?? $row->AGGREGATE ?? 0);
    }
};
