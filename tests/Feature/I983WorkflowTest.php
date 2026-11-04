<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CompanyFixture;
use Tests\TestCase;

class I983WorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_student_link_hides_employer_data_and_the_final_pdf_waits_for_a_complete_case(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions', 'i983-workflow');
        $created = $this->actingAs($company->hr)->post('/i983', [
            'candidate_name' => 'Ajay Oguri',
            'candidate_email' => 'ajay.workflow@example.test',
        ]);
        $created->assertRedirect();
        $link = (string) session('i983_link');
        $caseId = (int) DB::table('i983_cases')->where('tenant_id', $company->id)->value('id');

        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/employer', [
            'legal_name' => 'Veltrix Digital Solutions Inc',
            'street' => '37068 Grand River Ave',
            'city' => 'Novi',
            'state' => 'MI',
            'zip' => '48335',
            'ein' => '43-2316091',
            'employee_count' => 20,
            'naics' => '541511',
        ])->assertRedirect();

        $this->get($link)->assertOk()->assertDontSee('43-2316091')->assertDontSee('37068 Grand River Ave')->assertDontSee('541511');
        $this->assertSame('43-2316091', app(\App\Services\Hrms\I983Cases::class)->show($company->hr, $caseId)['employer']['ein']);
        $this->actingAs($company->hr)->get('/i983/'.$caseId)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('workflow.stage', 'student_submission')
            ->where('workflow.label', 'Student Submission')
            ->where('workflow.steps.0.key', 'create_case')
            ->where('workflow.steps.0.state', 'complete')
            ->where('workflow.steps.1.key', 'student_submission')
            ->where('workflow.steps.1.state', 'current')
            ->where('workflow.steps.2.key', 'employer_information')
            ->where('workflow.steps.2.state', 'pending')
            ->where('workflow.steps.5.key', 'final_pdf')
            ->where('workflow.canGenerateFinal', false)
            ->where('workflow.blockers', fn ($blockers): bool => collect($blockers)->contains('The student must submit Section 1 and certify Section 2.')
                && collect($blockers)->contains('Work arrangement is required before the final PDF.'))
            ->where('employer.ein', '43-2316091')
        );
        $this->actingAs($company->hr)->get('/i983')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('cases.0.stage', 'student_submission')
            ->where('cases.0.stageLabel', 'Student Submission')
        );
        $this->actingAs($company->lead)->post('/i983/'.$caseId.'/final', ['confirm' => '1'])->assertForbidden();
        $this->actingAs($company->employee)->post('/i983/'.$caseId.'/final', ['confirm' => '1'])->assertForbidden();
        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/final', ['confirm' => '1'])->assertSessionHasErrors('form');
        $this->assertNotSame('finalized', DB::table('i983_cases')->where('id', $caseId)->value('status'));
        $this->assertSame('Veltrix Digital Solutions Inc', DB::table('i983_employers')->where('case_id', $caseId)->value('legal_name'));
    }

    public function test_saved_employer_information_opens_the_training_plan_before_certification(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions', 'i983-next-step');
        $this->actingAs($company->hr)->post('/i983', [
            'candidate_name' => 'Naresh Ajay',
            'candidate_email' => 'naresh.step@example.test',
        ])->assertRedirect();
        $caseId = (int) DB::table('i983_cases')->where('tenant_id', $company->id)->value('id');
        DB::table('i983_cases')->where('id', $caseId)->update([
            'status' => 'student_submitted',
            'student_submitted_at' => now(),
        ]);
        DB::table('i983_student_certifications')->insert([
            'tenant_id' => $company->id,
            'case_id' => $caseId,
            'link_id' => (int) DB::table('i983_links')->where('case_id', $caseId)->value('id'),
            'version' => 1,
            'printed_name' => 'Naresh, Ajay',
            'student_email' => 'naresh.step@example.test',
            'acknowledged' => true,
            'statements_sha256' => str_repeat('a', 64),
            'certified_on' => '2026-11-04',
            'certified_at' => now(),
            'timezone' => 'Asia/Kolkata',
            'signature_path' => 'i983/test/student.png',
            'signature_sha256' => str_repeat('b', 64),
            'upload_sha256' => str_repeat('c', 64),
            'created_at' => now(),
        ]);
        $this->actingAs($company->hr)->post('/i983/signature', [
            'signatory_name' => 'Priya Shah',
            'signatory_title' => 'HR Manager',
            'signature' => UploadedFile::fake()->image('signature.jpg', 240, 60),
        ])->assertRedirect();

        $saved = $this->actingAs($company->hr)->post('/i983/'.$caseId.'/employer', [
            'legal_name' => 'Veltrix Digital Solutions Inc',
            'street' => '37085 Grand River Ave, Suite 1105, MI 48335',
            'ein' => '43-2316091',
            'employee_count' => 20,
            'naics' => '541511',
        ]);
        $saved->assertRedirect()->assertSessionHas('status', fn (string $status) => str_contains($status, 'City'));
        $this->actingAs($company->hr)->get('/i983/'.$caseId)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('workflow.stage', 'employer_information')
            ->where('workflow.steps.3.state', 'pending')
            ->where('workflow.steps.4.state', 'pending')
        );

        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/employer', [
            'legal_name' => 'Veltrix Digital Solutions Inc',
            'street' => '37085 Grand River Ave',
            'suite' => '1105',
            'city' => 'Farmington',
            'state' => 'MI',
            'zip' => '48335',
            'ein' => '43-2316091',
            'employee_count' => 20,
            'naics' => '541511',
        ])->assertRedirect()->assertSessionHas('status', 'Employer information saved. Continue with the Training Plan.');

        $this->actingAs($company->hr)->get('/i983/'.$caseId)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('workflow.stage', 'training_plan')
            ->where('workflow.steps.2.state', 'complete')
            ->where('workflow.steps.3.key', 'training_plan')
            ->where('workflow.steps.3.state', 'current')
            ->where('workflow.steps.4.key', 'employer_certification')
            ->where('workflow.steps.4.state', 'pending')
            ->where('workflow.canGenerateFinal', false)
            ->where('workflow.blockers', fn ($blockers): bool => collect($blockers)->contains('OPT hours per week is required before the final PDF.')
                && collect($blockers)->contains('Compensation is required before the final PDF.')
                && collect($blockers)->contains('Employment start date is required before the final PDF.'))
        );
        $this->assertNull(DB::table('i983_employers')->where('case_id', $caseId)->value('opt_hours'));
        $this->assertNull(DB::table('i983_employers')->where('case_id', $caseId)->value('start_date'));
    }
}
