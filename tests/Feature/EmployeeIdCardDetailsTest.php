<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CompanyFixture;
use Tests\TestCase;

class EmployeeIdCardDetailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_hr_can_save_and_edit_card_details_without_duplicating_or_changing_employees(): void
    {
        $this->withoutVite();
        Storage::fake('local');
        $company = CompanyFixture::make('ID Card Details Co', 'idcard-details');
        $owner = $this->member($company, 'Owner');
        $employeeId = $company->reportEmployeeId;
        $snapshot = $this->employeeSnapshot($employeeId);

        $this->actingAs($company->hr)->get('/id-cards')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('id-cards/manage')
            ->where('selectedEmployeeId', null)
            ->has('employees', 3)
            ->where('bloodGroups.0', 'A+')
        );

        $this->actingAs($company->hr)->post('/id-cards', [
            'employee_id' => $employeeId,
            'country' => 'india',
            'blood_group' => 'O+',
            'emergency_contact_number' => '+91-9000000000',
            'designation' => 'Software Engineer',
            'department' => 'Engineering',
            'photo' => UploadedFile::fake()->image('badge.png', 200, 240),
        ])->assertRedirect('/id-cards?employee='.$employeeId);

        $card = DB::table('employee_id_cards')
            ->where('tenant_id', $company->id)
            ->where('employee_id', $employeeId)
            ->first();
        $this->assertNotNull($card);
        $this->assertSame('draft', $card->status);
        $this->assertSame('O+', $card->blood_group);
        $this->assertSame('+91-9000000000', $card->emergency_contact_number);
        $this->assertSame('Software Engineer', $card->designation);
        $this->assertSame('Engineering', $card->department);
        $this->assertSame('india', $card->country);
        $this->assertNotEmpty($card->qr_token);
        $this->assertStringStartsWith('id-cards/'.$company->id.'/photos/', (string) $card->photo_path);
        Storage::disk('local')->assertExists((string) $card->photo_path);
        $this->assertSame($snapshot, $this->employeeSnapshot($employeeId));

        $this->actingAs($owner)->get('/id-cards?employee='.$employeeId)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('id-cards/manage')
            ->where('selectedEmployeeId', $employeeId)
            ->where('employee.code', 'idcard-details-REP')
            ->where('employee.email', $company->reportEmail)
            ->where('card.bloodGroup', 'O+')
            ->where('card.hasPhoto', true)
            ->where('card.emergencyContactNumber', '+91-9000000000')
            ->where('card.designation', 'Software Engineer')
            ->where('card.department', 'Engineering')
        );

        $previousPhoto = (string) $card->photo_path;
        $this->actingAs($company->hr)->post('/id-cards', [
            'employee_id' => $employeeId,
            'country' => 'usa',
            'blood_group' => 'B-',
            'emergency_contact_number' => '+1-555-0100',
            'designation' => 'Software Engineer',
            'department' => 'Engineering',
            'photo' => UploadedFile::fake()->image('replacement.jpg', 180, 220),
        ])->assertRedirect('/id-cards?employee='.$employeeId);

        $this->assertSame(1, DB::table('employee_id_cards')->where('tenant_id', $company->id)->where('employee_id', $employeeId)->count());
        $updated = DB::table('employee_id_cards')->where('id', $card->id)->first();
        $this->assertSame('usa', $updated->country);
        $this->assertSame('B-', $updated->blood_group);
        $this->assertSame('+1-555-0100', $updated->emergency_contact_number);
        $this->assertSame($card->qr_token, $updated->qr_token);
        $this->assertNotSame($previousPhoto, (string) $updated->photo_path);
        Storage::disk('local')->assertMissing($previousPhoto);
        Storage::disk('local')->assertExists((string) $updated->photo_path);
        $this->assertSame($snapshot, $this->employeeSnapshot($employeeId));

        $photo = $this->actingAs($company->hr)->get('/id-cards/'.$updated->id.'/photo');
        $photo->assertOk();
        $photo->assertHeader('Cache-Control', 'no-store, private');
        $photo->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_validation_rejects_bad_blood_group_photo_and_foreign_employee(): void
    {
        $this->withoutVite();
        Storage::fake('local');
        $company = CompanyFixture::make('ID Card Validate Co', 'idcard-validate');
        $other = CompanyFixture::make('Other ID Card Validate Co', 'other-idcard-validate');

        $this->from('/id-cards')
            ->actingAs($company->hr)
            ->post('/id-cards', [
                'employee_id' => $company->reportEmployeeId,
                'country' => 'india',
                'blood_group' => 'Z+',
                'emergency_contact_number' => str_repeat('9', 50),
                'designation' => '',
                'department' => '',
                'photo' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
            ])
            ->assertSessionHasErrors(['blood_group', 'emergency_contact_number', 'designation', 'department', 'photo']);

        $this->from('/id-cards')
            ->actingAs($company->hr)
            ->post('/id-cards', [
                'employee_id' => $other->reportEmployeeId,
                'country' => 'india',
                'blood_group' => 'A+',
                'designation' => 'Software Engineer',
                'department' => 'Engineering',
            ])
            ->assertSessionHasErrors('employee_id');

        $this->assertSame(0, DB::table('employee_id_cards')->where('tenant_id', $company->id)->count());
        $employeeColumns = Schema::getColumnListing('employees');
        $this->assertNotContains('blood_group', $employeeColumns);
        $this->assertNotContains('photo_path', $employeeColumns);
        $this->assertNotContains('emergency_contact_number', $employeeColumns);
    }

    public function test_permissions_and_tenant_isolation_for_card_details_and_photos(): void
    {
        $this->withoutVite();
        Storage::fake('local');
        $company = CompanyFixture::make('ID Card Access Details', 'idcard-access-details');
        $other = CompanyFixture::make('Other ID Card Access Details', 'other-idcard-access-details');
        $accounts = $this->accounts($company);

        $this->actingAs($company->hr)->post('/id-cards', [
            'employee_id' => $company->reportEmployeeId,
            'country' => 'india',
            'blood_group' => 'AB+',
            'emergency_contact_number' => '+91-9111111111',
            'designation' => 'Software Engineer',
            'department' => 'Engineering',
            'photo' => UploadedFile::fake()->image('face.png', 120, 140),
        ])->assertRedirect();

        $cardId = (int) DB::table('employee_id_cards')->where('tenant_id', $company->id)->value('id');
        $this->assertGreaterThan(0, $cardId);

        foreach ([$company->employee, $company->lead, $accounts] as $user) {
            $this->actingAs($user)->get('/id-cards')->assertForbidden();
            $this->actingAs($user)->post('/id-cards', [
                'employee_id' => $company->reportEmployeeId,
                'country' => 'india',
                'blood_group' => 'O-',
                'designation' => 'Software Engineer',
                'department' => 'Engineering',
            ])->assertForbidden();
            $this->actingAs($user)->get('/id-cards/'.$cardId.'/photo')->assertForbidden();
        }

        $this->actingAs($other->hr)->get('/id-cards')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('selectedEmployeeId', null)
            ->where('employees', fn ($rows) => collect($rows)->every(fn ($row) => ($row['card']['id'] ?? null) === null))
        );
        $this->actingAs($other->hr)->get('/id-cards/'.$cardId.'/photo')->assertNotFound();
        $this->actingAs($other->hr)->get('/id-cards?employee='.$company->reportEmployeeId)->assertNotFound();
        $this->actingAs($other->hr)->post('/id-cards', [
            'employee_id' => $company->reportEmployeeId,
            'country' => 'usa',
            'blood_group' => 'O+',
            'designation' => 'Software Engineer',
            'department' => 'Engineering',
        ])->assertSessionHasErrors('employee_id');

        $stolenPath = (string) DB::table('employee_id_cards')->where('id', $cardId)->value('photo_path');
        DB::table('employee_id_cards')->insert([
            'tenant_id' => $other->id,
            'employee_id' => $other->reportEmployeeId,
            'country' => 'india',
            'blood_group' => 'A+',
            'photo_path' => $stolenPath,
            'photo_mime' => 'image/png',
            'status' => 'draft',
            'qr_token' => 'other-tenant-stolen-photo-token-001',
            'created_by' => $other->hr->id,
            'updated_by' => $other->hr->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherCardId = (int) DB::table('employee_id_cards')->where('tenant_id', $other->id)->value('id');
        $this->actingAs($other->hr)->get('/id-cards/'.$otherCardId.'/photo')->assertNotFound();
        $this->actingAs($other->hr)->get('/id-cards?employee='.$other->reportEmployeeId)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('card.hasPhoto', false)
        );
    }

    public function test_hr_can_edit_designation_and_department_without_changing_employee_or_qr(): void
    {
        $this->withoutVite();
        Storage::fake('local');
        $company = CompanyFixture::make('ID Card Role Edit Co', 'idcard-role-edit');
        $employeeId = $company->reportEmployeeId;
        $snapshot = $this->employeeSnapshot($employeeId);

        $this->actingAs($company->hr)->post('/id-cards/settings', [
            'country' => 'india',
            'legal_name' => 'Veltrix Digital Solutions Private Limited',
            'display_name' => 'Veltrix India',
            'office_address' => 'Hyderabad',
            'contact_number' => '+91-40-00000000',
            'email' => 'india@veltrix.test',
            'website' => 'https://veltrix.test',
            'footer_address' => 'Hyderabad, India',
            'logo' => UploadedFile::fake()->image('logo.png', 240, 80),
        ])->assertRedirect();

        $this->actingAs($company->hr)->post('/id-cards', [
            'employee_id' => $employeeId,
            'country' => 'india',
            'blood_group' => 'B+',
            'emergency_contact_number' => '+91-98765-43210',
            'designation' => 'Software Engineer',
            'department' => 'Engineering',
            'photo' => UploadedFile::fake()->image('badge.png', 200, 240),
        ])->assertRedirect('/id-cards?employee='.$employeeId);

        $cardId = (int) DB::table('employee_id_cards')->where('tenant_id', $company->id)->where('employee_id', $employeeId)->value('id');
        $token = (string) DB::table('employee_id_cards')->where('id', $cardId)->value('qr_token');
        $this->actingAs($company->hr)->post('/id-cards/'.$cardId.'/issue')->assertRedirect();

        $issueBefore = DB::table('employee_id_card_issues')->where('id_card_id', $cardId)->orderByDesc('id')->first();
        $this->assertNotNull($issueBefore);
        $pdfBefore = (string) Storage::disk('local')->get((string) $issueBefore->pdf_path);
        $payloadBefore = json_decode((string) $issueBefore->payload_json, true);
        $this->assertSame('Software Engineer', $payloadBefore['designation'] ?? null);
        $this->assertSame('Engineering', $payloadBefore['department'] ?? null);

        $this->actingAs($company->hr)->post('/id-cards', [
            'employee_id' => $employeeId,
            'country' => 'india',
            'blood_group' => 'B+',
            'emergency_contact_number' => '+91-98765-43210',
            'designation' => 'Operations Manager',
            'department' => 'Operations',
        ])->assertRedirect('/id-cards?employee='.$employeeId)->assertSessionHas('status');

        $updated = DB::table('employee_id_cards')->where('id', $cardId)->first();
        $this->assertSame('Operations Manager', $updated->designation);
        $this->assertSame('Operations', $updated->department);
        $this->assertSame($token, $updated->qr_token);
        $this->assertSame('active', $updated->status);
        $this->assertSame(1, DB::table('employee_id_card_issues')->where('id_card_id', $cardId)->count());
        $this->assertSame($snapshot, $this->employeeSnapshot($employeeId));

        $this->actingAs($company->hr)->get('/id-cards?employee='.$employeeId)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('card.designation', 'Operations Manager')
            ->where('card.department', 'Operations')
            ->where('issue.preview.front.designation', 'Operations Manager')
            ->where('issue.preview.front.department', 'Operations')
        );

        $issueAfter = DB::table('employee_id_card_issues')->where('id_card_id', $cardId)->orderByDesc('id')->first();
        $this->assertSame((int) $issueBefore->id, (int) $issueAfter->id);
        $this->assertSame((string) $issueBefore->pdf_path, (string) $issueAfter->pdf_path);
        $payloadAfter = json_decode((string) $issueAfter->payload_json, true);
        $this->assertSame('Operations Manager', $payloadAfter['designation'] ?? null);
        $this->assertSame('Operations', $payloadAfter['department'] ?? null);

        $pdfAfter = (string) Storage::disk('local')->get((string) $issueAfter->pdf_path);
        $this->assertStringStartsWith('%PDF', $pdfAfter);
        $this->assertNotSame($pdfBefore, $pdfAfter);
        $this->assertSame($pdfAfter, $this->actingAs($company->hr)->get('/id-cards/'.$cardId.'/pdf')->streamedContent());
    }

    public function test_date_of_birth_is_required_prefilled_and_saved_on_the_employee(): void
    {
        $this->withoutVite();
        Storage::fake('local');
        $company = CompanyFixture::make('ID Card DOB Co', 'idcard-dob');
        $employeeId = $company->reportEmployeeId;
        DB::table('employees')->where('id', $employeeId)->update(['date_of_birth' => null]);

        $this->from('/id-cards?employee='.$employeeId)
            ->actingAs($company->hr)
            ->post('/id-cards', [
                'employee_id' => $employeeId,
                'country' => 'india',
                'blood_group' => 'B+',
                'emergency_contact_number' => '+91-9000000002',
                'designation' => 'Analyst',
                'department' => 'Engineering',
                'photo' => UploadedFile::fake()->image('badge.png', 180, 220),
            ])
            ->assertSessionHasErrors('date_of_birth');
        $this->assertNull(DB::table('employees')->where('id', $employeeId)->value('date_of_birth'));
        $this->assertSame(0, DB::table('employee_id_cards')->where('employee_id', $employeeId)->count());

        $this->actingAs($company->hr)->post('/id-cards', [
            'employee_id' => $employeeId,
            'country' => 'india',
            'blood_group' => 'B+',
            'emergency_contact_number' => '+91-9000000002',
            'designation' => 'Analyst',
            'department' => 'Engineering',
            'date_of_birth' => '1992-03-14',
            'photo' => UploadedFile::fake()->image('badge.png', 180, 220),
        ])->assertRedirect('/id-cards?employee='.$employeeId);

        $this->assertStringStartsWith('1992-03-14', (string) DB::table('employees')->where('id', $employeeId)->value('date_of_birth'));

        $this->actingAs($company->hr)->get('/id-cards?employee='.$employeeId)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('card.dateOfBirth', '1992-03-14')
                ->where('issue.preview.front.dateOfBirth', '1992-03-14')
            );

        $this->actingAs($company->hr)->post('/id-cards/settings', [
            'country' => 'india',
            'legal_name' => 'Veltrix India Pvt Ltd',
            'display_name' => 'Veltrix India',
            'office_address' => 'Hyderabad',
            'footer_address' => 'Hyderabad, India',
        ])->assertRedirect();

        $cardId = (int) DB::table('employee_id_cards')->where('employee_id', $employeeId)->value('id');
        $this->actingAs($company->hr)->post('/id-cards/'.$cardId.'/issue')->assertRedirect();
        $payload = json_decode((string) DB::table('employee_id_card_issues')->where('id_card_id', $cardId)->value('payload_json'), true);
        $this->assertSame('1992-03-14', $payload['dateOfBirth'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function employeeSnapshot(int $employeeId): array
    {
        return (array) DB::table('employees')->where('id', $employeeId)->first();
    }

    private function member(CompanyFixture $company, string $role): User
    {
        $user = User::factory()->create();
        $user->forceFill([
            'tenant_id' => $company->id,
            'is_platform_admin' => false,
            'invite_accepted_at' => now(),
        ])->save();
        DB::table('model_has_roles')->insert([
            'role_id' => DB::table('roles')->where('tenant_id', $company->id)->where('name', $role)->value('id'),
            'model_type' => User::class,
            'model_id' => $user->id,
            'tenant_id' => $company->id,
        ]);

        return $user->refresh();
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

        return $this->member($company, 'Accounts');
    }
}
