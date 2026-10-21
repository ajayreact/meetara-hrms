<?php

namespace Tests\Unit;

use App\Support\MigrationInspection;
use App\Support\MigrationInspectionReader;
use PHPUnit\Framework\TestCase;

class MigrationInspectionTest extends TestCase
{
    public function test_known_databases_are_refused_before_a_connection(): void
    {
        $inspection = new MigrationInspection;

        foreach (MigrationInspection::DENIED as $name) {
            $refusal = $inspection->preflight($name, 'mysql', null, null, null, false);
            $this->assertNotNull($refusal, $name);
            $this->assertStringContainsString($name, (string) $refusal);
        }

        $this->assertNotNull($inspection->preflight('MEETARA_HRMS', 'mysql', null, null, null, false));
        $this->assertNotNull($inspection->preflight('meetara_hrms_stage41c_20261021', 'mysql', null, null, null, false));
    }

    public function test_only_the_inspection_pattern_passes_preflight(): void
    {
        $inspection = new MigrationInspection;
        $expected = 'meetara_hrms_inspect_20261021';

        $this->assertNull($inspection->preflight($expected, 'mysql', null, null, null, false));
        $this->assertNull($inspection->preflight($expected, 'mysql', $expected, null, null, false));
        $this->assertNotNull($inspection->preflight('meetara_hrms_inspect_20261021_extra', 'mysql', null, null, null, false));
        $this->assertNotNull($inspection->preflight('meetara_hrms_stage41b_valid_20261021', 'mysql', null, null, null, false));
        $this->assertNotNull($inspection->preflight(null, 'mysql', null, null, null, false));
        $this->assertNotNull($inspection->preflight('mysql://root@127.0.0.1/meetara_hrms_inspect_20261021', 'mysql', null, null, null, false));
    }

    public function test_sqlite_urls_cached_config_and_a_different_configured_database_are_refused(): void
    {
        $inspection = new MigrationInspection;
        $expected = 'meetara_hrms_inspect_20261021';

        $this->assertStringContainsString('mysql', (string) $inspection->preflight($expected, 'sqlite', null, null, null, false));
        $this->assertStringContainsString('mysql', (string) $inspection->preflight($expected, '', null, null, null, false));
        $this->assertNotNull($inspection->preflight($expected, 'mysql', null, 'mysql://root@127.0.0.1/meetara_hrms', null, false));
        $this->assertNotNull($inspection->preflight($expected, 'mysql', null, null, 'mysql://root@127.0.0.1/meetara_hrms', false));
        $this->assertNotNull($inspection->preflight($expected, 'mysql', null, null, null, true));
        $this->assertStringContainsString('DB_DATABASE', (string) $inspection->preflight($expected, 'mysql', 'meetara_hrms', null, null, false));
    }

    public function test_a_selected_database_mismatch_blocks_schema_reads(): void
    {
        $inspection = new MigrationInspection;
        $expected = 'meetara_hrms_inspect_20261021';

        $this->assertFalse($inspection->schemaReadsAllowed($expected, 'meetara_hrms'));
        $this->assertFalse($inspection->schemaReadsAllowed($expected, 'meetara_hrms_stage41c_20261021'));
        $this->assertFalse($inspection->schemaReadsAllowed($expected, null));
        $this->assertTrue($inspection->schemaReadsAllowed($expected, $expected));
        $this->assertStringContainsString('SELECT DATABASE()', (string) $inspection->selectionRefusal($expected, 'meetara_hrms'));
        $this->assertTrue($inspection->statementsAreReadOnly());
    }

