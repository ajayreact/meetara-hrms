<?php

namespace Tests\Feature;

use App\Models\StandaloneIdCard;
use App\Models\StandaloneIdCardIssue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CompanyFixture;
use Tests\TestCase;

class StandaloneIdCardSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_standalone_id_card_tables_are_separate_from_employees_with_tenant_relationships(): void
    {
        foreach (['standalone_id_cards', 'standalone_id_card_issues'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }

        $this->assertTrue(Schema::hasColumns('standalone_id_cards', [
            'tenant_id',
            'country',
            'company_profile_id',
            'full_name',
            'card_number',
            'designation',
            'department',
            'email',
            'phone',
            'date_of_birth',
            'blood_group',
            'emergency_contact_number',
            'photo_path',
            'photo_mime',
            'status',
            'qr_token',
            'issued_at',
            'deactivated_at',
            'reissued_from_id',
            'created_by',
            'updated_by',
        ]));
        $this->assertTrue(Schema::hasColumns('standalone_id_card_issues', [
            'tenant_id',
            'id_card_id',
            'pdf_path',
            'payload_json',
            'issued_by',
            'created_at',
        ]));
        $this->assertFalse(Schema::hasColumn('standalone_id_cards', 'employee_id'));
        $this->assertNotContains('blood_group', Schema::getColumnListing('employees'));

        $company = CompanyFixture::make('Standalone Schema Co', 'standalone-schema');
        $other = CompanyFixture::make('Other Standalone Schema Co', 'other-standalone-schema');
        $employeeCount = (int) DB::table('employees')->where('tenant_id', $company->id)->count();

        $relationships = require base_path('database/migrations/2026_11_14_130000_enforce_standalone_id_card_tenant_relationships.php');
        $relationships->up();
        $this->assertTenantForeign('standalone_id_cards', 'company_profile_id', 'id_card_company_profiles');
        $this->assertTenantForeign('standalone_id_cards', 'created_by', 'users');
        $this->assertTenantForeign('standalone_id_card_issues', 'id_card_id', 'standalone_id_cards');
        $this->assertTenantForeign('standalone_id_card_issues', 'issued_by', 'users');

        $profileId = DB::table('id_card_company_profiles')->insertGetId([
            'tenant_id' => $company->id,
            'country' => 'india',
            'legal_name' => 'Veltrix India',
            'display_name' => 'Veltrix India',
            'office_address' => 'Hyderabad',
            'footer_address' => 'Hyderabad, India',
            'status' => 'active',
            'updated_by' => $company->hr->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $card = new StandaloneIdCard;
        $card->forceFill([
            'tenant_id' => $company->id,
            'country' => 'india',
            'company_profile_id' => $profileId,
            'full_name' => 'Guest Contractor',
            'card_number' => 'STC-001',
            'designation' => 'Consultant',
            'department' => 'Ops',
            'email' => 'guest@example.test',
            'phone' => '+91-9000000099',
            'blood_group' => 'O+',
            'emergency_contact_number' => '+91-9000000088',
            'photo_path' => 'id-cards/'.$company->id.'/standalone/photos/demo.jpg',
            'photo_mime' => 'image/jpeg',
            'status' => 'draft',
            'qr_token' => str_repeat('ab', 32),
            'created_by' => $company->hr->id,
            'updated_by' => $company->hr->id,
        ])->save();

        $issue = new StandaloneIdCardIssue;
        $issue->forceFill([
            'tenant_id' => $company->id,
            'id_card_id' => $card->id,
            'pdf_path' => 'id-cards/'.$company->id.'/standalone/pdfs/demo.pdf',
            'payload_json' => [
                'name' => 'Guest Contractor',
                'employeeCode' => 'STC-001',
                'bloodGroup' => 'O+',
            ],
            'issued_by' => $company->hr->id,
            'created_at' => now(),
        ])->save();

        $this->assertSame(1, StandaloneIdCard::query()->inCompany($company->id)->count());
        $this->assertSame(0, StandaloneIdCard::query()->inCompany($other->id)->count());
        $this->assertCount(1, $card->issues);
        $this->assertSame($employeeCount, (int) DB::table('employees')->where('tenant_id', $company->id)->count());

        $this->rejectCrossTenant(fn () => (new StandaloneIdCard)->forceFill([
            'tenant_id' => $company->id,
            'country' => 'india',
            'company_profile_id' => $this->foreignProfile($other),
            'full_name' => 'Cross',
            'card_number' => 'STC-X',
            'status' => 'draft',
            'qr_token' => str_repeat('cd', 32),
            'created_by' => $company->hr->id,
        ])->save());

        $this->rejectCrossTenant(fn () => (new StandaloneIdCardIssue)->forceFill([
            'tenant_id' => $company->id,
            'id_card_id' => $card->id,
            'payload_json' => ['name' => 'x'],
            'issued_by' => $other->hr->id,
            'created_at' => now(),
        ])->save());
    }

    public function test_standalone_id_card_migrations_roll_back_safely(): void
    {
        CompanyFixture::make('Standalone Migrate', 'standalone-migrate');
        $this->assertTrue(Schema::hasTable('standalone_id_cards'));
        $this->assertTrue(Schema::hasTable('employees'));

        $relationships = require base_path('database/migrations/2026_11_14_130000_enforce_standalone_id_card_tenant_relationships.php');
        $relationships->up();
        $this->assertTenantForeign('standalone_id_cards', 'created_by', 'users');
        $this->assertTenantForeign('standalone_id_cards', 'reissued_from_id', 'standalone_id_cards');

        $relationships->down();
        $this->assertFalse($this->tenantForeignExists('standalone_id_cards', 'company_profile_id', 'id_card_company_profiles'));
        $this->assertTrue(Schema::hasTable('standalone_id_cards'));
        $this->assertTrue(Schema::hasTable('employees'));

        $tables = require base_path('database/migrations/2026_11_14_120000_add_standalone_id_card_tables.php');
        $tables->down();
        $this->assertFalse(Schema::hasTable('standalone_id_cards'));
        $this->assertFalse(Schema::hasTable('standalone_id_card_issues'));
        $this->assertTrue(Schema::hasTable('employees'));
        $this->assertTrue(Schema::hasTable('employee_id_cards'));

        $tables->up();
        $relationships->up();
        $this->assertTrue(Schema::hasTable('standalone_id_cards'));
        $this->assertTenantForeign('standalone_id_card_issues', 'id_card_id', 'standalone_id_cards');
    }

    private function foreignProfile(CompanyFixture $company): int
    {
        return (int) DB::table('id_card_company_profiles')->insertGetId([
            'tenant_id' => $company->id,
            'country' => 'usa',
            'legal_name' => 'Foreign',
            'display_name' => 'Foreign',
            'office_address' => 'Elsewhere',
            'footer_address' => 'Elsewhere',
            'status' => 'active',
            'updated_by' => $company->hr->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
