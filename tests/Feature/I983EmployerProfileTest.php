<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Hrms\I983EmployerProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
        $this->assertSame('hr@veltrixds.com', $training->official_email);
        $this->assertSame('+1(248) 755-3888', $training->official_phone);
        $this->assertSame('Veltrix Profile LLC', $training->site_name);
        $this->assertSame('37068 Grand River Ave, Farmington, MI 48335', $training->site_address);
        $this->assertSame('remote', $training->work_arrangement);
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
        $this->assertSame('Case Only LLC', DB::table('i983_employer_profiles')->where('tenant_id', $company->id)->value('legal_name'));
        $this->assertSame('Case Only LLC', DB::table('i983_employers')->where('case_id', $caseId)->value('legal_name'));
        $this->assertSame('The student will receive direct supervision and guidance from the assigned supervisor throughout the training period. The supervisor will provide project assignments, explain technical requirements, monitor daily activities, and review completed work. Regular meetings will be conducted to discuss progress, address technical challenges, and provide constructive feedback. The supervisor will also ensure that the student\'s work aligns with the training objectives and provides opportunities to apply and develop skills related to the STEM degree.', $training->oversight);

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

    public function test_a_combined_address_and_training_defaults_fill_new_cases_only(): void
    {
        $parsed = app(I983EmployerProfile::class)->splitAddress('37085 Grand River Ave, Suite 1105, MI 48335 United States');
        $this->assertSame('37085 Grand River Ave', $parsed['street']);
        $this->assertSame('1105', $parsed['suite']);
        $this->assertSame('', $parsed['city']);
        $this->assertSame('MI', $parsed['state']);
        $this->assertSame('48335', $parsed['zip']);
        $locality = app(I983EmployerProfile::class)->splitLocality('Farmington, MI 48335');
        $this->assertSame(['city' => 'Farmington', 'state' => 'MI', 'zip' => '48335'], $locality);
        $this->assertSame('NorthIndustrialOverflow', app(I983EmployerProfile::class)->splitAddress('NorthIndustrialOverflow')['street']);
        $company = CompanyFixture::make('Veltrix Digital Solutions', 'i983-address-split');
        $combined = [
            'legal_name' => 'Veltrix Digital Solutions Inc',
            'website' => 'https://veltrixds.com',
            'street' => '37085 Grand River Ave, Suite 1105, Farmington, MI 48335 United States',
            'suite' => '',
            'city' => '',
            'state' => '',
            'zip' => '',
            'ein' => '43-2316091',
            'employee_count' => 20,
            'naics' => '541',
            'oversight' => 'Custom oversight for the next student.',
            'measures' => 'Custom measures for the next student.',
        ];

        $this->actingAs($company->hr)->put('/company/settings/i983-employer', $combined)->assertRedirect();
        $stored = DB::table('i983_employer_profiles')->where('tenant_id', $company->id)->first();
        $this->assertSame('37085 Grand River Ave', $stored->street);
        $this->assertSame('1105', $stored->suite);
        $this->assertSame('Farmington', $stored->city);
        $this->assertSame('MI', $stored->state);
        $this->assertSame('48335', $stored->zip);
        $this->assertSame('Custom oversight for the next student.', $stored->oversight);

        $this->actingAs($company->hr)->post('/i983', [
            'candidate_name' => 'First Student',
            'candidate_email' => 'first.address@example.test',
        ])->assertRedirect();
        $firstId = (int) DB::table('i983_cases')->where('tenant_id', $company->id)->value('id');
        $firstEmployer = DB::table('i983_employers')->where('case_id', $firstId)->first();
        $this->assertSame('37085 Grand River Ave', $firstEmployer->street);
        $this->assertSame('1105', $firstEmployer->suite);
        $this->assertSame('Farmington', $firstEmployer->city);
        $this->assertSame('MI', $firstEmployer->state);
        $this->assertSame('48335', $firstEmployer->zip);
        $this->assertSame(20, (int) $firstEmployer->employee_count);
        $this->assertSame('541', $firstEmployer->naics);
        $this->assertNull($firstEmployer->opt_hours);
        $this->assertNull($firstEmployer->start_date);
        $this->assertSame('Custom oversight for the next student.', DB::table('i983_training_plans')->where('case_id', $firstId)->value('oversight'));
        $this->assertSame('Custom measures for the next student.', DB::table('i983_training_plans')->where('case_id', $firstId)->value('measures'));

        $this->actingAs($company->hr)->put('/company/settings/i983-employer', [
            ...$combined,
            'street' => '1 Main Street',
            'suite' => '',
            'city' => 'Troy',
            'state' => 'MI',
            'zip' => '48084',
            'oversight' => 'Updated oversight.',
            'measures' => 'Updated measures.',
        ])->assertRedirect();
        $this->assertSame('Custom oversight for the next student.', DB::table('i983_training_plans')->where('case_id', $firstId)->value('oversight'));
        $this->assertSame('37085 Grand River Ave', DB::table('i983_employers')->where('case_id', $firstId)->value('street'));

        $this->actingAs($company->hr)->post('/i983', [
            'candidate_name' => 'Second Student',
            'candidate_email' => 'second.address@example.test',
        ])->assertRedirect();
        $secondId = (int) DB::table('i983_cases')->where('candidate_email', 'second.address@example.test')->value('id');
        $this->assertSame('1 Main Street', DB::table('i983_employers')->where('case_id', $secondId)->value('street'));
        $this->assertSame('Troy', DB::table('i983_employers')->where('case_id', $secondId)->value('city'));
        $this->assertSame('Updated oversight.', DB::table('i983_training_plans')->where('case_id', $secondId)->value('oversight'));
        $this->assertSame('Updated measures.', DB::table('i983_training_plans')->where('case_id', $secondId)->value('measures'));
        $this->assertSame('Custom oversight for the next student.', DB::table('i983_training_plans')->where('case_id', $firstId)->value('oversight'));
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

    public function test_compensation_defaults_fill_new_cases_and_annual_salary_follows_the_hourly_rate(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions', 'i983-compensation');
        $other = CompanyFixture::make('Other Company', 'i983-compensation-other');
        $saved = [
            ...$this->profile(),
            'opt_hours' => 25,
            'hourly_rate' => '20',
            'salary_frequency' => 'year',
            'annual_compensation' => '1.00',
        ];

        $this->actingAs($company->hr)->put('/company/settings/i983-employer', $saved)->assertRedirect();
        $stored = DB::table('i983_employer_profiles')->where('tenant_id', $company->id)->first();
        $this->assertSame(25, (int) $stored->opt_hours);
        $this->assertSame('20.00', $this->money($stored->hourly_rate));
        $this->assertSame('26000.00', $this->money($stored->annual_compensation));
        $this->assertFalse(Schema::hasColumn('i983_employer_profiles', 'start_date'));
        $this->actingAs($company->hr)->get('/company/settings')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('i983Employer.optHours', '25')
            ->where('i983Employer.hourlyRate', '20.00')
            ->where('i983Employer.annualCompensation', '26000.00')
        );

        $this->actingAs($company->hr)->post('/i983', [
            'candidate_name' => 'First Student',
            'candidate_email' => 'first.pay@example.test',
        ])->assertRedirect();
        $firstId = (int) DB::table('i983_cases')->where('candidate_email', 'first.pay@example.test')->value('id');
        $first = DB::table('i983_employers')->where('case_id', $firstId)->first();
        $this->assertSame(25, (int) $first->opt_hours);
        $this->assertSame('20.00', $this->money($first->hourly_rate));
        $this->assertSame('26000.00', $this->money($first->annual_compensation));
        $this->assertSame('26000.00', $this->money($first->salary));
        $this->assertNull($first->start_date);
        $this->assertNull($first->other_compensation);

        $this->actingAs($company->hr)->post('/i983/'.$firstId.'/employer', [
            ...$this->profile(),
            'website' => 'https://veltrixds.com',
        ])->assertRedirect();
        $this->assertSame(25, (int) DB::table('i983_employers')->where('case_id', $firstId)->value('opt_hours'));
        $this->assertSame(25, (int) DB::table('i983_employer_profiles')->where('tenant_id', $company->id)->value('opt_hours'));

        $this->actingAs($company->hr)->post('/i983', [
            'candidate_name' => 'Second Student',
            'candidate_email' => 'second.pay@example.test',
        ])->assertRedirect();
        $secondId = (int) DB::table('i983_cases')->where('candidate_email', 'second.pay@example.test')->value('id');

        $this->actingAs($company->hr)->post('/i983/'.$firstId.'/employer', [
            ...$this->profile(),
            'opt_hours' => 30,
            'hourly_rate' => '22',
            'salary_frequency' => 'year',
            'annual_compensation' => '9.00',
            'start_date' => '2026-09-02',
            'other_compensation' => 'Transit',
        ])->assertRedirect();
        $updated = DB::table('i983_employers')->where('case_id', $firstId)->first();
        $this->assertSame(30, (int) $updated->opt_hours);
        $this->assertSame('22.00', $this->money($updated->hourly_rate));
        $this->assertSame('34320.00', $this->money($updated->annual_compensation));
        $this->assertSame('34320.00', $this->money($updated->salary));
        $this->assertSame('2026-09-02', (string) $updated->start_date);
        $this->assertSame('Transit', $updated->other_compensation);
        $profile = DB::table('i983_employer_profiles')->where('tenant_id', $company->id)->first();
        $this->assertSame(30, (int) $profile->opt_hours);
        $this->assertSame('22.00', $this->money($profile->hourly_rate));
        $this->assertSame('34320.00', $this->money($profile->annual_compensation));

        $second = DB::table('i983_employers')->where('case_id', $secondId)->first();
        $this->assertSame(25, (int) $second->opt_hours);
        $this->assertSame('26000.00', $this->money($second->annual_compensation));
        $this->assertNull($second->start_date);
        $this->assertNull($second->other_compensation);

        $this->actingAs($company->hr)->post('/i983', [
            'candidate_name' => 'Third Student',
            'candidate_email' => 'third.pay@example.test',
        ])->assertRedirect();
        $thirdId = (int) DB::table('i983_cases')->where('candidate_email', 'third.pay@example.test')->value('id');
        $third = DB::table('i983_employers')->where('case_id', $thirdId)->first();
        $this->assertSame(30, (int) $third->opt_hours);
        $this->assertSame('34320.00', $this->money($third->annual_compensation));
        $this->assertSame(25, (int) DB::table('i983_employers')->where('case_id', $secondId)->value('opt_hours'));

        DB::table('i983_employers')->where('case_id', $secondId)->update([
            'opt_hours' => null,
            'hourly_rate' => null,
            'salary' => null,
            'salary_frequency' => null,
            'annual_compensation' => null,
        ]);
        $this->actingAs($company->hr)->get('/i983/'.$secondId)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('employer.optHours', '30')
            ->where('employer.hourlyRate', '22.00')
            ->where('employer.annualCompensation', '34320.00')
            ->where('employer.startDate', '')
        );
        $this->assertNull(DB::table('i983_employers')->where('case_id', $secondId)->value('opt_hours'));

        $this->actingAs($other->hr)->post('/i983', [
            'candidate_name' => 'Other Student',
            'candidate_email' => 'other.pay@example.test',
        ])->assertRedirect();
        $otherId = (int) DB::table('i983_cases')->where('candidate_email', 'other.pay@example.test')->value('id');
        $this->assertNull(DB::table('i983_employers')->where('case_id', $otherId)->value('opt_hours'));
        $this->assertSame(0, DB::table('i983_employer_profiles')->where('tenant_id', $other->id)->count());

        $this->actingAs($company->hr)->put('/company/settings/i983-employer', [
            ...$this->profile(),
            'opt_hours' => 10,
            'hourly_rate' => '20',
        ])->assertSessionHasErrors('opt_hours');
        $this->actingAs($company->hr)->put('/company/settings/i983-employer', [
            ...$this->profile(),
            'opt_hours' => 25,
        ])->assertSessionHasErrors('hourly_rate');
        $this->assertSame(30, (int) DB::table('i983_employer_profiles')->where('tenant_id', $company->id)->value('opt_hours'));
        $this->assertSame('34320.00', $this->money(DB::table('i983_employer_profiles')->where('tenant_id', $company->id)->value('annual_compensation')));
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
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
