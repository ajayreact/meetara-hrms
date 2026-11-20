<?php

namespace Tests\Feature;

use App\Services\Hrms\I983Evaluations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CompanyFixture;
use Tests\TestCase;

class I983EvaluationDraftTest extends TestCase
{
    use RefreshDatabase;

    public function test_hr_saves_only_explicit_dates_and_cannot_write_the_narrative(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions', 'i983-eval-draft');
        $other = CompanyFixture::make('Other Employer', 'i983-eval-other');
        $this->actingAs($company->hr)->post('/i983', [
            'candidate_name' => 'Mina Patel',
            'candidate_email' => 'mina.draft@example.test',
        ])->assertRedirect();
        $caseId = (int) DB::table('i983_cases')->where('tenant_id', $company->id)->value('id');
        $this->actingAs($company->hr)->get('/i983/'.$caseId.'/evaluation')->assertNotFound();
        DB::table('i983_cases')->where('id', $caseId)->update([
            'status' => 'student_submitted',
            'student_submitted_at' => now(),
        ]);
        DB::table('i983_students')->where('case_id', $caseId)->update([
            'surname' => 'Patel',
            'given_name' => 'Mina',
            'opt_start' => '2026-01-15',
            'opt_end' => '2028-01-14',
        ]);
        DB::table('i983_employers')->where('case_id', $caseId)->update([
            'legal_name' => 'Veltrix Digital Solutions Inc',
            'start_date' => '2026-02-01',
        ]);
        DB::table('i983_training_plans')->where('case_id', $caseId)->update([
            'official_name' => 'Jordan Hale',
            'official_title' => 'Engineering Manager',
        ]);

        $this->actingAs($company->hr)->get('/i983/'.$caseId.'/evaluation')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('studentName', 'Patel, Mina')
            ->where('employerName', 'Veltrix Digital Solutions Inc')
            ->where('supervisorName', 'Jordan Hale')
            ->where('supervisorTitle', 'Engineering Manager')
            ->where('optStart', '2026-01-15')
            ->where('optEnd', '2028-01-14')
            ->where('employmentStart', '2026-02-01')
            ->where('suggestedFrom', '')
            ->where('suggestedTo', '2028-01-14')
            ->where('dateFrom', '')
            ->where('dateTo', '')
            ->where('narrative', '')
            ->where('evaluationStatus', 'pending')
        );
        $this->assertSame(0, DB::table('i983_evaluations')->where('case_id', $caseId)->count());
        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/evaluation', [
            'date_from' => '',
            'date_to' => '',
        ])->assertSessionHasErrors('date_from');
        $this->assertSame(0, DB::table('i983_evaluations')->where('case_id', $caseId)->count());
        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/evaluation', [
            'date_from' => '2026-02-01',
            'date_to' => '2028-01-14',
            'narrative' => 'The student improved quickly.',
        ])->assertSessionHasErrors('narrative');
        $this->assertNull(DB::table('i983_evaluations')->where('case_id', $caseId)->value('narrative'));
        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/evaluation', [
            'date_from' => '2026-01-01',
            'date_to' => '2028-01-14',
        ])->assertSessionHasErrors('date_from');
        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/evaluation', [
            'date_from' => '2028-01-14',
            'date_to' => '2026-02-01',
        ])->assertSessionHasErrors('date_to');
        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/evaluation', [
            'date_from' => '2026-06-01',
            'date_to' => '2028-01-14',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $saved = DB::table('i983_evaluations')->where('case_id', $caseId)->where('kind', 'final')->first();
        $this->assertNotNull($saved);
        $this->assertSame('2026-06-01', substr((string) $saved->date_from, 0, 10));
        $this->assertSame('2028-01-14', substr((string) $saved->date_to, 0, 10));
        $this->assertSame('in_progress', $saved->status);
        $this->assertNull($saved->narrative);
        $this->actingAs($other->hr)->get('/i983/'.$caseId.'/evaluation')->assertNotFound();
        $this->actingAs($company->lead)->get('/i983/'.$caseId.'/evaluation')->assertForbidden();
        $this->actingAs($company->employee)->post('/i983/'.$caseId.'/evaluation', [
            'date_from' => '2026-06-01',
            'date_to' => '2028-01-14',
        ])->assertForbidden();
        $this->actingAs($company->hr)->get('/i983')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('cases.0.canReviewEvaluation', true)
        );
    }

    public function test_dates_must_be_complete_and_inside_the_stored_stem_opt_period(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions', 'i983-eval-bounds');
        $this->actingAs($company->hr)->post('/i983', [
            'candidate_name' => 'Mina Patel',
            'candidate_email' => 'mina.bounds@example.test',
        ])->assertRedirect();
        $caseId = (int) DB::table('i983_cases')->where('tenant_id', $company->id)->value('id');
        DB::table('i983_cases')->where('id', $caseId)->update([
            'status' => 'student_submitted',
            'student_submitted_at' => now(),
        ]);
        DB::table('i983_students')->where('case_id', $caseId)->update([
            'surname' => 'Patel',
            'given_name' => 'Mina',
            'opt_start' => null,
            'opt_end' => null,
        ]);

        $this->actingAs($company->hr)->get('/i983/'.$caseId.'/evaluation')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('evaluationStatus', 'pending')
            ->where('dateFrom', '')
            ->where('dateTo', '')
            ->where('suggestedFrom', '')
            ->where('suggestedTo', '')
        );
        $this->assertSame(0, DB::table('i983_evaluations')->where('case_id', $caseId)->count());

        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/evaluation', [
            'date_from' => '2026-06-01',
            'date_to' => '2028-01-14',
        ])->assertSessionHasErrors('date_from');
        DB::table('i983_students')->where('case_id', $caseId)->update(['opt_start' => '2026-01-15']);
        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/evaluation', [
            'date_from' => '2026-06-01',
            'date_to' => '2028-01-14',
        ])->assertSessionHasErrors('date_from');
        DB::table('i983_students')->where('case_id', $caseId)->update(['opt_start' => null, 'opt_end' => '2028-01-14']);
        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/evaluation', [
            'date_from' => '2026-06-01',
            'date_to' => '2028-01-14',
        ])->assertSessionHasErrors('date_from');
        DB::table('i983_students')->where('case_id', $caseId)->update([
            'opt_start' => '2026-01-15',
            'opt_end' => '2028-01-14',
        ]);
        foreach ([
            ['2026-06-01', ''],
            ['', '2028-01-14'],
            ['2026-01-01', '2028-01-14'],
            ['2028-02-01', '2028-03-01'],
            ['2026-06-01', '2028-02-01'],
            ['2026-01-01', '2026-01-10'],
            ['2028-01-14', '2026-06-01'],
        ] as [$from, $to]) {
            $this->actingAs($company->hr)->post('/i983/'.$caseId.'/evaluation', [
                'date_from' => $from,
                'date_to' => $to,
            ])->assertSessionHasErrors();
        }
        $this->assertSame(0, DB::table('i983_evaluations')->where('case_id', $caseId)->count());
        $this->assertSame(0, DB::table('i983_events')->where('case_id', $caseId)->where('action', 'evaluation_draft_saved')->count());

        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/evaluation', [
            'date_from' => '2026-01-15',
            'date_to' => '2028-01-14',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $saved = DB::table('i983_evaluations')->where('case_id', $caseId)->where('kind', 'final')->first();
        $this->assertSame('2026-01-15', substr((string) $saved->date_from, 0, 10));
        $this->assertSame('2028-01-14', substr((string) $saved->date_to, 0, 10));
        $this->assertNull($saved->narrative);

        $method = new \ReflectionMethod(I983Evaluations::class, 'insertFinal');
        $case = DB::table('i983_cases')->where('id', $caseId)->first();

        try {
            $method->invoke(app(I983Evaluations::class), $case, [
                'date_from' => '2026-07-01',
                'date_to' => '2026-08-01',
                'narrative' => 'A later request must not replace the saved evaluation.',
                'status' => 'in_progress',
                'created_by' => $company->hr->id,
                'created_at' => now(),
                'updated_at' => now(),
            ], 'date_from');
            $this->fail('A duplicate evaluation insert should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame('This evaluation was already saved. The existing record was not changed.', $exception->errors()['date_from'][0]);
        }

        $kept = DB::table('i983_evaluations')->where('case_id', $caseId)->where('kind', 'final')->first();
        $this->assertSame(1, DB::table('i983_evaluations')->where('case_id', $caseId)->count());
        $this->assertSame(substr((string) $saved->date_from, 0, 10), substr((string) $kept->date_from, 0, 10));
        $this->assertSame(substr((string) $saved->date_to, 0, 10), substr((string) $kept->date_to, 0, 10));
        $this->assertNull($kept->narrative);
    }
}
