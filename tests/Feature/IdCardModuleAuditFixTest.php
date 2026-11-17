<?php

namespace Tests\Feature;

use App\Services\Hrms\EmployeeIdCardIssuer;
use App\Services\Hrms\EmployeeIdCardPdf;
use App\Services\Hrms\StandaloneIdCardIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CompanyFixture;
use Tests\TestCase;

class IdCardModuleAuditFixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_failed_employee_reissue_restores_previous_active_card_and_qr(): void
    {
        Storage::fake('local');
        $company = CompanyFixture::make('Audit Reissue Employee', 'audit-reissue-emp');
        $this->seedReadyEmployeeCard($company, 'india');
        $cardId = (int) DB::table('employee_id_cards')->where('tenant_id', $company->id)->value('id');
        $this->actingAs($company->hr)->post('/id-cards/'.$cardId.'/issue')->assertRedirect();

        $before = DB::table('employee_id_cards')->where('id', $cardId)->first();
        $this->assertSame('active', $before->status);
        $oldToken = (string) $before->qr_token;

        $this->app->instance(EmployeeIdCardPdf::class, new class extends EmployeeIdCardPdf
        {
            public function render(array $card): string
            {
                throw ValidationException::withMessages(['card' => 'Forced PDF failure after archive.']);
            }
        });

        try {
            $this->app->make(EmployeeIdCardIssuer::class)->reissue(
                $company->hr,
                \App\Models\EmployeeIdCard::query()->findOrFail($cardId),
            );
            $this->fail('Expected reissue to fail after archive when PDF rendering fails.');
        } catch (ValidationException $exception) {
            $this->assertSame(['card' => ['Forced PDF failure after archive.']], $exception->errors());
        }

        $restored = DB::table('employee_id_cards')->where('id', $cardId)->first();
        $this->assertSame('active', $restored->status);
        $this->assertSame($oldToken, (string) $restored->qr_token);
        $this->assertNull($restored->deactivated_at);
        $this->assertSame(1, DB::table('employee_id_cards')->where('tenant_id', $company->id)->where('employee_id', $company->reportEmployeeId)->count());
        $this->get('/id-cards/verify/'.$oldToken)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('verification.valid', true)
            ->where('verification.status', 'active')
        );
    }

    public function test_failed_standalone_reissue_restores_card_number_and_active_status(): void
    {
        Storage::fake('local');
        $company = CompanyFixture::make('Audit Reissue Standalone', 'audit-reissue-std');
        $cardId = $this->createReadyStandalone($company, 'STC-RESTORE', 'india');
        $this->actingAs($company->hr)->post('/id-cards/standalone/'.$cardId.'/issue')->assertRedirect();
        $oldToken = (string) DB::table('standalone_id_cards')->where('id', $cardId)->value('qr_token');

        $this->app->instance(EmployeeIdCardPdf::class, new class extends EmployeeIdCardPdf
        {
            public function render(array $card): string
            {
                throw ValidationException::withMessages(['card' => 'Forced PDF failure after archive.']);
            }
        });

        try {
            $this->app->make(StandaloneIdCardIssuer::class)->reissue(
                $company->hr,
                \App\Models\StandaloneIdCard::query()->findOrFail($cardId),
            );
            $this->fail('Expected standalone reissue to fail after archive when PDF rendering fails.');
        } catch (ValidationException $exception) {
            $this->assertSame(['card' => ['Forced PDF failure after archive.']], $exception->errors());
        }

        $restored = DB::table('standalone_id_cards')->where('id', $cardId)->first();
        $this->assertSame('active', $restored->status);
        $this->assertSame('STC-RESTORE', $restored->card_number);
        $this->assertSame($oldToken, (string) $restored->qr_token);
        $this->assertSame(1, DB::table('standalone_id_cards')->where('tenant_id', $company->id)->count());
    }

    public function test_public_verify_uses_issue_snapshot_not_live_employee_edits(): void
    {
        Storage::fake('local');
        $company = CompanyFixture::make('Audit Verify Snapshot', 'audit-verify-snap');
        $this->seedReadyEmployeeCard($company, 'india');
        $cardId = (int) DB::table('employee_id_cards')->where('tenant_id', $company->id)->value('id');
        $this->actingAs($company->hr)->post('/id-cards/'.$cardId.'/issue')->assertRedirect();
        $token = (string) DB::table('employee_id_cards')->where('id', $cardId)->value('qr_token');
        $code = (string) DB::table('employees')->where('id', $company->reportEmployeeId)->value('employee_code');

        DB::table('employees')->where('id', $company->reportEmployeeId)->update([
            'first_name' => 'Changed',
            'last_name' => 'Later',
        ]);

        $this->get('/id-cards/verify/'.$token)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('verification.valid', true)
            ->where('verification.employeeCode', $code)
            ->where('verification.name', fn ($name) => is_string($name) && ! str_contains($name, 'Changed Later'))
            ->missing('verification.bloodGroup')
            ->missing('verification.emergencyContactNumber')
            ->missing('verification.photoPath')
        );
    }

    public function test_company_logo_is_replaced_only_after_successful_profile_save(): void
    {
        Storage::fake('local');
        $company = CompanyFixture::make('Audit Logo Safety', 'audit-logo-safe');
        $this->actingAs($company->hr)->post('/id-cards/settings', [
            'country' => 'india',
            'legal_name' => 'Veltrix India Pvt Ltd',
            'display_name' => 'Veltrix India',
            'office_address' => 'Hyderabad',
            'footer_address' => 'Hyderabad, India',
            'logo' => UploadedFile::fake()->image('old-logo.png', 120, 120),
        ])->assertRedirect();

        $oldPath = (string) DB::table('id_card_company_profiles')->where('tenant_id', $company->id)->value('logo_path');
        Storage::disk('local')->assertExists($oldPath);

        $this->actingAs($company->hr)->post('/id-cards/settings', [
            'country' => 'india',
            'legal_name' => 'Veltrix India Pvt Ltd',
            'display_name' => 'Veltrix India Updated',
            'office_address' => 'Hyderabad',
            'footer_address' => 'Hyderabad, India',
            'logo' => UploadedFile::fake()->image('new-logo.png', 120, 120),
        ])->assertRedirect();

        $newPath = (string) DB::table('id_card_company_profiles')->where('tenant_id', $company->id)->value('logo_path');
        $this->assertNotSame($oldPath, $newPath);
        Storage::disk('local')->assertExists($newPath);
        Storage::disk('local')->assertMissing($oldPath);
    }

    public function test_standalone_duplicate_card_number_returns_validation_error(): void
    {
        Storage::fake('local');
        $company = CompanyFixture::make('Audit Card Number', 'audit-card-number');
        $this->seedCompanyProfile($company, 'india');

        $this->actingAs($company->hr)->post('/id-cards/standalone', [
            'full_name' => 'First',
            'card_number' => 'STC-DUP',
            'designation' => 'Guest',
            'department' => 'Ops',
            'country' => 'india',
            'blood_group' => 'O+',
            'emergency_contact_number' => '+91-1',
            'designation' => 'Software Engineer',
            'department' => 'Engineering',
            'photo' => UploadedFile::fake()->image('a.png', 120, 140),
        ])->assertRedirect();

        $this->from('/id-cards/standalone')->actingAs($company->hr)->post('/id-cards/standalone', [
            'full_name' => 'Second',
            'card_number' => 'STC-DUP',
            'designation' => 'Guest',
            'department' => 'Ops',
            'country' => 'india',
            'blood_group' => 'A+',
            'emergency_contact_number' => '+91-2',
            'designation' => 'Software Engineer',
            'department' => 'Engineering',
            'photo' => UploadedFile::fake()->image('b.png', 120, 140),
        ])->assertSessionHasErrors('card_number');

        $this->assertSame(1, DB::table('standalone_id_cards')->where('tenant_id', $company->id)->where('card_number', 'STC-DUP')->count());
    }

    private function seedReadyEmployeeCard(CompanyFixture $company, string $country): void
    {
        $this->seedCompanyProfile($company, $country);
        $this->actingAs($company->hr)->post('/id-cards', [
            'employee_id' => $company->reportEmployeeId,
            'country' => $country,
            'blood_group' => 'O+',
            'emergency_contact_number' => '+91-9000000999',
            'designation' => 'Software Engineer',
            'department' => 'Engineering',
            'photo' => UploadedFile::fake()->image('badge.png', 180, 220),
        ])->assertRedirect();
    }

    private function createReadyStandalone(CompanyFixture $company, string $cardNumber, string $country): int
    {
        $this->seedCompanyProfile($company, $country);
        $this->actingAs($company->hr)->post('/id-cards/standalone', [
            'full_name' => 'Standalone '.$cardNumber,
            'card_number' => $cardNumber,
            'designation' => 'Guest',
            'department' => 'Ops',
            'email' => strtolower($cardNumber).'@example.test',
            'phone' => '+91-9000000000',
            'country' => $country,
            'blood_group' => 'O+',
            'emergency_contact_number' => '+91-9000000001',
            'designation' => 'Software Engineer',
            'department' => 'Engineering',
            'photo' => UploadedFile::fake()->image($cardNumber.'.png', 160, 200),
        ])->assertRedirect();

        return (int) DB::table('standalone_id_cards')
            ->where('tenant_id', $company->id)
            ->where('card_number', $cardNumber)
            ->value('id');
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
