<?php

namespace App\Support;

/**
 * Decides whether a later read-only MySQL inspection may start.
 * This class does not open a connection and does not call migration methods.
 */
final class MigrationInspection
{
    public const PATTERN = '/^meetara_hrms_inspect_[0-9]{8}$/';

    /**
     * Live, rehearsal, browser, and earlier disposable names.
     * The inspection pattern does not match these. They are refused by name as well.
     *
     * @var list<string>
     */
    public const DENIED = [
        'meetara_hrms',
        'meetara_hrms_baseline_20261020',
        'meetara_hrms_browser',
        'meetara_hrms_decision_20261020',
        'meetara_hrms_integrity_20261020',
        'meetara_hrms_invite_20261020',
        'meetara_hrms_notecheck_20261020',
        'meetara_hrms_offer_20261020',
        'meetara_hrms_preflight_20261020180530',
        'meetara_hrms_prelive_20261020',
        'meetara_hrms_rehearsal_onboard_20261020121740',
        'meetara_hrms_restore_20261020',
        'meetara_hrms_stage27_20261020',
        'meetara_hrms_stage28_20261020',
        'meetara_hrms_stage36_20261020',
        'meetara_hrms_stage36_browser_20261020',
        'meetara_hrms_stage37_20261020',
        'meetara_hrms_stage37_browser_20261020',
        'meetara_hrms_stage38_browser_20261020',
        'meetara_hrms_stage41a_20261021',
        'meetara_hrms_stage41b_20261021',
        'meetara_hrms_stage41b_invalid_20261021',
        'meetara_hrms_stage41b_valid_20261021',
        'meetara_hrms_stage41c_20261021',
        'meetara_hrms_stage41c_repair_20261021',
    ];

    /**
     * @var list<string>
     */
    public const REQUIRED_LIFECYCLE_COLUMNS = [
        'id',
        'tenant_id',
        'actor_id',
        'action',
        'created_at',
    ];

    /**
     * @var list<string>
     */
    public const REPORTED_TABLES = [
        'tenants',
        'users',
        'company_lifecycle_events',
        'company_admin_invitations',
        'migrations',
    ];

    public function identityStatement(): string
    {
        return 'SELECT DATABASE()';
    }

    public function versionStatement(): string
    {
        return 'SELECT VERSION() AS server_version';
    }

    public function tableStatement(): string
    {
        return 'SELECT TABLE_NAME AS table_name, ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN (\'tenants\', \'users\', \'company_lifecycle_events\', \'company_admin_invitations\', \'migrations\')';
    }

    public function migrationLedgerStatement(): string
    {
        return 'SELECT migration, batch FROM migrations ORDER BY id';
    }

    public function lifecycleColumnStatement(): string
    {
        return 'SELECT COLUMN_NAME AS column_name, COLUMN_TYPE AS column_type, IS_NULLABLE AS is_nullable, CHARACTER_MAXIMUM_LENGTH AS character_maximum_length, COLUMN_DEFAULT AS column_default, COLLATION_NAME AS collation_name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = \'company_lifecycle_events\' ORDER BY ORDINAL_POSITION';
    }

    public function invitationIndexStatement(): string
    {
        return 'SELECT INDEX_NAME AS index_name, NON_UNIQUE AS non_unique, SEQ_IN_INDEX AS seq_in_index, COLUMN_NAME AS column_name FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = \'company_admin_invitations\' ORDER BY INDEX_NAME, SEQ_IN_INDEX';
    }

    public function invitationForeignKeyStatement(): string
    {
        return 'SELECT CONSTRAINT_NAME AS constraint_name, COLUMN_NAME AS column_name, REFERENCED_TABLE_NAME AS referenced_table_name, REFERENCED_COLUMN_NAME AS referenced_column_name FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND TABLE_NAME = \'company_admin_invitations\' AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY CONSTRAINT_NAME, ORDINAL_POSITION';
    }

    /**
     * @return list<string>
     */
    public function schemaStatements(): array
    {
        return [
            $this->versionStatement(),
            $this->tableStatement(),
            $this->migrationLedgerStatement(),
            $this->lifecycleColumnStatement(),
            $this->invitationIndexStatement(),
            $this->invitationForeignKeyStatement(),
        ];
    }

    /**
     * @param  list<string>  $entries
     * @return list<string>
     */
    public function redactTrace(array $entries): array
    {
        return array_map(function (string $entry): string {
            $entry = preg_replace('/mysql:\/\/\S+/i', 'mysql://redacted', $entry) ?? $entry;
            $entry = preg_replace('/\b(password|pwd)=([^\s]+)/i', '$1=redacted', $entry) ?? $entry;
            $entry = preg_replace('/:\/\/[^:\s\/]+:[^@\s]+@/', '://redacted:redacted@', $entry) ?? $entry;

            return $entry;
        }, $entries);
    }

