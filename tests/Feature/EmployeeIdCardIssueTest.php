<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Hrms\EmployeeIdCardPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CompanyFixture;
use Tests\TestCase;

class EmployeeIdCardIssueTest extends TestCase
{
    use RefreshDatabase;

    public function test_issue_creates_cr80_pdf_with_unique_qr_and_public_verification_privacy(): void
    {
        $this->withoutVite();
        Storage::fake('local');
        $company = CompanyFixture::make('ID Card Issue Co', 'idcard-issue');
        $other = CompanyFixture::make('Other ID Card Issue Co', 'other-idcard-issue');
        $employeeId = $company->reportEmployeeId;
        $snapshot = $this->employeeSnapshot($employeeId);

        $this->seedCompanyProfile($company, 'india');
        $this->seedCardDetails($company, $employeeId, 'india', 'O+', '+91-9000000001');

        $cardId = (int) DB::table('employee_id_cards')->where('tenant_id', $company->id)->where('employee_id', $employeeId)->value('id');
        $token = (string) DB::table('employee_id_cards')->where('id', $cardId)->value('qr_token');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);

        $this->actingAs($company->hr)->from('/id-cards?employee='.$employeeId)->post('/id-cards/'.$cardId.'/issue')
            ->assertRedirect('/id-cards?employee='.$employeeId);

        $card = DB::table('employee_id_cards')->where('id', $cardId)->first();
        $this->assertSame('active', $card->status);
        $this->assertNotNull($card->issued_at);
        $this->assertSame('IDC-'.str_pad((string) $cardId, 6, '0', STR_PAD_LEFT), $card->card_number);

        $issue = DB::table('employee_id_card_issues')->where('id_card_id', $cardId)->orderByDesc('id')->first();
        $this->assertNotNull($issue);
        $this->assertStringStartsWith('id-cards/'.$company->id.'/pdfs/', (string) $issue->pdf_path);
        Storage::disk('local')->assertExists((string) $issue->pdf_path);

        $pdfBytes = (string) Storage::disk('local')->get((string) $issue->pdf_path);
        $this->assertStringStartsWith('%PDF', $pdfBytes);
        $this->assertSame(2, (new EmployeeIdCardPdf)->pages($pdfBytes));
        $this->assertStringContainsString('/MediaBox [0.00 0.00 153.01 242.65]', $this->normalizePdfBoxes($pdfBytes));

        $payload = json_decode((string) $issue->payload_json, true);
        $this->assertIsArray($payload);
        $this->assertSame('O+', $payload['bloodGroup']);
        $this->assertArrayNotHasKey('logoPath', $payload);
        $this->assertArrayNotHasKey('photoPath', $payload);
        $this->assertStringContainsString('/id-cards/verify/'.$token, (string) $payload['verifyUrl']);

        $download = $this->actingAs($company->hr)->get('/id-cards/'.$cardId.'/pdf');
        $download->assertOk();
        $download->assertHeader('Content-Type', 'application/pdf');
        $download->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame($pdfBytes, $download->streamedContent());

        $this->actingAs($other->hr)->get('/id-cards/'.$cardId.'/pdf')->assertNotFound();
        $this->actingAs($company->employee)->post('/id-cards/'.$cardId.'/issue')->assertForbidden();
        $this->actingAs($company->lead)->get('/id-cards/'.$cardId.'/pdf')->assertForbidden();

