<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CompanyFixture;
use Tests\TestCase;

class I983EmployerProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_saved_employer_profile_is_copied_onto_new_cases_only(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions', 'i983-profile');
        $other = CompanyFixture::make('Other Company', 'i983-profile-other');
        $owner = $this->owner($company);
        $profile = $this->profile();

        $this->actingAs($company->lead)->put('/company/settings/i983-employer', $profile)->assertForbidden();
        $this->actingAs($company->employee)->put('/company/settings/i983-employer', $profile)->assertForbidden();
        $this->actingAs($company->hr)->put('/company/settings/i983-employer', [...$profile, 'state' => 'Michigan'])->assertSessionHasErrors('state');
        $this->actingAs($company->hr)->put('/company/settings/i983-employer', $profile)->assertRedirect();
        $this->actingAs($owner)->get('/company/settings')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('i983Employer.legalName', 'Veltrix Profile LLC')
            ->where('i983Employer.ein', '12-3456789')
            ->where('i983Employer.employeeCount', '48')
            ->where('i983Employer.naics', '541511')
        );

        $stored = DB::table('i983_employer_profiles')->where('tenant_id', $company->id)->first();
        $this->assertNotNull($stored);
        $this->assertSame($company->hr->id, (int) $stored->updated_by);
        $this->assertNotSame('12-3456789', $stored->ein_ciphertext);
        $this->assertSame('MI', $stored->state);
        $this->assertSame(0, DB::table('i983_employer_profiles')->where('tenant_id', $other->id)->count());

        $this->actingAs($company->hr)->post('/i983', [
            'candidate_name' => 'Ajay Oguri',
            'candidate_email' => 'ajay.profile@example.test',
        ])->assertRedirect();
        $caseId = (int) DB::table('i983_cases')->where('tenant_id', $company->id)->value('id');
        $employer = DB::table('i983_employers')->where('case_id', $caseId)->first();
        $this->assertSame('Veltrix Profile LLC', $employer->legal_name);
        $this->assertSame('37068 Grand River Ave', $employer->street);
        $this->assertSame('1105', $employer->suite);
        $this->assertSame('Farmington', $employer->city);
        $this->assertSame('MI', $employer->state);
        $this->assertSame('48335', $employer->zip);
        $this->assertSame(48, (int) $employer->employee_count);
        $this->assertSame('541511', $employer->naics);
        $this->assertNull($employer->opt_hours);
        $this->assertNull($employer->hourly_rate);
        $this->assertNull($employer->salary);
        $this->assertNull($employer->annual_compensation);
        $this->assertNull($employer->start_date);
        $this->assertNotSame($stored->ein_ciphertext, $employer->ein_ciphertext);
        $training = DB::table('i983_training_plans')->where('case_id', $caseId)->first();
        $this->assertSame('Veltrix Profile LLC', $training->employer_name);
        $this->assertNull($training->official_name);
        $this->assertNull($training->official_title);
        $this->assertNull($training->official_email);
        $this->assertNull($training->site_name);
        $this->assertNull($training->site_address);
        $this->assertNotSame($company->hr->name, $training->official_name);
        $this->assertNotSame($company->hr->email, $training->official_email);

        $draft = $this->actingAs($company->hr)->get('/i983/'.$caseId.'/draft');
        $draft->assertOk();
        $bytes = $draft->getContent();
        $this->assertStringContainsString('(Veltrix Profile LLC)', $bytes);
        $this->assertStringContainsString('(37068 Grand River Ave)', $bytes);
        $this->assertStringContainsString('(Farmington)', $bytes);
        $this->assertStringContainsString('(MI)', $bytes);
        $this->assertStringContainsString('(48335)', $bytes);
        $this->assertStringContainsString('(12-3456789)', $bytes);
        $this->assertStringContainsString('(48)', $bytes);
        $this->assertStringContainsString('(541511)', $bytes);

        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/employer', [
            'legal_name' => 'Case Only LLC',
            'street' => '1 Case Street',
            'city' => 'Troy',
            'state' => 'MI',
            'zip' => '48084',
            'ein' => '98-7654321',
            'employee_count' => 7,
            'naics' => '541512',
        ])->assertRedirect();
        $this->assertSame('Veltrix Profile LLC', DB::table('i983_employer_profiles')->where('tenant_id', $company->id)->value('legal_name'));
        $this->assertSame('Case Only LLC', DB::table('i983_employers')->where('case_id', $caseId)->value('legal_name'));

        $this->actingAs($owner)->put('/company/settings/i983-employer', [...$profile, 'legal_name' => 'Updated Profile LLC'])->assertRedirect();
        $this->assertSame('Updated Profile LLC', DB::table('i983_employer_profiles')->where('tenant_id', $company->id)->value('legal_name'));
        $this->assertSame($owner->id, (int) DB::table('i983_employer_profiles')->where('tenant_id', $company->id)->value('updated_by'));
        $this->assertSame('Case Only LLC', DB::table('i983_employers')->where('case_id', $caseId)->value('legal_name'));

        $this->actingAs($other->hr)->post('/i983', [
            'candidate_name' => 'Other Student',
            'candidate_email' => 'other.profile@example.test',
        ])->assertRedirect();
        $otherCase = (int) DB::table('i983_cases')->where('tenant_id', $other->id)->value('id');
        $this->assertNull(DB::table('i983_employers')->where('case_id', $otherCase)->value('legal_name'));
        $this->actingAs($other->hr)->get('/i983/'.$caseId)->assertNotFound();
    }

    public function test_a_new_case_copies_the_company_signatory_without_the_signed_in_user(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions', 'i983-signatory-copy');
        $company->hr->forceFill([
            'name' => 'Logged In HR',
            'email' => 'logged-in-hr@example.test',
        ])->save();

        $this->actingAs($company->employee)->post('/i983/signature', [
            'signatory_name' => 'Priya Shah',
            'signatory_title' => 'HR Manager',
            'signature' => UploadedFile::fake()->image('signature.jpg', 240, 60),
        ])->assertForbidden();
        $this->actingAs($company->hr)->post('/i983/signature', [
            'signatory_name' => 'Priya Shah',
            'signatory_title' => 'HR Manager',
            'signature' => UploadedFile::fake()->image('signature.jpg', 240, 60),
        ])->assertRedirect();

        $this->actingAs($company->hr)->post('/i983', [
            'candidate_name' => 'Ajay Oguri',
            'candidate_email' => 'ajay.signatory@example.test',
        ])->assertRedirect();
        $caseId = (int) DB::table('i983_cases')->where('tenant_id', $company->id)->value('id');
        $training = DB::table('i983_training_plans')->where('case_id', $caseId)->first();
        $employer = DB::table('i983_employers')->where('case_id', $caseId)->first();

        $this->assertSame('Priya Shah', $training->official_name);
        $this->assertSame('HR Manager', $training->official_title);
        $this->assertNull($training->official_email);
        $this->assertNull($training->official_phone);
        $this->assertNull($training->site_name);
        $this->assertNull($training->site_address);
        $this->assertNotSame('Logged In HR', $training->official_name);
        $this->assertNotSame('logged-in-hr@example.test', $training->official_email);
        $this->assertNull($employer->opt_hours);
        $this->assertNull($employer->hourly_rate);
        $this->assertNull($employer->salary);
        $this->assertNull($employer->start_date);

        $this->actingAs($company->hr)->post('/i983/signature', [
            'signatory_name' => 'New Signer',
            'signatory_title' => 'Director',
        ])->assertRedirect();
        $this->assertSame('Priya Shah', DB::table('i983_training_plans')->where('case_id', $caseId)->value('official_name'));
        $this->assertSame('HR Manager', DB::table('i983_training_plans')->where('case_id', $caseId)->value('official_title'));

        $this->actingAs($company->hr)->post('/i983', [
            'candidate_name' => 'Second Student',
            'candidate_email' => 'second.signatory@example.test',
        ])->assertRedirect();
        $secondId = (int) DB::table('i983_cases')->where('tenant_id', $company->id)->where('candidate_email', 'second.signatory@example.test')->value('id');
        $this->assertSame('New Signer', DB::table('i983_training_plans')->where('case_id', $secondId)->value('official_name'));
        $this->assertSame('Director', DB::table('i983_training_plans')->where('case_id', $secondId)->value('official_title'));
        $this->assertSame('Priya Shah', DB::table('i983_training_plans')->where('case_id', $caseId)->value('official_name'));

        $this->actingAs($company->hr)->get('/company/settings')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('i983Signatory.name', 'New Signer')
            ->where('i983Signatory.title', 'Director')
            ->where('i983Signatory.hasImage', true)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function profile(): array
    {
        return [
            'legal_name' => 'Veltrix Profile LLC',
            'website' => 'https://veltrixds.com',
            'street' => '37068 Grand River Ave',
            'suite' => '1105',
            'city' => 'Farmington',
            'state' => 'mi',
            'zip' => '48335',
            'ein' => '12-3456789',
            'employee_count' => 48,
            'naics' => '541511',
        ];
    }

    private function owner(CompanyFixture $company): User
    {
        $roleId = (int) DB::table('roles')->where('tenant_id', $company->id)->where('name', 'Owner')->value('id');
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