    /**
     * @param  list<string>  $lifecycleColumnNames
     * @param  array{column_type?: string, character_maximum_length?: int|string|null, is_nullable?: string|bool}|null  $subjectEmail
     * @return array{classification: string, reasons: list<string>}
     */
    public function classify(bool $lifecycleExists, bool $invitationsExist, array $lifecycleColumnNames, ?array $subjectEmail): array
    {
        $reasons = [];

        if (! $lifecycleExists) {
            $reasons[] = 'company_lifecycle_events is absent';
        }

        if (! $invitationsExist) {
            $reasons[] = 'company_admin_invitations is absent';
        }

        $present = array_map(strtolower(...), $lifecycleColumnNames);

        foreach (self::REQUIRED_LIFECYCLE_COLUMNS as $column) {
            if ($lifecycleExists && ! in_array($column, $present, true)) {
                $reasons[] = 'company_lifecycle_events.'.$column.' is absent';
            }
        }

        $emailProblem = $subjectEmail !== null && ! $this->subjectEmailMatches($subjectEmail);
        $defaultProblem = $subjectEmail !== null && ! $this->defaultIsCompatible($subjectEmail);

        if ($reasons !== [] || $emailProblem || $defaultProblem) {
            if ($lifecycleExists && $subjectEmail === null) {
                $reasons[] = 'subject_email is absent';
            }

            if ($emailProblem) {
                $reasons[] = 'subject_email has an incompatible type, length, or nullability';
            }

            if ($defaultProblem) {
                $reasons[] = 'subject_email has a non-NULL default';
            }

            return [
                'classification' => 'unsafe_incomplete',
                'reasons' => $reasons,
            ];
        }

        if ($subjectEmail === null) {
            return [
                'classification' => 'repair_necessary',
                'reasons' => ['subject_email is absent'],
            ];
        }

        return [
            'classification' => 'repair_unnecessary',
            'reasons' => ['subject_email is nullable varchar(191) with no default'],
        ];
    }

    /**
     * Runs the inspector's SQL sequence. The first statement is always SELECT DATABASE().
     * A mismatch returns before any metadata statement is sent to the reader.
     *
     * @return array{status: string, trace: list<string>, lines: list<string>, message: ?string}
     */
    public function read(MigrationInspectionReader $reader, string $expected): array
    {
        $trace = [];
        $select = function (string $sql, array $params = []) use ($reader, &$trace): array {
            $trace[] = $this->redactTrace([$sql])[0];
            $rows = $reader->select($sql, $params);
            $normalized = [];

            foreach ($rows as $row) {
                $normalized[] = $this->normalizeRow($row);
            }

            return $normalized;
        };

        $identityRows = $select($this->identityStatement());
        $selected = $identityRows[0]['database()'] ?? null;
        $selectedName = is_string($selected) ? strtolower($selected) : null;
        $selection = $this->selectionRefusal($expected, $selectedName);

        if ($selection !== null) {
            return [
                'status' => 'mismatch',
                'trace' => $trace,
                'lines' => [],
                'message' => $selection,
            ];
        }

        $versionRows = $select($this->versionStatement());
        $serverVersion = isset($versionRows[0]['server_version']) ? (string) $versionRows[0]['server_version'] : null;
        $tableRows = $select($this->tableStatement(), [$expected]);
        $tables = [];

        foreach ($tableRows as $row) {
            $tables[(string) ($row['table_name'] ?? '')] = [
                'engine' => isset($row['engine']) ? (string) $row['engine'] : null,
            ];
        }

        $existing = array_map(strtolower(...), array_keys($tables));
        $migrations = null;
        $lifecycleColumns = [];
        $invitationIndexes = null;
        $invitationForeignKeys = null;

        if (in_array('migrations', $existing, true)) {
            $migrations = $select($this->migrationLedgerStatement());
        }

        if (in_array('company_lifecycle_events', $existing, true)) {
            $lifecycleColumns = $select($this->lifecycleColumnStatement(), [$expected]);
        }

        if (in_array('company_admin_invitations', $existing, true)) {
            $invitationIndexes = $select($this->invitationIndexStatement(), [$expected]);
            $invitationForeignKeys = $select($this->invitationForeignKeyStatement(), [$expected]);
        }

        return [
            'status' => 'ok',
            'trace' => $trace,
            'lines' => $this->reportLines($serverVersion, $tables, $lifecycleColumns, $migrations, $invitationIndexes, $invitationForeignKeys),
            'message' => null,
        ];
    }

