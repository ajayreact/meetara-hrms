<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CompanyFixture;
use Tests\Support\I983StudentSignature;
use Tests\TestCase;

class I983EvaluationStudentTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_student_writes_and_signs_the_final_evaluation_without_changing_stored_pdfs(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions', 'i983-eval-student');
        $this->actingAs($company->hr)->post('/i983', [
            'candidate_name' => 'Mina Patel',
            'candidate_email' => 'mina.sign@example.test',
        ])->assertRedirect();
        $link = (string) session('i983_link');
        $caseId = (int) DB::table('i983_cases')->where('tenant_id', $company->id)->value('id');
        DB::table('i983_cases')->where('id', $caseId)->update([
            'status' => 'finalized',
            'student_submitted_at' => now(),
        ]);
        DB::table('i983_students')->where('case_id', $caseId)->update([
            'surname' => 'Patel',
            'given_name' => 'Mina',
            'opt_start' => '2026-01-15',
            'opt_end' => '2028-01-14',
        ]);
        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/evaluation', [
            'date_from' => '2026-06-01',
            'date_to' => '2028-01-14',
        ])->assertRedirect();

        $this->get($link)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('form.evaluation.available', true)
            ->where('form.evaluation.studentName', 'Patel, Mina')
            ->where('form.evaluation.datesReady', true)
            ->where('form.status', 'finalized')
        );
        $this->post($link.'/evaluation', [
            'narrative' => '   ',
        ])->assertSessionHasErrors('narrative');
        $this->post($link.'/evaluation', [
            'narrative' => str_repeat('A', 400),
            'printed_name' => 'Someone Else',
        ])->assertSessionHasErrors('narrative');
        $this->assertNull(DB::table('i983_evaluations')->where('case_id', $caseId)->value('narrative'));

        $this->post($link.'/evaluation', [
            'narrative' => 'I completed the assigned software projects and documented the results myself.',
            'printed_name' => 'Someone Else',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('I completed the assigned software projects and documented the results myself.', DB::table('i983_evaluations')->where('case_id', $caseId)->value('narrative'));
        $this->assertNull(DB::table('i983_evaluations')->where('case_id', $caseId)->value('student_printed_name'));

        $employer = imagecreatetruecolor(680, 48);
        $ink = imagecolorallocate($employer, 10, 10, 10);
        imagefilledrectangle($employer, 20, 16, 400, 32, $ink);
        $employerPath = storage_path('framework/testing/employer-eval-'.bin2hex(random_bytes(4)).'.png');
        imagepng($employer, $employerPath);
        imagedestroy($employer);
        Storage::disk('local')->put('i983/'.$company->id.'/signatory.png', (string) file_get_contents($employerPath));
        DB::table('i983_signatories')->insert([
            'tenant_id' => $company->id,
            'signatory_name' => 'Jordan Hale',
            'signatory_title' => 'Director',
            'image_path' => 'i983/'.$company->id.'/signatory.png',
            'image_mime' => 'image/png',
            'image_width' => 680,
            'image_height' => 48,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->post($link.'/evaluation', [
            'narrative' => 'I completed the assigned software projects and documented the results myself.',
            'signature' => new UploadedFile($employerPath, 'employer.png', 'image/png', null, true),
        ])->assertSessionHasErrors('signature');
        $this->assertNull(DB::table('i983_evaluations')->where('case_id', $caseId)->value('student_signature_path'));

        $signature = I983StudentSignature::fields()['signature'];
        $this->post($link.'/evaluation', [
            'narrative' => 'I completed the assigned software projects and documented the results myself.',
            'printed_name' => 'Someone Else',
            'signature' => $signature,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $saved = DB::table('i983_evaluations')->where('case_id', $caseId)->where('kind', 'final')->first();
        $this->assertSame('Patel, Mina', $saved->student_printed_name);
        $this->assertNotSame('Someone Else', $saved->student_printed_name);
        $this->assertSame('ready_for_signature', $saved->status);
        $this->assertNotNull($saved->student_signed_on);
        $this->assertTrue(Storage::disk('local')->exists((string) $saved->student_signature_path));
        $this->assertSame(0, DB::table('i983_documents')->where('case_id', $caseId)->count());
        $this->post($link, ['surname' => 'Changed'])->assertSessionHasErrors('form');
        $this->post($link.'/evaluation', [
            'narrative' => 'A different narrative after signing.',
            'signature' => I983StudentSignature::fields()['signature'],
        ])->assertSessionHasErrors('narrative');
        $this->assertSame('I completed the assigned software projects and documented the results myself.', DB::table('i983_evaluations')->where('case_id', $caseId)->value('narrative'));
        DB::table('i983_links')->where('case_id', $caseId)->update(['expires_at' => now()->subDay()]);
        $this->post($link.'/evaluation', [
            'narrative' => 'I completed the assigned software projects and documented the results myself.',
        ])->assertNotFound();
    }
}
