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

class StandaloneIdCardFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_hr_can_create_edit_search_and_issue_standalone_card_without_touching_employees(): void
    {
        $this->withoutVite();
        Storage::fake('local');
        $company = CompanyFixture::make('Standalone Feature Co', 'standalone-feature');
        $other = CompanyFixture::make('Other Standalone Feature Co', 'other-standalone-feature');
        $employeeSnapshot = $this->employeesSnapshot($company->id);
        $employeeCount = count($employeeSnapshot);

        $this->seedCompanyProfile($company, 'india');

        $this->actingAs($company->hr)->get('/id-cards/standalone')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('id-cards/standalone/index')
            ->has('cards')
            ->where('selectedCardId', null)
        );

        $create = $this->actingAs($company->hr)->post('/id-cards/standalone', [
            'full_name' => 'Priya Standalone',
            'card_number' => 'STC-100',
            'designation' => 'Visitor',
            'department' => 'Facilities',
            'email' => 'priya.standalone@example.test',
            'phone' => '+91-9000000100',
            'country' => 'india',
            'date_of_birth' => '1994-06-15',
            'blood_group' => 'A+',
            'emergency_contact_number' => '+91-9000000101',
            'photo' => UploadedFile::fake()->image('guest.png', 160, 200),
        ]);
        $create->assertRedirect();

        $cardId = (int) DB::table('standalone_id_cards')->where('tenant_id', $company->id)->where('card_number', 'STC-100')->value('id');
        $this->assertGreaterThan(0, $cardId);
        $photoPath = (string) DB::table('standalone_id_cards')->where('id', $cardId)->value('photo_path');
        $this->assertStringStartsWith('id-cards/'.$company->id.'/standalone/photos/', $photoPath);
        Storage::disk('local')->assertExists($photoPath);
        $token = (string) DB::table('standalone_id_cards')->where('id', $cardId)->value('qr_token');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);

        $this->actingAs($company->hr)->get('/id-cards/standalone?q=Priya')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('cards.0.fullName', 'Priya Standalone')
            ->where('cards.0.cardNumber', 'STC-100')
        );

        $this->actingAs($company->hr)->from('/id-cards/standalone?card='.$cardId)->post('/id-cards/standalone/'.$cardId, [
            'full_name' => 'Priya Standalone Updated',
            'card_number' => 'STC-100',
            'designation' => 'Contractor',
            'department' => 'Facilities',
            'email' => 'priya.standalone@example.test',
            'phone' => '+91-9000000100',
            'country' => 'india',
            'date_of_birth' => '1994-06-15',
            'blood_group' => 'A+',
            'emergency_contact_number' => '+91-9000000101',
        ])->assertRedirect('/id-cards/standalone?card='.$cardId);

        $this->assertSame('Priya Standalone Updated', DB::table('standalone_id_cards')->where('id', $cardId)->value('full_name'));
        $this->assertSame('Contractor', DB::table('standalone_id_cards')->where('id', $cardId)->value('designation'));

        $this->actingAs($company->hr)->from('/id-cards/standalone?card='.$cardId)->post('/id-cards/standalone/'.$cardId.'/issue')
            ->assertRedirect('/id-cards/standalone?card='.$cardId);

        $card = DB::table('standalone_id_cards')->where('id', $cardId)->first();
        $this->assertSame('active', $card->status);
        $this->assertNotNull($card->issued_at);

        $issue = DB::table('standalone_id_card_issues')->where('id_card_id', $cardId)->orderByDesc('id')->first();
        $this->assertNotNull($issue);
        $this->assertStringStartsWith('id-cards/'.$company->id.'/standalone/pdfs/', (string) $issue->pdf_path);
        Storage::disk('local')->assertExists((string) $issue->pdf_path);
        $pdfBytes = (string) Storage::disk('local')->get((string) $issue->pdf_path);
        $this->assertStringStartsWith('%PDF', $pdfBytes);
        $this->assertSame(2, (new EmployeeIdCardPdf)->pages($pdfBytes));
        $this->assertStringContainsString('/MediaBox [0.00 0.00 153.01 242.65]', $this->normalizePdfBoxes($pdfBytes));

        $payload = json_decode((string) $issue->payload_json, true);
        $this->assertIsArray($payload);
        $this->assertSame('A+', $payload['bloodGroup']);
        $this->assertSame('STC-100', $payload['employeeCode']);
        $this->assertArrayNotHasKey('logoPath', $payload);
        $this->assertArrayNotHasKey('photoPath', $payload);

        $download = $this->actingAs($company->hr)->get('/id-cards/standalone/'.$cardId.'/pdf');
        $download->assertOk();
        $download->assertHeader('Content-Type', 'application/pdf');
        $download->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame($pdfBytes, $download->streamedContent());

        $photo = $this->actingAs($company->hr)->get('/id-cards/standalone/'.$cardId.'/photo');
        $photo->assertOk();
        $photo->assertHeader('Cache-Control', 'no-store, private');

        $this->actingAs($other->hr)->get('/id-cards/standalone/'.$cardId.'/pdf')->assertNotFound();
        $this->actingAs($other->hr)->get('/id-cards/standalone/'.$cardId.'/photo')->assertNotFound();
        $this->actingAs($other->hr)->post('/id-cards/standalone/'.$cardId.'/issue')->assertNotFound();

        $verify = $this->get('/id-cards/verify/'.$token);
        $verify->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('id-cards/verify')
            ->where('verification.valid', true)
            ->where('verification.status', 'active')
            ->where('verification.name', 'Priya Standalone Updated')
            ->where('verification.employeeCode', 'STC-100')
            ->where('verification.companyName', 'Veltrix India')
            ->missing('verification.bloodGroup')
            ->missing('verification.emergencyContactNumber')
            ->missing('verification.photoPath')
            ->missing('verification.photoUrl')
        );

        $html = $verify->assertInertia(fn (Assert $page) => $page)->getContent();
        $this->assertStringNotContainsString('A+', $html);
        $this->assertStringNotContainsString('+91-9000000101', $html);
        $this->assertStringNotContainsString('id-cards/'.$company->id.'/standalone/photos/', $html);

        $this->assertSame($employeeCount, (int) DB::table('employees')->where('tenant_id', $company->id)->count());
        $this->assertSame($employeeSnapshot, $this->employeesSnapshot($company->id));
        $this->assertSame(0, DB::table('employee_id_cards')->where('tenant_id', $company->id)->count());
    }

    public function test_reissue_and_deactivate_invalidate_previous_qr_and_pdf_access(): void
    {
        $this->withoutVite();
        Storage::fake('local');
        $company = CompanyFixture::make('Standalone Lifecycle Co', 'standalone-lifecycle');
        $this->seedCompanyProfile($company, 'usa');
        $cardId = $this->createReadyCard($company, 'STC-200', 'usa', 'B+', '+1-555-0200');
        $this->actingAs($company->hr)->post('/id-cards/standalone/'.$cardId.'/issue')->assertRedirect();
        $oldToken = (string) DB::table('standalone_id_cards')->where('id', $cardId)->value('qr_token');

        $this->actingAs($company->hr)->post('/id-cards/standalone/'.$cardId.'/reissue')->assertRedirect();
        $old = DB::table('standalone_id_cards')->where('id', $cardId)->first();
        $this->assertSame('reissued', $old->status);
        $this->assertSame('STC-200#R'.$cardId, $old->card_number);
        $this->assertNotNull($old->deactivated_at);

        $new = DB::table('standalone_id_cards')
            ->where('tenant_id', $company->id)
            ->where('card_number', 'STC-200')
            ->where('status', 'active')
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($new);
        $this->assertSame($cardId, (int) $new->reissued_from_id);
        $this->assertNotSame($oldToken, (string) $new->qr_token);
        $this->assertStringStartsWith('id-cards/'.$company->id.'/standalone/photos/', (string) $new->photo_path);
        Storage::disk('local')->assertExists((string) $new->photo_path);

        $this->actingAs($company->hr)->get('/id-cards/standalone/'.$cardId.'/pdf')->assertNotFound();
        $this->get('/id-cards/verify/'.$oldToken)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('verification.valid', false)
            ->where('verification.status', 'reissued')
            ->where('verification.name', '')
            ->where('verification.employeeCode', '')
            ->where('verification.companyName', '')
            ->missing('verification.bloodGroup')
        );
        $this->get('/id-cards/verify/'.$new->qr_token)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('verification.valid', true)
            ->where('verification.employeeCode', 'STC-200')
        );

        $this->actingAs($company->hr)->post('/id-cards/standalone/'.$new->id.'/deactivate')->assertRedirect();
        $this->assertSame('inactive', DB::table('standalone_id_cards')->where('id', $new->id)->value('status'));
        $this->actingAs($company->hr)->get('/id-cards/standalone/'.$new->id.'/pdf')->assertNotFound();
        $this->get('/id-cards/verify/'.$new->qr_token)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('verification.valid', false)
            ->where('verification.status', 'inactive')
            ->where('verification.name', '')
            ->where('verification.message', 'This ID card is no longer valid.')
        );
    }

    public function test_permissions_and_tenant_isolation_for_standalone_cards(): void
    {
        $this->withoutVite();
        Storage::fake('local');
        $company = CompanyFixture::make('Standalone Perm Co', 'standalone-perm');
        $other = CompanyFixture::make('Other Standalone Perm Co', 'other-standalone-perm');
        $accounts = $this->accounts($company);

        $owner = $this->owner($company);

        $this->actingAs($company->employee)->get('/id-cards/standalone')->assertForbidden();
        $this->actingAs($company->lead)->get('/id-cards/standalone')->assertForbidden();
        $this->actingAs($accounts)->get('/id-cards/standalone')->assertForbidden();
        $this->actingAs($company->hr)->get('/id-cards/standalone')->assertOk();
        $this->actingAs($owner)->get('/id-cards/standalone')->assertOk();

        $this->seedCompanyProfile($company, 'india');
        $cardId = $this->createReadyCard($company, 'STC-300', 'india', 'O+', '+91-9000000300');

        foreach ([$company->employee, $company->lead, $accounts] as $user) {
            $this->actingAs($user)->post('/id-cards/standalone/'.$cardId.'/issue')->assertForbidden();
            $this->actingAs($user)->post('/id-cards/standalone/'.$cardId.'/reissue')->assertForbidden();
            $this->actingAs($user)->post('/id-cards/standalone/'.$cardId.'/deactivate')->assertForbidden();
            $this->actingAs($user)->get('/id-cards/standalone/'.$cardId.'/pdf')->assertForbidden();
        }

        $this->actingAs($company->hr)->post('/id-cards/standalone/'.$cardId.'/issue')->assertRedirect();
        $this->actingAs($other->hr)->post('/id-cards/standalone/'.$cardId.'/reissue')->assertNotFound();
        $this->actingAs($other->hr)->post('/id-cards/standalone/'.$cardId.'/deactivate')->assertNotFound();
        $this->actingAs($other->hr)->get('/id-cards/standalone?card='.$cardId)->assertNotFound();

        $this->actingAs($company->hr)->post('/id-cards/standalone', [
            'full_name' => 'Dup Number',
            'card_number' => 'STC-300',
            'designation' => 'X',
            'department' => 'Y',
            'country' => 'india',
            'date_of_birth' => '1994-06-15',
            'blood_group' => 'O+',
            'emergency_contact_number' => '+91-1',
            'photo' => UploadedFile::fake()->image('dup.png', 120, 140),
        ])->assertSessionHasErrors('card_number');
    }

    public function test_issue_requires_readiness_and_unique_qr_tokens(): void
    {
        $this->withoutVite();
        Storage::fake('local');
        $company = CompanyFixture::make('Standalone Ready Co', 'standalone-ready');

        $this->actingAs($company->hr)->post('/id-cards/standalone', [
            'full_name' => 'Not Ready',
            'card_number' => 'STC-400',
            'country' => 'india',
            'date_of_birth' => '1994-06-15',
        ])->assertRedirect();
        $cardId = (int) DB::table('standalone_id_cards')->where('tenant_id', $company->id)->value('id');

        $this->from('/id-cards/standalone?card='.$cardId)
            ->actingAs($company->hr)
            ->post('/id-cards/standalone/'.$cardId.'/issue')
            ->assertSessionHasErrors('card');

        $this->seedCompanyProfile($company, 'india');
        $this->actingAs($company->hr)->post('/id-cards/standalone/'.$cardId, [
            'full_name' => 'Ready Person',
            'card_number' => 'STC-400',
            'designation' => 'Temp',
            'department' => 'Ops',
            'country' => 'india',
            'date_of_birth' => '1994-06-15',
            'blood_group' => 'AB+',
            'emergency_contact_number' => '+91-9000000400',
            'photo' => UploadedFile::fake()->image('ready.png', 140, 180),
        ])->assertRedirect();

        $this->actingAs($company->hr)->post('/id-cards/standalone/'.$cardId.'/issue')->assertRedirect();
        $tokens = DB::table('standalone_id_cards')->where('tenant_id', $company->id)->pluck('qr_token');
        $this->assertSame($tokens->count(), $tokens->unique()->count());
        $this->get('/id-cards/verify/'.str_repeat('f', 64))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('verification.valid', false)
            ->where('verification.status', 'invalid')
        );
    }

    private function createReadyCard(CompanyFixture $company, string $cardNumber, string $country, string $blood, string $emergency): int
    {
        $this->actingAs($company->hr)->post('/id-cards/standalone', [
            'full_name' => 'Standalone '.$cardNumber,
            'card_number' => $cardNumber,
            'designation' => 'Guest',
            'department' => 'Ops',
            'email' => strtolower($cardNumber).'@example.test',
            'phone' => '+91-9000000000',
            'country' => $country,
            'date_of_birth' => '1994-06-15',
            'blood_group' => $blood,
            'emergency_contact_number' => $emergency,
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

    /**
     * @return list<array<string, mixed>>
     */
    private function employeesSnapshot(int $tenantId): array
    {
        return DB::table('employees')
            ->where('tenant_id', $tenantId)
            ->orderBy('id')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->values()
            ->all();
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
        return $this->roleUser($company, 'Accounts', createRole: true);
    }

    private function owner(CompanyFixture $company): User
    {
        return $this->roleUser($company, 'Owner');
    }

    private function roleUser(CompanyFixture $company, string $role, bool $createRole = false): User
    {
        if ($createRole) {
            DB::table('roles')->insert([
                'tenant_id' => $company->id,
                'name' => $role,
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $roleId = DB::table('roles')->where('tenant_id', $company->id)->where('name', $role)->value('id');
        $this->assertNotNull($roleId);

        $user = User::factory()->create();
        $user->forceFill([
            'tenant_id' => $company->id,
            'is_platform_admin' => false,
            'invite_accepted_at' => now(),
        ])->save();
        DB::table('model_has_roles')->insert([
            'role_id' => $roleId,
            'model_type' => User::class,
            'model_id' => $user->id,
            'tenant_id' => $company->id,
        ]);

        return $user->refresh();
    }
}
