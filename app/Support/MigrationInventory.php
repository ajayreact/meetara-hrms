<?php

namespace App\Support;

/**
 * File-only view of the migration tree and the schema dump.
 * It does not open a database connection.
 */
class MigrationInventory
{
    public const SCHEMA_DUMP = 'database/schema/mysql-schema.sql';

    public const SCHEMA_DUMP_SHA256 = '565FF15940792A4530297648E3B7106061A76A0E6FD54E2D3721A84580542836';

    public const SCHEMA_DUMP_BYTES = 163031;

    /**
     * Names recorded in the schema dump whose PHP files are not in the repository.
     *
     * @var list<string>
     */
    public const MISSING_HISTORICAL = [
        '2026_10_19_023112_create_permission_tables',
        '2026_10_19_100000_create_hrms_tables',
        '2026_10_19_120000_create_remaining_phase_tables',
        '2026_10_19_130000_add_holiday_calendars',
        '2026_10_19_140000_add_office_wifi_and_wfh',
        '2026_10_19_140000_add_payroll_day_counts',
        '2026_10_19_160000_add_profile_details_and_tickets',
    ];

    /**
     * Files on disk that the last documented live ledger left unrecorded.
     * An unrestricted migrate would apply them. They are not approved here.
     *
     * @var list<string>
     */
    public const UNREVIEWED_FOR_RECORDED_HISTORY = [
        '2026_10_20_120000_add_company_lifecycle_events',
        '2026_10_20_140000_add_company_admin_invitations',
    ];

    public const SUBJECT_EMAIL_REPAIR = '2026_10_21_120000_repair_company_lifecycle_subject_email';

    /**
     * @return list<string>
     */
    public function dumpNames(): array
    {
        preg_match_all("/INSERT INTO `migrations` \(`id`, `migration`, `batch`\) VALUES \(\d+,'([^']+)',\d+\);/", $this->schemaDump(), $matches);

        return $matches[1];
    }

    /**
     * @return list<string>
     */
    public function diskNames(): array
    {
        $names = [];

        foreach (glob($this->root().DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations'.DIRECTORY_SEPARATOR.'*.php') ?: [] as $path) {
            $names[] = basename($path, '.php');
        }

        sort($names);

        return $names;
    }

    /**
     * @return list<string>
     */
    public function missingFiles(): array
    {
        return array_values(array_diff($this->dumpNames(), $this->diskNames()));
    }

    /**
     * Migration files that an empty database would still run after the schema dump loads.
     *
     * @return list<string>
     */
    public function filesAfterDump(): array
    {
        return $this->pendingFiles($this->dumpNames());
    }

    /**
     * @param  list<string>  $recordedNames
     * @return list<string>
     */
    public function pendingFiles(array $recordedNames): array
    {
        $pending = array_values(array_diff($this->diskNames(), $recordedNames));
        sort($pending);

        return $pending;
    }

    public function schemaDumpMatchesRelease(): bool
    {
        $path = $this->schemaPath();

        return is_file($path)
            && filesize($path) === self::SCHEMA_DUMP_BYTES
            && hash_equals(strtolower(self::SCHEMA_DUMP_SHA256), hash_file('sha256', $path));
    }

    public function invitationColumnRequiresLifecycleTable(): bool
    {
        $invitation = (string) file_get_contents($this->root().'/database/migrations/2026_10_20_140000_add_company_admin_invitations.php');
        $lifecycle = (string) file_get_contents($this->root().'/database/migrations/2026_10_20_120000_add_company_lifecycle_events.php');

        $columnGuard = strpos($invitation, 'requires company_lifecycle_events.subject_email');
        $createInvitations = strpos($invitation, "Schema::create('company_admin_invitations'");

        return str_contains($lifecycle, "Schema::create('company_lifecycle_events'")
            && str_contains($invitation, 'requires company_lifecycle_events. Apply 2026_10_20_120000_add_company_lifecycle_events first.')
            && $columnGuard !== false
            && $createInvitations !== false
            && $columnGuard < $createInvitations
            && str_contains($invitation, 'throw new \\RuntimeException');
    }

    public function subjectEmailRepairAddsMissingColumnOnly(): bool
    {
        $repair = (string) file_get_contents($this->root().'/database/migrations/'.self::SUBJECT_EMAIL_REPAIR.'.php');
        $add = strpos($repair, "\$table->string('subject_email', 191)->nullable()");
        $createLifecycle = strpos($repair, "Schema::create('company_lifecycle_events'");
        $createInvitations = strpos($repair, "Schema::create('company_admin_invitations'");

        return str_contains($repair, 'requires company_lifecycle_events and company_admin_invitations')
            && str_contains($repair, 'incompatible definition')
            && str_contains($repair, 'MySQL commits each DDL statement')
            && $add !== false
            && $createLifecycle === false
            && $createInvitations === false
            && str_contains($repair, 'must not be rolled back');
    }

    private function schemaDump(): string
    {
        return (string) file_get_contents($this->schemaPath());
    }

    private function schemaPath(): string
    {
        return $this->root().DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, self::SCHEMA_DUMP);
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
