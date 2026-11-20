<?php

namespace Tests\Feature;

use App\Services\Hrms\I983Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CompanyFixture;
use Tests\Support\I983StudentSignature;
use Tests\TestCase;

class I983EvaluationPdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_five_uses_the_measured_final_evaluation_lines(): void
    {
        $directory = storage_path('framework/testing');
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        $student = $directory.'/eval-student-line.png';
        $employer = $directory.'/eval-employer-line.png';
        $this->ink($student, 20, 40, 80);
        $this->ink($employer, 80, 20, 20);
        $source = (string) file_get_contents(storage_path('app/private/i983/official-i983.pdf'));
        $bytes = app(I983Pdf::class)->overlayFinalEvaluation($source, [
            'date_from' => '06-01-2026',
            'date_to' => '04-05-2028',
            'narrative' => 'I completed the assigned projects.',
            'student_name' => 'Patel, Mina',
            'student_date' => '11-20-2026',
            'student_image' => $student,
            'employer_name' => 'Jordan Hale, Director',
            'employer_date' => '11-20-2026',
            'employer_image' => $employer,
        ]);
        $this->assertSame($source, (string) file_get_contents(storage_path('app/private/i983/official-i983.pdf')));
        $this->assertSame(5, preg_match_all('/\/Type\s*\/Page(?!s)/', $bytes));
        $this->assertStringContainsString('230.00 337.80 Td (06-01-2026)', $bytes);
        $this->assertStringContainsString('374.00 337.80 Td (04-05-2028)', $bytes);
        $this->assertStringContainsString('46.00 326.00 Td (I completed the assigned projects.)', $bytes);
        $this->assertStringContainsString('142.00 115.80 Td (Patel, Mina)', $bytes);
        $this->assertStringContainsString('496.00 115.80 Td (11-20-2026)', $bytes);
        $this->assertStringContainsString('260.00 84.00 Td (Jordan Hale, Director)', $bytes);
        $this->assertStringContainsString('495.00 84.00 Td (11-20-2026)', $bytes);
        file_put_contents($directory.'/i983-page5-preview.pdf', $bytes);
    }

    public function test_a_completed_evaluation_is_included_only_in_a_new_final_pdf(): void
    {
        Mail::fake();
        [$company, $caseId, $link] = $this->preparedCase('i983-eval-new-final', 'new.final@example.test');
        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/evaluation', [
            'date_from' => '2026-06-01',
            'date_to' => '2028-04-05',
        ])->assertRedirect();
        $this->post($link.'/evaluation', [
            'narrative' => 'I completed the assigned projects.',
            'signature' => I983StudentSignature::fields()['signature'],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/evaluation/signature')->assertRedirect()->assertSessionHasNoErrors();
        $student = DB::table('i983_documents')->where('case_id', $caseId)->where('kind', 'student_snapshot')->first();
        $studentBytes = Storage::disk('local')->get($student->file_path);
        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/final', ['confirm' => '1'])->assertRedirect();
        $signed = DB::table('i983_documents')->where('case_id', $caseId)->where('kind', 'employer_signed')->first();
        $final = DB::table('i983_documents')->where('case_id', $caseId)->where('kind', 'final_signed')->first();
        $finalBytes = Storage::disk('local')->get($final->file_path);
        $this->assertStringContainsString('(I completed the assigned projects.)', $finalBytes);
        $this->assertStringNotContainsString('(I completed the assigned projects.)', Storage::disk('local')->get($signed->file_path));
        $this->assertSame($studentBytes, Storage::disk('local')->get($student->file_path));
        $this->assertNotSame(hash('sha256', (string) Storage::disk('local')->get($signed->file_path)), $final->sha256);
        $this->assertSame((int) $final->id, (int) DB::table('i983_evaluations')->where('case_id', $caseId)->value('document_id'));
        $this->assertSame(0, DB::table('i983_documents')->where('case_id', $caseId)->where('kind', 'evaluation_final')->count());
        $this->actingAs($company->hr)->get('/i983/'.$caseId)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('documents', fn (mixed $documents): bool => collect($documents)->pluck('kind')->all() === ['student_snapshot', 'hr_working', 'final_signed'])
        );
        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/final', ['confirm' => '1'])->assertSessionHasErrors('form');
        $this->assertSame($finalBytes, Storage::disk('local')->get($final->file_path));
    }

    public function test_an_existing_final_pdf_is_kept_and_the_evaluation_is_stored_separately(): void
    {
        Mail::fake();
        [$company, $caseId, $link] = $this->preparedCase('i983-eval-separate', 'separate.final@example.test');
        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/final', ['confirm' => '1'])->assertRedirect();
        $final = DB::table('i983_documents')->where('case_id', $caseId)->where('kind', 'final_signed')->first();
        $student = DB::table('i983_documents')->where('case_id', $caseId)->where('kind', 'student_snapshot')->first();
        $finalBytes = Storage::disk('local')->get($final->file_path);
        $studentBytes = Storage::disk('local')->get($student->file_path);
        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/evaluation', [
            'date_from' => '2026-06-01',
            'date_to' => '2028-04-05',
        ])->assertRedirect();
        $this->post($link.'/evaluation', [
            'narrative' => 'I completed the assigned projects.',
            'signature' => I983StudentSignature::fields()['signature'],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/evaluation/signature')->assertRedirect()->assertSessionHasNoErrors();
        $preview = $this->actingAs($company->hr)->get('/i983/'.$caseId.'/evaluation/preview');
        $preview->assertOk();
        $this->assertStringContainsString('(I completed the assigned projects.)', $preview->getContent());
        $this->assertSame($finalBytes, Storage::disk('local')->get($final->file_path));
        $this->assertSame($final->sha256, hash('sha256', $finalBytes));
        $this->actingAs($company->lead)->get('/i983/'.$caseId.'/evaluation/preview')->assertForbidden();
        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/evaluation/document')->assertRedirect()->assertSessionHasNoErrors();
        $evaluation = DB::table('i983_documents')->where('case_id', $caseId)->where('kind', 'evaluation_final')->first();
        $this->assertSame((int) $final->id, (int) $evaluation->source_document_id);
        $this->assertSame((int) $evaluation->id, (int) DB::table('i983_evaluations')->where('case_id', $caseId)->value('document_id'));
        $this->assertSame($finalBytes, Storage::disk('local')->get($final->file_path));
        $this->assertSame($studentBytes, Storage::disk('local')->get($student->file_path));
        $this->assertSame($evaluation->sha256, hash('sha256', (string) Storage::disk('local')->get($evaluation->file_path)));
        $this->actingAs($company->hr)->get('/i983/'.$caseId)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('documents', fn (mixed $documents): bool => collect($documents)->pluck('kind')->all() === ['student_snapshot', 'hr_working', 'final_signed'])
        );
        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/evaluation/document')->assertSessionHasErrors('form');
        $this->assertSame(1, DB::table('i983_documents')->where('case_id', $caseId)->where('kind', 'evaluation_final')->count());
        $this->assertSame($finalBytes, Storage::disk('local')->get($final->file_path));
    }

    public function test_the_student_can_open_only_the_completed_evaluation_pdf(): void
    {
        Mail::fake();
        [$company, $caseId, $link] = $this->preparedCase('i983-eval-student-pdf', 'student.evaluation@example.test');
        $this->get($link.'/evaluation')->assertNotFound();
        $this->get($link.'/evaluation/download')->assertNotFound();
        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/final', ['confirm' => '1'])->assertRedirect();
        $final = DB::table('i983_documents')->where('case_id', $caseId)->where('kind', 'final_signed')->first();
        $student = DB::table('i983_documents')->where('case_id', $caseId)->where('kind', 'student_snapshot')->first();
        $working = DB::table('i983_documents')->where('case_id', $caseId)->where('kind', 'hr_working')->first();
        $finalBytes = Storage::disk('local')->get($final->file_path);
        $studentBytes = Storage::disk('local')->get($student->file_path);
        $finalEvents = DB::table('i983_events')->where('case_id', $caseId)->where('subject', $final->sha256)->orderBy('id')->pluck('action', 'id')->all();
        $studentEvents = DB::table('i983_events')->where('case_id', $caseId)->where('subject', $student->sha256)->orderBy('id')->pluck('action', 'id')->all();

        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/evaluation', [
            'date_from' => '2026-06-01',
            'date_to' => '2028-04-05',
        ])->assertRedirect();
        $this->post($link.'/evaluation', [
            'narrative' => 'I completed the assigned projects.',
            'signature' => I983StudentSignature::fields()['signature'],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->get($link.'/evaluation')->assertNotFound();
        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/evaluation/signature')->assertRedirect()->assertSessionHasNoErrors();
        $this->get($link.'/evaluation/download')->assertNotFound();
        $this->get($link)->assertOk()->assertInertia(fn (Assert $page) => $page->where('form.evaluation.evaluationPdf', false));
        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/evaluation/document')->assertRedirect()->assertSessionHasNoErrors();
        $evaluation = DB::table('i983_documents')->where('case_id', $caseId)->where('kind', 'evaluation_final')->first();
        $this->get($link)->assertOk()->assertInertia(fn (Assert $page) => $page->where('form.evaluation.evaluationPdf', true));

        $events = DB::table('i983_events')->where('case_id', $caseId)->orderBy('id')->pluck('subject', 'id')->all();
        $preview = $this->get($link.'/evaluation');
        $preview->assertOk();
        $preview->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('(I completed the assigned projects.)', $preview->getContent());
        $download = $this->get($link.'/evaluation/download');
        $download->assertOk();
        $this->assertStringContainsString('attachment', (string) $download->headers->get('content-disposition'));
        $this->assertStringContainsString('Final_Evaluation', (string) $download->headers->get('content-disposition'));
        $this->assertSame(Storage::disk('local')->get($evaluation->file_path), $download->getContent());
        $this->assertNotSame($studentBytes, $download->getContent());
        $this->assertNotSame($finalBytes, $download->getContent());
        $this->assertNotSame(Storage::disk('local')->get($working->file_path), $download->getContent());
        $this->assertSame($events, DB::table('i983_events')->where('case_id', $caseId)->orderBy('id')->pluck('subject', 'id')->all());
        $this->assertSame($finalBytes, Storage::disk('local')->get($final->file_path));
        $this->assertSame($studentBytes, Storage::disk('local')->get($student->file_path));
        $this->assertSame($final->sha256, hash('sha256', (string) Storage::disk('local')->get($final->file_path)));
        $this->assertSame($student->sha256, hash('sha256', (string) Storage::disk('local')->get($student->file_path)));
        $this->assertSame($evaluation->sha256, hash('sha256', (string) Storage::disk('local')->get($evaluation->file_path)));
        $this->assertSame($finalEvents, DB::table('i983_events')->where('case_id', $caseId)->where('subject', $final->sha256)->orderBy('id')->pluck('action', 'id')->all());
        $this->assertSame($studentEvents, DB::table('i983_events')->where('case_id', $caseId)->where('subject', $student->sha256)->orderBy('id')->pluck('action', 'id')->all());

        $sealed = $this->get($link.'/final/download');
        $sealed->assertOk();
        $this->assertSame($finalBytes, $sealed->getContent());
        auth()->logout();
        $this->get('/i983/s/'.str_repeat('ab', 32).'/evaluation')->assertNotFound();
        $this->get('/i983/s/'.str_repeat('ab', 32).'/evaluation/download')->assertNotFound();
        $this->get($link.'/documents/'.$student->id)->assertNotFound();
        $this->get($link.'/documents/'.$working->id)->assertNotFound();
        $this->get($link.'/documents/'.$final->id)->assertNotFound();
        $this->get('/i983/'.$caseId.'/documents/'.$student->id.'/preview')->assertRedirect();
        $this->get('/i983/'.$caseId.'/documents/'.$working->id.'/download')->assertRedirect();
        $this->actingAs($company->employee)->get('/i983/'.$caseId.'/documents/'.$evaluation->id.'/preview')->assertForbidden();
        $this->actingAs($company->employee)->get('/i983/'.$caseId.'/evaluation')->assertForbidden();

        $other = CompanyFixture::make('Other People', 'i983-eval-pdf-outsider');
        $this->actingAs($other->hr)->post('/i983', [
            'candidate_name' => 'Other Student',
            'candidate_email' => 'other.evaluation@example.test',
        ])->assertRedirect();
        $otherLink = (string) session('i983_link');
        $this->get($otherLink.'/evaluation')->assertNotFound();
        $this->get($otherLink.'/evaluation/download')->assertNotFound();
        $this->assertStringStartsWith('%PDF', (string) $finalBytes);
        $this->get($otherLink.'/evaluation/download')->assertNotFound();

        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/evaluation/document')->assertSessionHasErrors('form');
        $this->assertSame(1, DB::table('i983_documents')->where('case_id', $caseId)->where('kind', 'evaluation_final')->count());
        $this->assertSame($evaluation->sha256, (string) DB::table('i983_documents')->where('id', $evaluation->id)->value('sha256'));
        $this->assertSame($finalBytes, Storage::disk('local')->get($final->file_path));
        $this->assertSame($studentBytes, Storage::disk('local')->get($student->file_path));
    }

    /**
     * @return array{0: CompanyFixture, 1: int, 2: string}
     */
    private function preparedCase(string $slug, string $email): array
    {
        $company = CompanyFixture::make('Acme People', $slug);
        $this->actingAs($company->hr)->post('/i983', [
            'candidate_name' => 'Riley Quinn',
            'candidate_email' => $email,
        ])->assertRedirect();
        $link = (string) session('i983_link');
        $caseId = (int) DB::table('i983_cases')->where('tenant_id', $company->id)->value('id');
        $this->post($link, [
            'surname' => 'Quinn',
            'given_name' => 'Riley',
            'middle_name' => '',
            'email' => $email,
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
            'student_role' => 'Data analyst trainee',
            'goals' => 'Practice one reviewed workflow.',
            'submit' => 1,
            ...I983StudentSignature::fields(),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/employer', [
            'legal_name' => 'Acme People LLC',
            'street' => '1 Main Street',
            'suite' => '200',
            'website' => 'https://acme.test',
            'city' => 'Farmington',
            'state' => 'MI',
            'zip' => '48335',
            'ein' => '98-7654321',
            'employee_count' => 12,
            'naics' => '541511',
            'opt_hours' => 20,
            'salary' => '72000',
            'salary_frequency' => 'year',
            'other_compensation' => 'None',
            'start_date' => '2026-04-06',
        ])->assertRedirect();
        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/training', [
            'student_name' => 'Quinn, Riley',
            'employer_name' => 'Acme People LLC',
            'site_name' => 'Acme Remote',
            'site_address' => '500 Training Plaza, Austin, TX 78701',
            'work_arrangement' => 'remote',
            'official_name' => 'Priya Shah',
            'official_title' => 'HR Manager',
            'official_email' => 'priya@acme.test',
            'official_phone' => '248-555-0100',
            'student_role' => 'Data analyst trainee',
            'goals' => 'Practice one reviewed workflow.',
            'oversight' => 'Weekly check-in with the hiring manager.',
            'measures' => 'Reviewed project notes each month.',
            'remarks' => 'Sample remarks for the training plan.',
        ])->assertRedirect();
        $this->actingAs($company->hr)->post('/i983/signature', [
            'signatory_name' => 'Jordan Hale',
            'signatory_title' => 'Director',
            'signature' => UploadedFile::fake()->image('signature.jpg', 240, 60),
        ])->assertRedirect();
        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/documents/working')->assertRedirect();

        return [$company, $caseId, $link];
    }

    private function ink(string $path, int $red, int $green, int $blue): void
    {
        $image = imagecreatetruecolor(680, 48);
        $color = imagecolorallocate($image, $red, $green, $blue);
        imagefilledrectangle($image, 30, 16, 420, 32, $color);
        imagepng($image, $path);
        imagedestroy($image);
    }
}