        $verify = $this->get('/id-cards/verify/'.$token);
        $verify->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('id-cards/verify')
            ->where('verification.valid', true)
            ->where('verification.status', 'active')
            ->where('verification.employeeCode', 'idcard-issue-REP')
            ->where('verification.name', fn ($name) => is_string($name) && $name !== '')
            ->where('verification.companyName', 'Veltrix India')
            ->missing('verification.bloodGroup')
            ->missing('verification.emergencyContactNumber')
            ->missing('verification.photoPath')
            ->missing('verification.photoUrl')
            ->missing('verification.logoPath')
        );

        $html = $verify->assertInertia(fn (Assert $page) => $page)->getContent();
        $this->assertStringNotContainsString('O+', $html);
        $this->assertStringNotContainsString('+91-9000000001', $html);
        $this->assertStringNotContainsString('id-cards/'.$company->id.'/photos/', $html);
        $this->assertSame($snapshot, $this->employeeSnapshot($employeeId));
    }

    public function test_reissue_and_deactivate_invalidate_previous_qr_tokens(): void
    {
        $this->withoutVite();
        Storage::fake('local');
        $company = CompanyFixture::make('ID Card Lifecycle Co', 'idcard-lifecycle');
        $employeeId = $company->reportEmployeeId;

        $this->seedCompanyProfile($company, 'usa');
        $this->seedCardDetails($company, $employeeId, 'usa', 'B+', '+1-555-0199');
        $cardId = (int) DB::table('employee_id_cards')->where('tenant_id', $company->id)->value('id');
        $this->actingAs($company->hr)->post('/id-cards/'.$cardId.'/issue')->assertRedirect();
        $oldToken = (string) DB::table('employee_id_cards')->where('id', $cardId)->value('qr_token');

        $this->actingAs($company->hr)->post('/id-cards/'.$cardId.'/reissue')->assertRedirect();
        $old = DB::table('employee_id_cards')->where('id', $cardId)->first();
        $this->assertSame('reissued', $old->status);
        $this->assertNotNull($old->deactivated_at);

        $new = DB::table('employee_id_cards')
            ->where('tenant_id', $company->id)
            ->where('employee_id', $employeeId)
            ->where('status', 'active')
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($new);
        $this->assertSame($cardId, (int) $new->reissued_from_id);
        $this->assertNotSame($oldToken, (string) $new->qr_token);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $new->qr_token);
        $this->assertSame(1, DB::table('employee_id_cards')->where('tenant_id', $company->id)->where('employee_id', $employeeId)->where('status', 'active')->count());

        $this->get('/id-cards/verify/'.$oldToken)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('verification.valid', false)
            ->where('verification.status', 'reissued')
            ->where('verification.name', '')
            ->where('verification.employeeCode', '')
            ->where('verification.companyName', '')
            ->where('verification.cardNumber', '')
            ->missing('verification.bloodGroup')
            ->missing('verification.emergencyContactNumber')
        );
        $this->get('/id-cards/verify/'.$new->qr_token)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('verification.valid', true)
            ->where('verification.status', 'active')
            ->where('verification.employeeCode', 'idcard-lifecycle-REP')
        );

        $this->actingAs($company->hr)->post('/id-cards/'.$new->id.'/deactivate')->assertRedirect();
        $this->assertSame('inactive', DB::table('employee_id_cards')->where('id', $new->id)->value('status'));
        $this->actingAs($company->hr)->get('/id-cards/'.$new->id.'/pdf')->assertNotFound();
        $this->get('/id-cards/verify/'.$new->qr_token)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('verification.valid', false)
            ->where('verification.status', 'inactive')
            ->where('verification.name', '')
            ->where('verification.employeeCode', '')
            ->where('verification.message', 'This employee ID card is no longer valid.')
        );

        $tokens = DB::table('employee_id_cards')->where('tenant_id', $company->id)->pluck('qr_token');
        $this->assertSame($tokens->count(), $tokens->unique()->count());
    }

    public function test_issue_requires_readiness_and_keeps_tenants_isolated(): void
    {
        $this->withoutVite();
        Storage::fake('local');
        $company = CompanyFixture::make('ID Card Ready Co', 'idcard-ready');
        $other = CompanyFixture::make('Other ID Card Ready Co', 'other-idcard-ready');
        $accounts = $this->accounts($company);

        $this->actingAs($company->hr)->post('/id-cards', [
            'employee_id' => $company->reportEmployeeId,
            'country' => 'india',
            'blood_group' => 'A+',
            'emergency_contact_number' => '+91-9000000002',
            'designation' => 'Software Engineer',
            'department' => 'Engineering',
        ])->assertRedirect();
        $cardId = (int) DB::table('employee_id_cards')->where('tenant_id', $company->id)->value('id');

        $this->from('/id-cards?employee='.$company->reportEmployeeId)
            ->actingAs($company->hr)
            ->post('/id-cards/'.$cardId.'/issue')
            ->assertSessionHasErrors('card');

        $this->seedCompanyProfile($company, 'india');
        $this->actingAs($company->hr)->post('/id-cards', [
            'employee_id' => $company->reportEmployeeId,
            'country' => 'india',
            'blood_group' => 'A+',
            'emergency_contact_number' => '+91-9000000002',
            'designation' => 'Software Engineer',
            'department' => 'Engineering',
            'photo' => UploadedFile::fake()->image('face.png', 160, 200),
        ])->assertRedirect();

        foreach ([$company->employee, $company->lead, $accounts] as $user) {
            $this->actingAs($user)->post('/id-cards/'.$cardId.'/issue')->assertForbidden();
            $this->actingAs($user)->post('/id-cards/'.$cardId.'/reissue')->assertForbidden();
            $this->actingAs($user)->post('/id-cards/'.$cardId.'/deactivate')->assertForbidden();
        }

        $this->actingAs($company->hr)->post('/id-cards/'.$cardId.'/issue')->assertRedirect();
        $this->actingAs($other->hr)->post('/id-cards/'.$cardId.'/reissue')->assertNotFound();
        $this->actingAs($other->hr)->post('/id-cards/'.$cardId.'/deactivate')->assertNotFound();
        $this->get('/id-cards/verify/not-a-valid-token')->assertNotFound();
        $this->get('/id-cards/verify/'.str_repeat('a', 64))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('verification.valid', false)
            ->where('verification.status', 'invalid')
        );
    }

    public function test_pdf_renderer_uses_cr80_portrait_dimensions(): void
    {
        $pdf = (new EmployeeIdCardPdf)->render([
            'name' => 'Sample Employee',
            'employeeCode' => 'EMP-100',
            'designation' => 'Engineer',
            'department' => 'Delivery',
            'email' => 'sample@example.test',
            'bloodGroup' => 'O+',
            'emergencyContactNumber' => '+91-9000000000',
            'companyDisplayName' => 'Veltrix',
            'footerAddress' => 'Hyderabad, India',
            'verifyUrl' => 'https://hrms.example.test/id-cards/verify/'.str_repeat('ab', 32),
            'logoPath' => null,
            'photoPath' => null,
        ]);

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertSame(2, (new EmployeeIdCardPdf)->pages($pdf));
        $this->assertSame(EmployeeIdCardPdf::WIDTH_MM, 53.98);
        $this->assertSame(EmployeeIdCardPdf::HEIGHT_MM, 85.60);
        $this->assertStringContainsString('/MediaBox [0.00 0.00 153.01 242.65]', $this->normalizePdfBoxes($pdf));
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

    private function seedCardDetails(CompanyFixture $company, int $employeeId, string $country, string $blood, string $emergency): void
    {
        $this->actingAs($company->hr)->post('/id-cards', [
            'employee_id' => $employeeId,
            'country' => $country,
            'blood_group' => $blood,
            'emergency_contact_number' => $emergency,
            'designation' => 'Software Engineer',
            'department' => 'Engineering',
            'photo' => UploadedFile::fake()->image('badge.png', 180, 220),
        ])->assertRedirect();
    }

    /**
     * @return array<string, mixed>
     */
    private function employeeSnapshot(int $employeeId): array
    {
        return (array) DB::table('employees')->where('id', $employeeId)->first();
    }

    private function normalizePdfBoxes(string $pdf): string
    {
        return (string) preg_replace_callback('/\/MediaBox \[([0-9.]+) ([0-9.]+) ([0-9.]+) ([0-9.]+)\]/', function (array $matches): string {
            return sprintf(
                '/MediaBox [%s %s %s %s]',
                number_format((float) $matches[1], 2, '.', ''),
                number_format((float) $matches[2], 2, '.', ''),
                number_format((float) $matches[3], 2, '.', ''),
                number_format((float) $matches[4], 2, '.', ''),
            );
        }, $pdf);
    }

    private function accounts(CompanyFixture $company): User
    {
        DB::table('roles')->insert([
            'tenant_id' => $company->id,
            'name' => 'Accounts',
            'guard_name' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::factory()->create();
        $user->forceFill([
            'tenant_id' => $company->id,
            'is_platform_admin' => false,
            'invite_accepted_at' => now(),
        ])->save();
        DB::table('model_has_roles')->insert([
            'role_id' => DB::table('roles')->where('tenant_id', $company->id)->where('name', 'Accounts')->value('id'),
            'model_type' => User::class,
            'model_id' => $user->id,
            'tenant_id' => $company->id,
        ]);

        return $user->refresh();
    }
}
