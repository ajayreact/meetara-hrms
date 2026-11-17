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
        );

        $previousPhoto = (string) $card->photo_path;
        $this->actingAs($company->hr)->post('/id-cards', [
            'employee_id' => $employeeId,
            'country' => 'usa',
            'blood_group' => 'B-',
            'emergency_contact_number' => '+1-555-0100',
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
                'photo' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
            ])
            ->assertSessionHasErrors(['blood_group', 'emergency_contact_number', 'photo']);

        $this->from('/id-cards')
            ->actingAs($company->hr)
            ->post('/id-cards', [
                'employee_id' => $other->reportEmployeeId,
                'country' => 'india',
                'blood_group' => 'A+',
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
