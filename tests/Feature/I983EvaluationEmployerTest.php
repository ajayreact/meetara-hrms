<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CompanyFixture;
use Tests\Support\I983StudentSignature;
use Tests\TestCase;

class I983EvaluationEmployerTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_employer_signature_is_copied_and_later_signatory_changes_do_not_rewrite_it(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions', 'i983-eval-employer');
        $this->actingAs($company->hr)->post('/i983', [
            'candidate_name' => 'Mina Patel',
            'candidate_email' => 'mina.employer@example.test',
        ])->assertRedirect();
        $link = (string) session('i983_link');
        $caseId = (int) DB::table('i983_cases')->where('tenant_id', $company->id)->value('id');
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
        $image = imagecreatetruecolor(680, 48);
        $ink = imagecolorallocate($image, 20, 30, 40);
        imagefilledrectangle($image, 24, 14, 360, 30, $ink);
        $source = storage_path('framework/testing/signatory-copy-'.bin2hex(random_bytes(4)).'.png');
        imagepng($image, $source);
        imagedestroy($image);
        Storage::disk('local')->put('i983/'.$company->id.'/signatory.png', (string) file_get_contents($source));
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

        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/evaluation/signature')->assertSessionHasErrors('signature');
        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/evaluation', [
            'date_from' => '2026-06-01',
            'date_to' => '2027-06-01',
        ])->assertRedirect();
        $this->post($link.'/evaluation', [
            'narrative' => 'I completed the assigned software projects and documented the results myself.',
            'signature' => I983StudentSignature::fields()['signature'],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($company->lead)->post('/i983/'.$caseId.'/evaluation/signature')->assertForbidden();
        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/evaluation/signature')->assertRedirect()->assertSessionHasNoErrors();
        $saved = DB::table('i983_evaluations')->where('case_id', $caseId)->first();
        $copied = Storage::disk('local')->get((string) $saved->employer_signature_path);
        $this->assertSame(hash('sha256', (string) file_get_contents($source)), hash('sha256', (string) $copied));
        $this->assertSame('Jordan Hale', $saved->employer_printed_name);
        $this->assertSame('Director', $saved->employer_title);
        $this->assertSame('ready_for_signature', $saved->status);
        $this->assertNull($saved->completed_at);
        $this->assertSame(0, DB::table('i983_documents')->where('case_id', $caseId)->count());

        $replacement = imagecreatetruecolor(680, 48);
        $other = imagecolorallocate($replacement, 200, 10, 10);
        imagefilledrectangle($replacement, 10, 10, 200, 36, $other);
        ob_start();
        imagepng($replacement);
        $different = ob_get_clean();
        imagedestroy($replacement);
        Storage::disk('local')->put('i983/'.$company->id.'/signatory.png', (string) $different);
        DB::table('i983_signatories')->where('tenant_id', $company->id)->update([
            'signatory_name' => 'New Person',
            'signatory_title' => 'New Title',
        ]);
        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/evaluation/signature')->assertSessionHasErrors('signature');
        $again = DB::table('i983_evaluations')->where('case_id', $caseId)->first();
        $this->assertSame('Jordan Hale', $again->employer_printed_name);
        $this->assertSame(hash('sha256', (string) file_get_contents($source)), hash('sha256', (string) Storage::disk('local')->get((string) $again->employer_signature_path)));
        $this->assertNotSame(hash('sha256', (string) $different), hash('sha256', (string) Storage::disk('local')->get((string) $again->employer_signature_path)));
    }
}
