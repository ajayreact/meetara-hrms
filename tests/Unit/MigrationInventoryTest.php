<?php

namespace Tests\Unit;

use App\Support\MigrationInventory;
use PHPUnit\Framework\TestCase;

class MigrationInventoryTest extends TestCase
{
    public function test_the_schema_dump_matches_the_recorded_release_and_stops_before_later_files(): void
    {
        $inventory = new MigrationInventory;

        $this->assertTrue($inventory->schemaDumpMatchesRelease());
        $this->assertCount(49, $inventory->dumpNames());
        $this->assertSame(MigrationInventory::MISSING_HISTORICAL, $inventory->missingFiles());
        $this->assertSame([
            '2026_11_03_220000_add_company_legal_name',
            '2026_11_04_030000_add_organization_catalog_status',
            '2026_11_04_040000_add_i983_compensation_and_site_arrangement',
            '2026_11_04_050000_reassign_accounts_only_users_to_owner',
            '2026_11_04_060000_add_i983_employer_profiles',
        ], $inventory->filesAfterDump());
    }

    public function test_an_unrestricted_migrate_would_apply_unreviewed_files_to_the_documented_ledger(): void
    {
        $inventory = new MigrationInventory;
        $documentedLedger = [
            '0001_01_01_000000_create_users_table',
            '0001_01_01_000001_create_cache_table',
            '0001_01_01_000002_create_jobs_table',
            ...MigrationInventory::MISSING_HISTORICAL,
            '2026_10_19_200000_add_office_networks_and_attendance_breaks',
            '2026_10_19_210000_add_holiday_status_and_leave_events',
            '2026_10_19_220000_add_payroll_workflow_tables',
            '2026_10_19_230000_add_role_assignment_events',
            '2026_10_19_240000_add_company_payroll_settings',
            '2026_10_19_250000_add_help_desk_overtime_and_audit_tables',
            '2026_10_19_260000_point_ticket_assignee_at_users',
            '2026_10_19_270000_convert_workflow_tables_to_innodb',
            '2026_10_20_160000_extend_employee_onboarding_documents',
            '2026_10_20_180000_add_offer_letters',
            '2026_10_20_200000_add_employee_record_event_note',
        ];

        $this->assertSame([
            ...MigrationInventory::UNREVIEWED_FOR_RECORDED_HISTORY,
            MigrationInventory::SUBJECT_EMAIL_REPAIR,
            '2026_10_21_150000_add_office_location_verification',
            '2026_10_22_120000_add_route_map_gps_capture',
            '2026_10_23_200000_add_candidate_onboarding',
            '2026_10_30_140000_store_attendance_verification_coordinates',
            '2026_10_30_160000_add_offer_letter_templates',
            '2026_10_30_180000_add_followups_tasks_and_birthdays',
            '2026_10_30_200000_add_appointment_letter_layout_and_signatures',
            '2026_10_30_210000_scope_legal_profiles_by_country',
            '2026_10_30_220000_add_offer_letter_notice_days_and_snapshots',
            '2026_11_02_120000_add_offer_approval_setting',
            '2026_11_02_180000_add_offer_salutation_and_letterhead_confirmation',
            '2026_11_02_200000_add_payroll_import_snapshots',
            '2026_11_02_210000_add_employee_invitation_expiry',
            '2026_11_02_220000_add_attendance_corrections',
            '2026_11_02_230000_add_letterhead_identity_and_offer_corrections',
            '2026_11_02_240000_add_hr_announcements',
            '2026_11_02_250000_add_document_catalog',
            '2026_11_02_260000_add_usa_offer_designations',
            '2026_11_02_270000_add_usa_offer_stationery',
            '2026_11_02_280000_add_i983_cases',
            '2026_11_02_290000_add_i983_corrections',
            '2026_11_02_300000_add_i983_document_versions',
            '2026_11_02_310000_add_i983_signatures',
            '2026_11_03_120000_add_offer_candidate_address_and_usa_employment_type',
            '2026_11_03_160000_add_i983_student_certifications',
            '2026_11_03_220000_add_company_legal_name',
            '2026_11_04_030000_add_organization_catalog_status',
            '2026_11_04_040000_add_i983_compensation_and_site_arrangement',
            '2026_11_04_050000_reassign_accounts_only_users_to_owner',
            '2026_11_04_060000_add_i983_employer_profiles',
        ], $inventory->pendingFiles($documentedLedger));
        $this->assertTrue($inventory->invitationColumnRequiresLifecycleTable());
        $this->assertTrue($inventory->subjectEmailRepairAddsMissingColumnOnly());
        $dump = (string) file_get_contents(dirname(__DIR__, 2).'/database/schema/mysql-schema.sql');
        $this->assertStringContainsString('company_lifecycle_events', $dump);
        $this->assertStringContainsString('company_admin_invitations', $dump);
        $this->assertSame(49, preg_match_all('/^INSERT INTO `migrations`/m', $dump));
        $docs = (string) file_get_contents(dirname(__DIR__, 2).'/docs/production.md');
        $this->assertStringContainsString(
            '`2026_10_20_120000_add_company_lifecycle_events` must run before `2026_10_20_140000_add_company_admin_invitations`',
            $docs
        );
        $this->assertStringContainsString('Neither file is approved for `meetara_hrms`', $docs);
        $this->assertStringContainsString('separate reviewed repair migration', $docs);
        $this->assertStringContainsString('2026_10_21_120000_repair_company_lifecycle_subject_email', $docs);
        $this->assertStringContainsString('The repair is not approved for `meetara_hrms`', $docs);
    }
}
