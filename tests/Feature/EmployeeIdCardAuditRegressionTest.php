<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CompanyFixture;
use Tests\TestCase;

class EmployeeIdCardAuditRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_qr_does_not_disclose_identity_and_inactive_pdf_is_blocked(): void
    {
        $this->withoutVite();
        Storage::fake('local');
        $company = CompanyFixture::make('ID Card Audit Privacy', 'idcard-audit-privacy');
        $this->seedReadyCard($company, $company->reportEmployeeId, 'india');
        $cardId = (int) DB::table('employee_id_cards')->where('tenant_id', $company->id)->value('id');
        $this->actingAs($company->hr)->post('/id-cards/'.$cardId.'/issue')->assertRedirect();
        $token = (string) DB::table('employee_id_cards')->where('id', $cardId)->value('qr_token');
        $code = (string) DB::table('employees')->where('id', $company->reportEmployeeId)->value('employee_code');

        $this->get('/id-cards/verify/'.$token)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('verification.valid', true)
            ->where('verification.employeeCode', $code)
        );

        $this->actingAs($company->hr)->post('/id-cards/'.$cardId.'/deactivate')->assertRedirect();

        $invalid = $this->get('/id-cards/verify/'.$token);
        $invalid->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('verification.valid', false)
            ->where('verification.status', 'inactive')
            ->where('verification.name', '')
            ->where('verification.employeeCode', '')
            ->where('verification.designation', '')
            ->where('verification.department', '')
            ->where('verification.companyName', '')
            ->where('verification.country', '')
            ->where('verification.cardNumber', '')
        );
        $this->assertStringNotContainsString($code, $invalid->getContent());
        $this->actingAs($company->hr)->get('/id-cards/'.$cardId.'/pdf')->assertNotFound();
    }

    public function test_save_collapses_duplicate_open_cards_and_issue_keeps_one_active(): void
    {
        $this->withoutVite();
        Storage::fake('local');
        $company = CompanyFixture::make('ID Card Audit Dupes', 'idcard-audit-dupes');
        $employeeId = $company->reportEmployeeId;
        $this->seedCompanyProfile($company, 'india');

        $first = DB::table('employee_id_cards')->insertGetId([
            'tenant_id' => $company->id,
            'employee_id' => $employeeId,
            'country' => 'india',
            'blood_group' => 'O+',
            'emergency_contact_number' => '+91-9000000301',
            'photo_path' => null,
            'status' => 'draft',
            'qr_token' => bin2hex(random_bytes(32)),
            'created_by' => $company->hr->id,
            'updated_by' => $company->hr->id,
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);
        $second = DB::table('employee_id_cards')->insertGetId([
            'tenant_id' => $company->id,
            'employee_id' => $employeeId,
            'country' => 'india',
            'blood_group' => 'A+',
            'emergency_contact_number' => '+91-9000000302',
            'photo_path' => null,
            'status' => 'draft',
            'qr_token' => bin2hex(random_bytes(32)),
            'created_by' => $company->hr->id,
            'updated_by' => $company->hr->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($company->hr)->post('/id-cards', [
            'employee_id' => $employeeId,
            'country' => 'india',
            'blood_group' => 'B+',
            'emergency_contact_number' => '+91-9000000303',
            'designation' => 'Software Engineer',
            'department' => 'Engineering',
            'photo' => UploadedFile::fake()->image('face.png', 160, 200),
        ])->assertRedirect();

        $this->assertSame('inactive', DB::table('employee_id_cards')->where('id', $first)->value('status'));
        $this->assertSame('draft', DB::table('employee_id_cards')->where('id', $second)->value('status'));
        $this->assertSame('B+', DB::table('employee_id_cards')->where('id', $second)->value('blood_group'));
        $this->assertSame(1, DB::table('employee_id_cards')->where('tenant_id', $company->id)->where('employee_id', $employeeId)->whereIn('status', ['draft', 'active'])->count());

        $this->actingAs($company->hr)->post('/id-cards/'.$second.'/issue')->assertRedirect();
        $this->assertSame(1, DB::table('employee_id_cards')->where('tenant_id', $company->id)->where('employee_id', $employeeId)->where('status', 'active')->count());
    }

    public function test_reissue_copies_photo_so_later_updates_do_not_break_old_card_file(): void
    {
        $this->withoutVite();
        Storage::fake('local');
        $company = CompanyFixture::make('ID Card Audit Photo', 'idcard-audit-photo');
        $employeeId = $company->reportEmployeeId;
        $this->seedReadyCard($company, $employeeId, 'usa');
        $oldId = (int) DB::table('employee_id_cards')->where('tenant_id', $company->id)->value('id');
        $this->actingAs($company->hr)->post('/id-cards/'.$oldId.'/issue')->assertRedirect();
        $oldPhoto = (string) DB::table('employee_id_cards')->where('id', $oldId)->value('photo_path');

        $this->actingAs($company->hr)->post('/id-cards/'.$oldId.'/reissue')->assertRedirect();
        $new = DB::table('employee_id_cards')
            ->where('tenant_id', $company->id)
            ->where('employee_id', $employeeId)
            ->where('status', 'active')
            ->first();
        $this->assertNotNull($new);
        $this->assertNotSame($oldPhoto, (string) $new->photo_path);
        Storage::disk('local')->assertExists($oldPhoto);
        Storage::disk('local')->assertExists((string) $new->photo_path);

        $this->actingAs($company->hr)->post('/id-cards', [
            'employee_id' => $employeeId,
            'country' => 'usa',
            'blood_group' => 'O+',
            'emergency_contact_number' => '+1-555-0400',
            'designation' => 'Software Engineer',
            'department' => 'Engineering',
            'photo' => UploadedFile::fake()->image('replacement.png', 160, 200),
        ])->assertRedirect();

        Storage::disk('local')->assertExists($oldPhoto);
        $this->actingAs($company->hr)->get('/id-cards/'.$oldId.'/photo')->assertOk();
    }

    public function test_manage_preview_does_not_mutate_company_profile_id(): void
    {
        $this->withoutVite();
        Storage::fake('local');
        $company = CompanyFixture::make('ID Card Audit Profile', 'idcard-audit-profile');
        $this->seedReadyCard($company, $company->reportEmployeeId, 'india');
        $cardId = (int) DB::table('employee_id_cards')->where('tenant_id', $company->id)->value('id');
        $profileId = (int) DB::table('id_card_company_profiles')->where('tenant_id', $company->id)->where('country', 'india')->value('id');

        DB::table('employee_id_cards')->where('id', $cardId)->update(['company_profile_id' => null, 'updated_at' => now()]);
        $this->actingAs($company->hr)->get('/id-cards?employee='.$company->reportEmployeeId)->assertOk();
        $this->assertNull(DB::table('employee_id_cards')->where('id', $cardId)->value('company_profile_id'));

        $this->actingAs($company->hr)->post('/id-cards/'.$cardId.'/issue')->assertRedirect();
        $this->assertSame($profileId, (int) DB::table('employee_id_cards')->where('id', $cardId)->value('company_profile_id'));
    }

    private function seedReadyCard(CompanyFixture $company, int $employeeId, string $country): void
    {
        $this->seedCompanyProfile($company, $country);
        $this->actingAs($company->hr)->post('/id-cards', [
            'employee_id' => $employeeId,
            'country' => $country,
            'blood_group' => 'O+',
            'emergency_contact_number' => '+91-9000000400',
            'designation' => 'Software Engineer',
            'department' => 'Engineering',
            'photo' => UploadedFile::fake()->image('badge.png', 160, 200),
        ])->assertRedirect();
    }

    private function seedCompanyProfile(CompanyFixture $company, string $country): void
    {
        $this->actingAs($company->hr)->post('/id-cards/settings', [
            'country' => $country,
            'legal_name' => $country === 'india' ? 'Veltrix India Pvt Ltd' : 'Veltrix Digital Solutions Inc',
            'display_name' => $country === 'india' ? 'Veltrix India' : 'Veltrix USA',
            'office_address' => $country === 'india' ? 'Hyderabad' : 'Farmington',
            'contact_number' => $country === 'india' ? '+91-40-00000000' : '+1-248-000-0000',
            'email' => $country.'@veltrix.test',
            'website' => 'https://veltrix.test',
            'footer_address' => $country === 'india' ? 'Hyderabad, India' : 'Farmington, MI, USA',
            'logo' => UploadedFile::fake()->image($country.'-logo.png', 120, 120),
        ])->assertRedirect();
    }
}