    public function test_the_script_does_not_call_migration_methods_or_connect_without_read(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 2).'/scripts/inspect-migration-state.php');
        $inspection = new MigrationInspection;
        $this->assertSame('SELECT DATABASE()', $inspection->identityStatement());
        $preflight = strpos($script, 'preflight(');
        $pdo = strpos($script, 'new PDO(');
        $read = strpos($script, '->read(');
        $this->assertNotFalse($preflight);
        $this->assertNotFalse($pdo);
        $this->assertNotFalse($read);
        $this->assertLessThan($pdo, $preflight);
        $this->assertLessThan($read, $pdo);
        $this->assertStringNotContainsString('getMessage()', $script);
        $this->assertStringNotContainsString('echo $password', $script);
        $this->assertStringNotContainsString('echo $host', $script);
        $this->assertStringContainsString("fwrite(STDERR, 'MySQL connection failed.'.PHP_EOL);", $script);
        $this->assertStringNotContainsString('Artisan', $script);
        $this->assertStringNotContainsString('Schema::', $script);
        $this->assertStringNotContainsString('migrate:', $script);
        $this->assertStringNotContainsString('Illuminate\\Support\\Facades', $script);

        $withoutRead = $this->runScript(['meetara_hrms_inspect_20261021']);
        $this->assertSame(0, $withoutRead['exit']);
        $this->assertStringContainsString('No connection was opened', $withoutRead['output']);

        $live = $this->runScript(['meetara_hrms']);
        $this->assertSame(2, $live['exit']);
        $this->assertStringContainsString('meetara_hrms', $live['error']);
        $this->assertStringNotContainsString('No connection was opened', $live['output']);

        $stage41c = $this->runScript(['meetara_hrms_stage41c_20261021']);
        $this->assertSame(2, $stage41c['exit']);
        $this->assertStringContainsString('meetara_hrms_stage41c_20261021', $stage41c['error']);
    }

    public function test_classification_covers_necessary_unnecessary_and_unsafe_states(): void
    {
        $inspection = new MigrationInspection;
        $columns = MigrationInspection::REQUIRED_LIFECYCLE_COLUMNS;
        $correct = ['column_type' => 'varchar(191)', 'character_maximum_length' => 191, 'is_nullable' => 'YES'];

        $necessary = $inspection->classify(true, true, $columns, null);
        $this->assertSame('repair_necessary', $necessary['classification']);
        $this->assertContains('subject_email is absent', $necessary['reasons']);

        $unnecessary = $inspection->classify(true, true, $columns, $correct);
        $this->assertSame('repair_unnecessary', $unnecessary['classification']);
        $this->assertContains('subject_email is nullable varchar(191) with no default', $unnecessary['reasons']);

        $explicitNull = $inspection->classify(true, true, $columns, $correct + ['column_default' => null]);
        $this->assertSame('repair_unnecessary', $explicitNull['classification']);
        $this->assertContains('subject_email is nullable varchar(191) with no default', $explicitNull['reasons']);

        $nonNullDefault = $inspection->classify(true, true, $columns, $correct + ['column_default' => 'kept@northwind.test']);
        $this->assertSame('unsafe_incomplete', $nonNullDefault['classification']);
        $this->assertContains('subject_email has a non-NULL default', $nonNullDefault['reasons']);

        $stringNull = $inspection->classify(true, true, $columns, $correct + ['column_default' => 'NULL']);
        $this->assertSame('unsafe_incomplete', $stringNull['classification']);

        $missingLifecycle = $inspection->classify(false, true, [], null);
        $this->assertSame('unsafe_incomplete', $missingLifecycle['classification']);
        $this->assertContains('company_lifecycle_events is absent', $missingLifecycle['reasons']);

        $missingInvitations = $inspection->classify(true, false, $columns, null);
        $this->assertSame('unsafe_incomplete', $missingInvitations['classification']);
        $this->assertContains('company_admin_invitations is absent', $missingInvitations['reasons']);

        $missingAction = $inspection->classify(true, true, ['id', 'tenant_id', 'actor_id', 'created_at'], null);
        $this->assertSame('unsafe_incomplete', $missingAction['classification']);
        $this->assertContains('company_lifecycle_events.action is absent', $missingAction['reasons']);

        $wrongLength = $inspection->classify(true, true, $columns, ['column_type' => 'varchar(100)', 'character_maximum_length' => 100, 'is_nullable' => 'YES']);
        $this->assertSame('unsafe_incomplete', $wrongLength['classification']);

        $notNull = $inspection->classify(true, true, $columns, ['column_type' => 'varchar(191)', 'character_maximum_length' => 191, 'is_nullable' => 'NO']);
        $this->assertSame('unsafe_incomplete', $notNull['classification']);

        $integer = $inspection->classify(true, true, $columns, ['column_type' => 'int', 'character_maximum_length' => null, 'is_nullable' => 'YES']);
        $this->assertSame('unsafe_incomplete', $integer['classification']);
        $this->assertContains('subject_email has an incompatible type, length, or nullability', $integer['reasons']);
    }

    public function test_missing_objects_are_reported_as_absent_rather_than_healthy(): void
    {
        $inspection = new MigrationInspection;
        $lines = $inspection->reportLines(null, [], [], null, null, null);
        $text = implode("\n", $lines);

        $this->assertStringContainsString('server_version=unavailable', $text);
        $this->assertStringContainsString('tenants=absent', $text);
        $this->assertStringContainsString('users=absent', $text);
        $this->assertStringContainsString('company_lifecycle_events=absent', $text);
        $this->assertStringContainsString('company_admin_invitations=absent', $text);
        $this->assertStringContainsString('lifecycle_column=action absent', $text);
        $this->assertStringContainsString('subject_email=absent', $text);
        $this->assertStringContainsString('migrations_table=absent', $text);
        $this->assertStringContainsString('migration_count=unavailable', $text);
        $this->assertStringContainsString('invitation_indexes=unavailable', $text);
        $this->assertStringContainsString('invitation_foreign_keys=unavailable', $text);
        $this->assertStringContainsString('classification=unsafe_incomplete', $text);
        $this->assertStringContainsString('verification_does_not_authorize_migrations=1', $text);

        $present = $inspection->reportLines(
            '8.4.7',
            [
                'tenants' => ['engine' => 'InnoDB'],
                'users' => ['engine' => 'InnoDB'],
                'company_lifecycle_events' => ['engine' => 'InnoDB'],
                'company_admin_invitations' => ['engine' => 'InnoDB'],
                'migrations' => ['engine' => 'InnoDB'],
            ],
            [
                ['column_name' => 'id'],
                ['column_name' => 'tenant_id'],
                ['column_name' => 'actor_id'],
                ['column_name' => 'action'],
                ['column_name' => 'created_at'],
                ['column_name' => 'subject_email', 'column_type' => 'varchar(191)', 'character_maximum_length' => 191, 'is_nullable' => 'YES', 'column_default' => null, 'collation_name' => 'utf8mb4_unicode_ci'],
            ],
            [['migration' => '2026_10_20_140000_add_company_admin_invitations', 'batch' => 2]],
            [['index_name' => 'company_admin_invitations_token_hash_unique', 'column_name' => 'token_hash', 'seq_in_index' => 1, 'non_unique' => 0]],
            [['constraint_name' => 'company_admin_invitations_tenant_id_foreign', 'column_name' => 'tenant_id', 'referenced_table_name' => 'tenants', 'referenced_column_name' => 'id']],
        );
        $presentText = implode("\n", $present);
        $this->assertStringContainsString('server_version=8.4.7', $presentText);
        $this->assertStringContainsString('lifecycle_column=created_at present', $presentText);
        $this->assertStringContainsString('subject_email=present type=varchar(191) length=191 nullable=yes default=null collation=utf8mb4_unicode_ci', $presentText);
        $this->assertStringContainsString('migration_count=1', $presentText);
        $this->assertStringContainsString('migration=2026_10_20_140000_add_company_admin_invitations batch=2', $presentText);
        $this->assertStringContainsString('invitation_index=company_admin_invitations_token_hash_unique column=token_hash', $presentText);
        $this->assertStringContainsString('invitation_foreign_key=company_admin_invitations_tenant_id_foreign column=tenant_id references=tenants.id', $presentText);
        $this->assertStringContainsString('classification=repair_unnecessary', $presentText);
        $this->assertStringContainsString('reason=subject_email is nullable varchar(191) with no default', $presentText);
        $this->assertStringContainsString('default=null', $presentText);
        $this->assertStringNotContainsString('@', $presentText);

        $withDefault = $inspection->reportLines(
            '8.4.7',
            [
                'company_lifecycle_events' => ['engine' => 'InnoDB'],
                'company_admin_invitations' => ['engine' => 'InnoDB'],
            ],
            [
                ['column_name' => 'id'],
                ['column_name' => 'tenant_id'],
                ['column_name' => 'actor_id'],
                ['column_name' => 'action'],
                ['column_name' => 'created_at'],
                ['column_name' => 'subject_email', 'column_type' => 'varchar(191)', 'character_maximum_length' => 191, 'is_nullable' => 'YES', 'column_default' => 'kept@northwind.test', 'collation_name' => 'utf8mb4_unicode_ci'],
            ],
            null,
            [],
            [],
        );
        $defaultText = implode("\n", $withDefault);
        $this->assertStringContainsString('default=kept@northwind.test', $defaultText);
        $this->assertStringContainsString('classification=unsafe_incomplete', $defaultText);
        $this->assertStringContainsString('reason=subject_email has a non-NULL default', $defaultText);
    }

    public function test_query_trace_stays_read_only_and_stops_before_metadata_when_identity_differs(): void
    {
        $inspection = new MigrationInspection;
        $expected = 'meetara_hrms_inspect_20261021';
        $this->assertTrue($inspection->statementsAreReadOnly());

        $redacted = $inspection->redactTrace([
            'SELECT DATABASE()',
            'mysql://root:secret@127.0.0.1/meetara_hrms',
            'password=secret',
        ]);
        $this->assertSame('SELECT DATABASE()', $redacted[0]);
        $this->assertStringNotContainsString('secret', implode("\n", $redacted));
        $this->assertStringNotContainsString('meetara_hrms', $redacted[1]);

        $expected = 'meetara_hrms_inspect_20261021';
        $mismatchReader = new RecordingInspectionReader([
            'SELECT DATABASE()' => [['DATABASE()' => 'meetara_hrms']],
        ]);
        $mismatch = $inspection->read($mismatchReader, $expected);
        $this->assertSame('mismatch', $mismatch['status']);
        $this->assertSame(['SELECT DATABASE()'], $mismatch['trace']);
        $this->assertSame(['SELECT DATABASE()'], $mismatchReader->sql);
        $this->assertSame([], $mismatch['lines']);
        $this->assertStringNotContainsString('mysql://', (string) $mismatch['message']);
        $this->assertStringNotContainsString('password', (string) $mismatch['message']);

        $inspectionClass = new MigrationInspection;
        $matchedReader = new RecordingInspectionReader([
            $inspectionClass->identityStatement() => [['DATABASE()' => $expected]],
            $inspectionClass->versionStatement() => [['server_version' => '8.4.7']],
            $inspectionClass->tableStatement() => [],
        ]);
        $matched = $inspectionClass->read($matchedReader, $expected);
        $this->assertSame('ok', $matched['status']);
        $this->assertSame($matchedReader->sql, $matched['trace']);
        $this->assertSame($inspectionClass->identityStatement(), $matched['trace'][0]);
        $this->assertSame($inspectionClass->versionStatement(), $matched['trace'][1]);
        $this->assertNotContains($inspectionClass->migrationLedgerStatement(), $matched['trace']);
        $this->assertStringContainsString('migrations_table=absent', implode("\n", $matched['lines']));
        $this->assertStringContainsString('verification_does_not_authorize_migrations=1', implode("\n", $matched['lines']));

        $withTables = new RecordingInspectionReader([
            $inspectionClass->identityStatement() => [['DATABASE()' => $expected]],
            $inspectionClass->versionStatement() => [['server_version' => '8.4.7']],
            $inspectionClass->tableStatement() => [
                ['table_name' => 'migrations', 'engine' => 'InnoDB'],
                ['table_name' => 'company_lifecycle_events', 'engine' => 'InnoDB'],
                ['table_name' => 'company_admin_invitations', 'engine' => 'InnoDB'],
            ],
            $inspectionClass->migrationLedgerStatement() => [],
            $inspectionClass->lifecycleColumnStatement() => [],
            $inspectionClass->invitationIndexStatement() => [],
            $inspectionClass->invitationForeignKeyStatement() => [],
        ]);
        $recorded = $inspectionClass->read($withTables, $expected);
        $this->assertSame($withTables->sql, $recorded['trace']);
        $this->assertSame($inspectionClass->identityStatement(), $recorded['trace'][0]);
        $this->assertContains($inspectionClass->migrationLedgerStatement(), $recorded['trace']);
        $this->assertGreaterThan(
            array_search($inspectionClass->tableStatement(), $recorded['trace'], true),
            array_search($inspectionClass->migrationLedgerStatement(), $recorded['trace'], true),
        );
    }

    /**
     * @param  list<string>  $arguments
     * @return array{exit: int, output: string, error: string}
     */
    private function runScript(array $arguments): array
    {
        $command = [PHP_BINARY, dirname(__DIR__, 2).'/scripts/inspect-migration-state.php', ...$arguments];
        $environment = [];

        foreach (getenv() as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $environment[$key] = $value;
            }
        }

        unset($environment['DB_DATABASE'], $environment['DB_URL'], $environment['DATABASE_URL'], $environment['HRMS_INSPECT_PASSWORD'], $environment['HRMS_INSPECT_HOST']);
        $environment['HRMS_INSPECT_DRIVER'] = 'mysql';

        $process = proc_open($command, [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, null, $environment);
        $this->assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'exit' => proc_close($process),
            'output' => is_string($output) ? $output : '',
            'error' => is_string($error) ? $error : '',
        ];
    }
}

final class RecordingInspectionReader implements MigrationInspectionReader
{
    /** @var list<string> */
    public array $sql = [];

    /** @param  array<string, list<array<string, mixed>>>  $responses */
    public function __construct(private array $responses) {}

    public function select(string $sql, array $params = []): array
    {
        $this->sql[] = $sql;

        if (! array_key_exists($sql, $this->responses)) {
            throw new RuntimeException('unexpected SQL');
        }

        return $this->responses[$sql];
    }
}
