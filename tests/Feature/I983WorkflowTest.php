<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
