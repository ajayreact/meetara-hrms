<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CompanyFixture;
use Tests\Support\I983StudentSignature;
use Tests\TestCase;

class I983FormMappingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->isolatePrivateDisk();
    }

    public function test_student_role_compensation_site_and_certification_map_without_changing_existing_files(): void
    {
        Mail::fake();
        $company = CompanyFixture::make('Acme People', 'acme-i983-map');
        $other = CompanyFixture::make('Other Company', 'other-i983-map');
        $offers = DB::table('usa_offers')->count();
        $payroll = DB::table('payroll_runs')->count();
        $this->actingAs($company->hr)->post('/i983', [
            'candidate_name' => 'Riley Quinn',
            'candidate_email' => 'riley.map@example.test',
        ])->assertRedirect();
        $link = (string) session('i983_link');
        $caseId = (int) DB::table('i983_cases')->where('tenant_id', $company->id)->value('id');
        $role = 'Software engineer trainee using the computer science degree and developing testing skills.';

        $this->post($link, $this->student(['student_role' => 'Data analyst trainee'], true))->assertRedirect();
        $snapshot = DB::table('i983_documents')->where('case_id', $caseId)->where('kind', 'student_snapshot')->first();
        $snapshotBytes = Storage::disk('local')->get($snapshot->file_path);

        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/training', [
            'student_role' => $role,
            'goals' => 'Complete one reviewed feature.',
        ])->assertRedirect();
        $this->assertSame($role, DB::table('i983_training_plans')->where('case_id', $caseId)->value('student_role'));
        $revision = DB::table('i983_field_revisions')->where('case_id', $caseId)->where('field_name', 'student_role')->orderByDesc('id')->first();
        $this->assertSame('hr', $revision->actor_type);
        $this->assertSame($company->hr->id, (int) $revision->actor_id);
        $this->assertSame('Data analyst trainee', $revision->previous_value);

        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/employer', [
            'legal_name' => 'Acme People LLC',
            'street' => '1 Main Street',
            'city' => 'Farmington',
            'state' => 'MI',
            'zip' => '48335',
            'ein' => '98-7654321',
            'employee_count' => 12,
            'naics' => '541511',
            'opt_hours' => 20,
            'hourly_rate' => '25.00',
            'salary_frequency' => 'hour',
            'annual_compensation' => '52000',
            'other_compensation' => 'Health coverage',
            'start_date' => '2026-04-06',
        ])->assertRedirect();
        $employer = DB::table('i983_employers')->where('case_id', $caseId)->first();
        $this->assertSame('25.00', number_format((float) $employer->hourly_rate, 2, '.', ''));
        $this->assertSame('hour', $employer->salary_frequency);
        $this->assertSame('26000.00', number_format((float) $employer->annual_compensation, 2, '.', ''));
        $this->assertSame('26000.00', number_format((float) $employer->salary, 2, '.', ''));

        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/training', [
            'site_name' => 'Acme Training Studio',
            'site_address' => '500 Training Plaza, Austin, TX 78701',
            'work_arrangement' => 'hybrid',
            'official_name' => 'Priya Shah',
            'official_title' => 'HR Manager',
            'official_email' => 'priya@acme.test',
            'official_phone' => '248-555-0100',
            'oversight' => 'Weekly review with the hiring manager.',
            'measures' => 'A reviewed project note each month.',
            'remarks' => 'Training stays at the separate site.',
        ])->assertRedirect();

        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/documents/working')->assertSessionHasErrors('signature');
        $this->assertSame(0, DB::table('i983_documents')->where('case_id', $caseId)->where('kind', 'hr_working')->count());
        $this->assertSame($snapshotBytes, Storage::disk('local')->get($snapshot->file_path));

        $this->actingAs($company->hr)->post('/i983/signature', [
            'signatory_name' => 'Priya Shah',
            'signatory_title' => 'HR Manager',
            'signature' => UploadedFile::fake()->image('signature.jpg', 240, 60),
        ])->assertRedirect();
        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/documents/working')->assertRedirect();
        $working = DB::table('i983_documents')->where('case_id', $caseId)->where('kind', 'hr_working')->first();
        $workingBytes = Storage::disk('local')->get($working->file_path);
        $this->assertSame(5, preg_match_all('/\/Type\s*\/Page(?!s)/', $workingBytes));
        $this->assertStringContainsString('46.00 532.00 Td ('.$role.')', $workingBytes);
        $this->assertStringContainsString('346.00 625.70 Td (26000.00)', $workingBytes);
        $this->assertStringNotContainsString('346.00 625.70 Td (25.00)', $workingBytes);
        $this->assertStringNotContainsString('52000.00', $workingBytes);
        $this->assertStringNotContainsString('per hour', $workingBytes);
        $this->assertStringContainsString('92.00 664.80 Td (Acme Training Studio)', $workingBytes);
        $this->assertStringContainsString('300.00 652.00 Td (500 Training Plaza, Austin, TX 78701)', $workingBytes);
        $this->assertStringContainsString('114.00 628.80 Td (Priya Shah)', $workingBytes);
        $this->assertStringContainsString('320.00 97.80 Td (Priya Shah, HR Manager)', $workingBytes);
        $this->assertStringContainsString('126.00 73.80 Td ('.now()->format('m-d-Y').')', $workingBytes);
        $this->assertStringContainsString('372.00 73.80 Td (Acme People LLC)', $workingBytes);
        $this->assertStringContainsString('320.00 451.80 Td (Priya Shah, HR Manager)', $workingBytes);
        $this->assertStringContainsString('126.00 433.80 Td ('.now()->format('m-d-Y').')', $workingBytes);
        $this->assertStringNotContainsString('100 Sample Street', $workingBytes);
        $this->assertSame($snapshotBytes, Storage::disk('local')->get($snapshot->file_path));

        DB::table('i983_training_plans')->where('case_id', $caseId)->update(['student_role' => '']);
        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/documents/working')->assertSessionHasErrors('student_role');
        DB::table('i983_training_plans')->where('case_id', $caseId)->update(['student_role' => $role, 'work_arrangement' => '']);
        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/documents/working')->assertSessionHasErrors('work_arrangement');
        DB::table('i983_employers')->where('case_id', $caseId)->update(['annual_compensation' => null, 'salary' => null]);
        DB::table('i983_training_plans')->where('case_id', $caseId)->update(['work_arrangement' => 'hybrid']);
        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/documents/working')->assertSessionHasErrors('salary');
        $this->assertSame(1, DB::table('i983_documents')->where('case_id', $caseId)->where('kind', 'hr_working')->count());
        $this->assertSame($workingBytes, Storage::disk('local')->get($working->file_path));

        $this->actingAs($other->hr)->post('/i983/'.$caseId.'/training', [
            'student_role' => 'Other company text',
        ])->assertNotFound();
        $this->assertSame($role, DB::table('i983_training_plans')->where('case_id', $caseId)->value('student_role'));
        $this->assertSame($offers, DB::table('usa_offers')->count());
        $this->assertSame($payroll, DB::table('payroll_runs')->count());
        Mail::assertNothingSent();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function student(array $overrides, bool $submit): array
    {
        return [
            'surname' => 'Quinn',
            'given_name' => 'Riley',
            'email' => 'riley.map@example.test',
            'school_recommending' => 'Example State University',
            'school_degree' => 'Example State University',
            'sevis_school_code' => 'DET214F00001000',
            'dso_name' => 'Avery Cole',
            'dso_email' => 'dso@ex.edu',
            'dso_phone' => '+1 (248) 555-0199',
            'sevis_id' => 'N0000000001',
            'opt_start' => '2026-04-06',
            'opt_end' => '2028-04-05',
            'major_cip' => 'Computer Science 11.0701',
            'degree_level' => 'Master',
            'degree_awarded_on' => '2026-05-01',
            'prior_degree' => 'no',
            'ead' => 'IOE0000000001',
            'goals' => 'Complete one reviewed feature.',
            'submit' => $submit ? 1 : 0,
            ...($submit ? I983StudentSignature::fields() : []),
            ...$overrides,
        ];
    }
}
