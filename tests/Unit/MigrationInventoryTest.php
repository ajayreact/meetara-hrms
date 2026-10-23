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
        $this->assertSame(MigrationInventory::MISSING_HISTORICAL, $inventory->missingFiles());
        $this->assertSame([
            '2026_10_20_120000_add_company_lifecycle_events',
            '2026_10_20_140000_add_company_admin_invitations',
            '2026_10_20_160000_extend_employee_onboarding_documents',
            '2026_10_20_180000_add_offer_letters',
            '2026_10_20_200000_add_employee_record_event_note',
            '2026_10_21_120000_repair_company_lifecycle_subject_email',
            '2026_10_21_150000_add_office_location_verification',
            '2026_10_22_120000_add_route_map_gps_capture',
            '2026_10_23_200000_add_candidate_onboarding',
        ], $inventory->filesAfterDump());
    }

    public function test_an_unrestricted_migrate_would_apply_unreviewed_files_to_the_documented_ledger(): void
    {
        $inventory = new MigrationInventory;
        $documentedLedger = [
            ...$inventory->dumpNames(),
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
        ], $inventory->pendingFiles($documentedLedger));
        $this->assertTrue($inventory->invitationColumnRequiresLifecycleTable());
        $this->assertTrue($inventory->subjectEmailRepairAddsMissingColumnOnly());
        $dump = (string) file_get_contents(dirname(__DIR__, 2).'/database/schema/mysql-schema.sql');
        $this->assertStringNotContainsString('company_lifecycle_events', $dump);
        $this->assertStringNotContainsString('company_admin_invitations', $dump);
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
