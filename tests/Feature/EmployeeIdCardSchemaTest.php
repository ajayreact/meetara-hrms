<?php

namespace Tests\Feature;

use App\Models\EmployeeIdCard;
use App\Models\EmployeeIdCardIssue;
use App\Models\IdCardCompanyProfile;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CompanyFixture;
use Tests\TestCase;

class EmployeeIdCardSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_id_card_tables_support_india_and_usa_profiles_without_changing_employees(): void
    {
        foreach (['id_card_company_profiles', 'employee_id_cards', 'employee_id_card_issues'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }

        $this->assertTrue(Schema::hasColumns('id_card_company_profiles', [
            'tenant_id',
            'country',
            'legal_name',
            'display_name',
            'logo_path',
            'logo_mime',
            'white_logo_path',
            'white_logo_mime',
            'signature_path',
            'signature_mime',
            'office_address',
            'contact_number',
            'email',
            'website',
            'footer_address',
            'status',
            'updated_by',
        ]));
        $this->assertTrue(Schema::hasColumns('employee_id_cards', [
            'tenant_id',
            'employee_id',
            'country',
            'company_profile_id',
            'blood_group',
            'emergency_contact_number',
            'designation',
            'department',
            'photo_path',
            'photo_mime',
            'status',
            'card_number',
            'qr_token',
            'issued_at',
            'deactivated_at',
            'reissued_from_id',
            'created_by',
            'updated_by',
        ]));
        $this->assertTrue(Schema::hasColumns('employee_id_card_issues', [
            'tenant_id',
            'id_card_id',
            'pdf_path',
            'payload_json',
            'issued_by',
            'created_at',
        ]));

        $employeeColumns = Schema::getColumnListing('employees');
        $this->assertNotContains('blood_group', $employeeColumns);
        $this->assertNotContains('photo_path', $employeeColumns);
        $this->assertNotContains('emergency_contact_number', $employeeColumns);
        $this->assertNotContains('qr_token', $employeeColumns);

        $company = CompanyFixture::make('ID Card Co', 'idcardco');
        $other = CompanyFixture::make('Other ID Card Co', 'otheridcard');
        $employeeSnapshot = $this->employeeSnapshot($company->reportEmployeeId);

        $relationships = require base_path('database/migrations/2026_11_13_130000_enforce_id_card_tenant_relationships.php');
        $relationships->up();
        $this->assertTenantForeign('id_card_company_profiles', 'updated_by', 'users');
        $this->assertTenantForeign('employee_id_cards', 'employee_id', 'employees');
        $this->assertTenantForeign('employee_id_cards', 'company_profile_id', 'id_card_company_profiles');
        $this->assertTenantForeign('employee_id_card_issues', 'issued_by', 'users');

        $india = new IdCardCompanyProfile;
        $india->forceFill([
            'tenant_id' => $company->id,
            'country' => 'india',
            'legal_name' => 'Veltrix Digital Solutions Private Limited',
            'display_name' => 'Veltrix India',
            'office_address' => 'Hyderabad',
            'contact_number' => '+91-40-00000000',
            'email' => 'india@veltrix.test',
            'website' => 'https://veltrix.test',
            'footer_address' => 'Hyderabad, India',
            'status' => 'active',
            'updated_by' => $company->hr->id,
        ])->save();

        $usa = new IdCardCompanyProfile;
        $usa->forceFill([
            'tenant_id' => $company->id,
            'country' => 'usa',
            'legal_name' => 'Veltrix Digital Solutions Inc',
            'display_name' => 'Veltrix USA',
            'office_address' => 'Texas',
            'contact_number' => '+1-000-000-0000',
            'email' => 'usa@veltrix.test',
            'website' => 'https://veltrix.test',
            'footer_address' => 'Texas, USA',
            'status' => 'active',
            'updated_by' => $company->hr->id,
        ])->save();

        $card = new EmployeeIdCard;
        $card->forceFill([
            'tenant_id' => $company->id,
            'employee_id' => $company->reportEmployeeId,
            'country' => 'india',
            'company_profile_id' => $india->id,
            'blood_group' => 'O+',
            'emergency_contact_number' => '+91-9000000000',
            'photo_path' => 'id-cards/'.$company->id.'/photos/demo.jpg',
            'photo_mime' => 'image/jpeg',
            'status' => 'active',
            'card_number' => 'IDC-0001',
            'qr_token' => 'qr-token-india-001',
            'issued_at' => now(),
            'created_by' => $company->hr->id,
            'updated_by' => $company->hr->id,
        ])->save();

        $issue = new EmployeeIdCardIssue;
        $issue->forceFill([
            'tenant_id' => $company->id,
            'id_card_id' => $card->id,
            'pdf_path' => 'id-cards/'.$company->id.'/pdfs/demo.pdf',
            'payload_json' => [
                'name' => 'idcardco-REP Person',
                'employeeCode' => 'idcardco-REP',
                'designation' => 'Engineer',
                'department' => 'Delivery',
                'email' => 'rep@example.test',
                'bloodGroup' => 'O+',
                'emergencyContactNumber' => '+91-9000000000',
                'footerAddress' => 'Hyderabad, India',
                'country' => 'india',
            ],
            'issued_by' => $company->hr->id,
            'created_at' => now(),
        ])->save();

        $this->assertSame(2, IdCardCompanyProfile::query()->inCompany($company->id)->count());
        $this->assertSame(0, IdCardCompanyProfile::query()->inCompany($other->id)->count());
        $this->assertSame(1, EmployeeIdCard::query()->inCompany($company->id)->count());
        $this->assertSame(0, EmployeeIdCard::query()->inCompany($other->id)->count());
        $this->assertSame('india', $card->companyProfile->country);
        $this->assertSame((int) $company->reportEmployeeId, (int) $card->employee->id);
        $this->assertSame('O+', $card->fresh()->blood_group);
        $this->assertSame('Hyderabad, India', $issue->fresh()->payload_json['footerAddress']);
        $this->assertCount(1, $card->issues);
        $this->assertSame($employeeSnapshot, $this->employeeSnapshot($company->reportEmployeeId));

        $this->rejectCrossTenant(fn () => (new IdCardCompanyProfile)->forceFill([
            'tenant_id' => $company->id,
            'country' => 'india',
            'legal_name' => 'Dup',
            'display_name' => 'Dup',
            'office_address' => 'X',
            'footer_address' => 'Y',
            'status' => 'active',
            'updated_by' => $other->hr->id,
        ])->save());

        $this->rejectCrossTenant(fn () => (new EmployeeIdCard)->forceFill([
            'tenant_id' => $company->id,
            'employee_id' => $other->reportEmployeeId,
            'country' => 'usa',
            'company_profile_id' => $usa->id,
            'status' => 'draft',
            'qr_token' => 'qr-cross-employee',
            'created_by' => $company->hr->id,
        ])->save());

        $this->rejectCrossTenant(fn () => (new EmployeeIdCard)->forceFill([
            'tenant_id' => $company->id,
            'employee_id' => $company->reportEmployeeId,
            'country' => 'usa',
            'company_profile_id' => $this->foreignProfile($other)->id,
            'status' => 'draft',
            'qr_token' => 'qr-cross-profile',
            'created_by' => $company->hr->id,
        ])->save());

        $this->rejectCrossTenant(fn () => (new EmployeeIdCardIssue)->forceFill([
            'tenant_id' => $company->id,
            'id_card_id' => $card->id,
            'payload_json' => ['country' => 'india'],
            'issued_by' => $other->hr->id,
            'created_at' => now(),
        ])->save());
    }

    public function test_id_card_migrations_roll_back_and_restore_tenant_relationships(): void
    {
        $company = CompanyFixture::make('ID Card Migrate', 'idcardmigrate');
        $this->assertTrue(Schema::hasTable('employee_id_cards'));
        $this->assertTrue(Schema::hasTable('employees'));

        $relationships = require base_path('database/migrations/2026_11_13_130000_enforce_id_card_tenant_relationships.php');
        $relationships->up();

        $this->assertTenantForeign('id_card_company_profiles', 'updated_by', 'users');
        $this->assertTenantForeign('employee_id_cards', 'employee_id', 'employees');
        $this->assertTenantForeign('employee_id_cards', 'company_profile_id', 'id_card_company_profiles');
        $this->assertTenantForeign('employee_id_cards', 'reissued_from_id', 'employee_id_cards');
        $this->assertTenantForeign('employee_id_cards', 'created_by', 'users');
        $this->assertTenantForeign('employee_id_cards', 'updated_by', 'users');
        $this->assertTenantForeign('employee_id_card_issues', 'id_card_id', 'employee_id_cards');
        $this->assertTenantForeign('employee_id_card_issues', 'issued_by', 'users');

        $relationships->down();
        $this->assertFalse($this->tenantForeignExists('employee_id_cards', 'employee_id', 'employees'));
        $this->assertFalse($this->tenantForeignExists('employee_id_card_issues', 'id_card_id', 'employee_id_cards'));
        $this->assertTrue(Schema::hasTable('employee_id_cards'));
        $this->assertTrue(Schema::hasTable('employees'));
        $this->assertNotContains('blood_group', Schema::getColumnListing('employees'));

        $relationships->up();
        $this->assertTenantForeign('employee_id_cards', 'company_profile_id', 'id_card_company_profiles');
        $this->assertTenantForeign('employee_id_card_issues', 'issued_by', 'users');

        $tables = require base_path('database/migrations/2026_11_13_120000_add_employee_id_card_tables.php');
        $tables->down();
        $this->assertFalse(Schema::hasTable('id_card_company_profiles'));
        $this->assertFalse(Schema::hasTable('employee_id_cards'));
        $this->assertFalse(Schema::hasTable('employee_id_card_issues'));
        $this->assertTrue(Schema::hasTable('employees'));
        $this->assertSame($company->id, (int) $company->hr->companyId());

        $tables->up();
        $this->assertTrue(Schema::hasTable('id_card_company_profiles'));
        $this->assertTrue(Schema::hasTable('employee_id_cards'));
        $this->assertTrue(Schema::hasTable('employee_id_card_issues'));
        $this->assertNotEmpty(Schema::getForeignKeys('employee_id_cards'));
        $this->assertNotEmpty(Schema::getForeignKeys('employee_id_card_issues'));
        $this->assertNotEmpty(Schema::getForeignKeys('id_card_company_profiles'));

        $relationships->up();
        $this->assertTenantForeign('employee_id_cards', 'employee_id', 'employees');
        $this->assertNotContains('blood_group', Schema::getColumnListing('employees'));
        $this->assertNotContains('photo_path', Schema::getColumnListing('employees'));
    }

    private function foreignProfile(CompanyFixture $company): IdCardCompanyProfile
    {
        $profile = new IdCardCompanyProfile;
        $profile->forceFill([
            'tenant_id' => $company->id,
            'country' => 'usa',
            'legal_name' => 'Foreign Legal',
            'display_name' => 'Foreign Display',
            'office_address' => 'Elsewhere',
            'footer_address' => 'Elsewhere',
            'status' => 'active',
            'updated_by' => $company->hr->id,
        ])->save();

        return $profile;
    }

    /**
     * @return array<string, mixed>
     */
    private function employeeSnapshot(int $employeeId): array
    {
        return (array) DB::table('employees')->where('id', $employeeId)->first();
    }

    private function assertTenantForeign(string $table, string $column, string $references): void
    {
        $this->assertTrue(
            $this->tenantForeignExists($table, $column, $references),
            "Missing tenant relationship {$table}.{$column} -> {$references}.",
        );
    }

    private function tenantForeignExists(string $table, string $column, string $references): bool
    {
        foreach (Schema::getForeignKeys($table) as $foreign) {
            if (($foreign['columns'] ?? []) === ['tenant_id', $column]
                && ($foreign['foreign_table'] ?? '') === $references
                && ($foreign['foreign_columns'] ?? []) === ['tenant_id', 'id']) {
                return true;
            }
        }

        return false;
    }

    private function rejectCrossTenant(callable $write): void
    {
        try {
            $write();
        } catch (QueryException $exception) {
            $this->assertTrue(
                str_contains(strtolower($exception->getMessage()), 'foreign key')
                    || str_contains(strtolower($exception->getMessage()), 'unique')
                    || str_contains(strtolower($exception->getMessage()), 'constraint'),
                $exception->getMessage(),
            );

            return;
        }

        $this->fail('A cross-tenant reference was stored.');
    }
}