    /**
     * @param  array<string, array{engine?: string|null}>  $tables
     * @param  list<array{column_name: string, column_type?: string, is_nullable?: string, character_maximum_length?: int|string|null, column_default?: mixed, collation_name?: string|null}>  $lifecycleColumns
     * @param  list<array<string, mixed>>|null  $migrations
     * @param  list<array<string, mixed>>|null  $invitationIndexes
     * @param  list<array<string, mixed>>|null  $invitationForeignKeys
     * @return list<string>
     */
    public function reportLines(
        ?string $serverVersion,
        array $tables,
        array $lifecycleColumns,
        ?array $migrations,
        ?array $invitationIndexes,
        ?array $invitationForeignKeys,
    ): array {
        $lines = [];
        $lines[] = 'server_version='.($serverVersion !== null && $serverVersion !== '' ? $serverVersion : 'unavailable');

        foreach (self::REPORTED_TABLES as $table) {
            $found = null;

            foreach ($tables as $name => $meta) {
                if (strcasecmp((string) $name, $table) === 0) {
                    $found = $meta;
                    break;
                }
            }

            if ($found === null) {
                $lines[] = $table.'=absent';
                continue;
            }

            $engine = isset($found['engine']) && is_string($found['engine']) && $found['engine'] !== '' ? $found['engine'] : 'unknown';
            $lines[] = $table.'=present engine='.$engine;
        }

        $names = array_map(fn (array $column): string => strtolower((string) $column['column_name']), $lifecycleColumns);
        $lifecycleExists = $this->tablePresent($tables, 'company_lifecycle_events');

        foreach (self::REQUIRED_LIFECYCLE_COLUMNS as $column) {
            $lines[] = 'lifecycle_column='.$column.' '.($lifecycleExists && in_array($column, $names, true) ? 'present' : 'absent');
        }

        $subject = null;

        foreach ($lifecycleColumns as $column) {
            if (strcasecmp((string) $column['column_name'], 'subject_email') === 0) {
                $subject = $column;
                break;
            }
        }

        if (! $lifecycleExists || $subject === null) {
            $lines[] = 'subject_email=absent';
        } else {
            $default = array_key_exists('column_default', $subject) && $subject['column_default'] !== null ? (string) $subject['column_default'] : 'null';
            $collation = isset($subject['collation_name']) && is_string($subject['collation_name']) && $subject['collation_name'] !== '' ? $subject['collation_name'] : 'null';
            $length = $subject['character_maximum_length'] ?? 'null';
            $lines[] = 'subject_email=present type='.strtolower((string) ($subject['column_type'] ?? 'unknown'))
                .' length='.(is_scalar($length) ? (string) $length : 'null')
                .' nullable='.strtolower((string) ($subject['is_nullable'] ?? 'unknown'))
                .' default='.$default
                .' collation='.$collation;
        }

        if ($migrations === null) {
            $lines[] = 'migrations_table=absent';
            $lines[] = 'migration_count=unavailable';
        } else {
            $lines[] = 'migrations_table=present';
            $lines[] = 'migration_count='.count($migrations);

            foreach ($migrations as $row) {
                $lines[] = 'migration='.(string) ($row['migration'] ?? '').' batch='.(string) ($row['batch'] ?? '');
            }
        }

        if ($invitationIndexes === null) {
            $lines[] = 'invitation_indexes=unavailable';
        } else {
            $lines[] = 'invitation_index_count='.count($invitationIndexes);

            foreach ($invitationIndexes as $row) {
                $lines[] = 'invitation_index='.(string) ($row['index_name'] ?? '')
                    .' column='.(string) ($row['column_name'] ?? '')
                    .' seq='.(string) ($row['seq_in_index'] ?? '')
                    .' non_unique='.(string) ($row['non_unique'] ?? '');
            }
        }

        if ($invitationForeignKeys === null) {
            $lines[] = 'invitation_foreign_keys=unavailable';
        } else {
            $lines[] = 'invitation_foreign_key_count='.count($invitationForeignKeys);

            foreach ($invitationForeignKeys as $row) {
                $lines[] = 'invitation_foreign_key='.(string) ($row['constraint_name'] ?? '')
                    .' column='.(string) ($row['column_name'] ?? '')
                    .' references='.(string) ($row['referenced_table_name'] ?? '').'.'.(string) ($row['referenced_column_name'] ?? '');
            }
        }

        $classification = $this->classify(
            $lifecycleExists,
            $this->tablePresent($tables, 'company_admin_invitations'),
            $lifecycleExists ? $names : [],
            $subject,
        );
        $lines[] = 'classification='.$classification['classification'];

        foreach ($classification['reasons'] as $reason) {
            $lines[] = 'reason='.$reason;
        }

        $lines[] = 'verification_does_not_authorize_migrations=1';

        return $lines;
    }

    public function preflight(
        ?string $expected,
        string $driver,
        ?string $configuredDatabase,
        ?string $dbUrl,
        ?string $databaseUrl,
        bool $configCached,
    ): ?string {
        if ($configCached) {
            return 'Refusing the inspection because bootstrap/cache/config.php is present. A cached configuration can point at another database.';
        }

        if ($this->present($dbUrl) || $this->present($databaseUrl)) {
            return 'Refusing the inspection because DB_URL or DATABASE_URL is set. Unset both. A database URL can replace the selected database.';
        }

        if (! is_string($expected) || trim($expected) === '') {
            return 'Refusing the inspection because the expected database name is missing.';
        }

        if (str_contains($expected, '://') || str_contains($expected, '@') || preg_match('/\s/', $expected) === 1) {
            return 'Refusing the inspection because the expected database name is not a plain database name.';
        }

        if ($this->denied($expected)) {
            return 'Refusing the inspection because '.$expected.' is a known live, rehearsal, or browser database.';
        }

        if (preg_match(self::PATTERN, $expected) !== 1) {
            return 'Refusing the inspection because the database name must match meetara_hrms_inspect_ plus an eight-digit date.';
        }

        if ($driver !== 'mysql') {
            return 'Refusing the inspection because HRMS_INSPECT_DRIVER must be mysql. SQLite and every other driver are rejected.';
        }

        if ($this->present($configuredDatabase) && $configuredDatabase !== $expected) {
            return 'Refusing the inspection because DB_DATABASE does not match the expected database. The name in .env is not an authorization.';
        }

        if ($this->present($configuredDatabase) && $this->denied($configuredDatabase)) {
            return 'Refusing the inspection because DB_DATABASE names a known live, rehearsal, or browser database.';
        }

        return null;
    }

    public function selectionRefusal(string $expected, ?string $selected): ?string
    {
        if ($selected !== $expected || $this->denied($selected ?? '')) {
            return 'Refusing the inspection because SELECT DATABASE() did not match the expected database. No ledger or column query was run.';
        }

        return null;
    }

    public function schemaReadsAllowed(string $expected, ?string $selected): bool
    {
        return $this->selectionRefusal($expected, $selected) === null
            && $this->preflight($expected, 'mysql', $expected, null, null, false) === null;
    }

    public function statementsAreReadOnly(): bool
    {
        $statements = array_merge([$this->identityStatement()], $this->schemaStatements());

        foreach ($statements as $statement) {
            if (! str_starts_with($statement, 'SELECT ') || preg_match('/\b(INSERT|UPDATE|DELETE|ALTER|DROP|CREATE|TRUNCATE|REPLACE|GRANT|CALL)\b/i', $statement) === 1) {
                return false;
            }
        }

        return $statements !== [];
    }

    /**
     * @param  array{column_type?: string, character_maximum_length?: int|string|null, is_nullable?: string|bool}  $column
     */
    private function subjectEmailMatches(array $column): bool
    {
        $type = strtolower((string) ($column['column_type'] ?? ''));
        $nullable = $column['is_nullable'] ?? null;
        $isNullable = $nullable === true || (is_string($nullable) && strcasecmp($nullable, 'YES') === 0);
        $length = $column['character_maximum_length'] ?? null;

        return $isNullable && ($type === 'varchar(191)' || ((int) $length === 191 && str_starts_with($type, 'varchar')));
    }

    /**
     * information_schema uses null both when the column has no DEFAULT clause and when it is DEFAULT NULL.
     *
     * @param  array{column_default?: mixed}  $column
     */
    private function defaultIsCompatible(array $column): bool
    {
        return ! array_key_exists('column_default', $column) || $column['column_default'] === null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row): array
    {
        $normalized = [];

        foreach ($row as $key => $value) {
            $normalized[strtolower((string) $key)] = $value;
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $tables
     */
    private function tablePresent(array $tables, string $name): bool
    {
        foreach (array_keys($tables) as $table) {
            if (strcasecmp((string) $table, $name) === 0) {
                return true;
            }
        }

        return false;
    }

    private function denied(string $name): bool
    {
        foreach (self::DENIED as $denied) {
            if (strcasecmp($name, $denied) === 0) {
                return true;
            }
        }

        return false;
    }

    private function present(?string $value): bool
    {
        return is_string($value) && $value !== '';
    }
}
