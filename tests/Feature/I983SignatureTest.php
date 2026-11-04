<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CompanyFixture;
use Tests\Support\I983StudentSignature;
use Tests\TestCase;

class I983SignatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->isolatePrivateDisk();
    }

    protected function tearDown(): void
    {
        foreach (['i983_documents' => 'file_path', 'i983_signatories' => 'image_path', 'i983_certifications' => 'signature_path', 'i983_student_certifications' => 'signature_path'] as $table => $column) {
            if (DB::getSchemaBuilder()->hasTable($table)) {
                foreach (DB::table($table)->pluck($column) as $path) {
                    if (is_string($path) && $path !== '') {
                        Storage::disk('local')->delete($path);
                    }
                }
            }
        }

        parent::tearDown();
    }

    public function test_preview_download_signature_and_finalization_keep_earlier_pdfs_private(): void
    {
        Mail::fake();
        $official = storage_path('app/private/i983/official-i983.pdf');
        $officialHash = hash_file('sha256', $official);
        $company = CompanyFixture::make('Acme People', 'acme-i983-sign');
        $other = CompanyFixture::make('Other Company', 'other-i983-sign');
        $owner = $this->owner($company);
        $created = $this->actingAs($company->hr)->post('/i983', [
            'candidate_name' => 'Riley Quinn',
            'candidate_email' => 'riley.sign@example.test',
        ]);
        $link = (string) session('i983_link');
        $caseId = (int) DB::table('i983_cases')->where('tenant_id', $company->id)->value('id');

        $this->post($link, $this->student([
            'student_role' => 'Data analyst trainee',
            'goals' => 'Complete one reviewed feature and discuss the result with the hiring manager during the weekly review meeting. The second sentence names the next skill to practice.',
        ], true))->assertRedirect();
        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/employer', $this->employer())->assertRedirect();
        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/training', $this->training())->assertRedirect();
        $this->actingAs($company->hr)->post('/i983/signature', [
            'signatory_name' => 'Priya Shah',
            'signatory_title' => 'HR Manager',
            'signature' => UploadedFile::fake()->image('signature.jpg', 240, 60),
        ])->assertRedirect();
        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/documents/working')->assertRedirect();

        $snapshot = DB::table('i983_documents')->where('case_id', $caseId)->where('kind', 'student_snapshot')->first();
        $working = DB::table('i983_documents')->where('case_id', $caseId)->where('kind', 'hr_working')->first();
        $snapshotBytes = Storage::disk('local')->get($snapshot->file_path);
        $workingBytes = Storage::disk('local')->get($working->file_path);
        $studentDate = now()->timezone('Asia/Kolkata')->format('m-d-Y');
        $this->assertStringContainsString('144.00 173.90 Td (Quinn, Riley)', $snapshotBytes);
        $this->assertStringContainsString('491.00 173.90 Td ('.$studentDate.')', $snapshotBytes);
        $this->assertStringContainsString('144.00 173.90 Td (Quinn, Riley)', $workingBytes);
        $this->assertStringContainsString('320.00 97.80 Td (Priya Shah, HR Manager)', $workingBytes);
        $this->assertStringContainsString('320.00 451.80 Td (Priya Shah, HR Manager)', $workingBytes);
        $this->assertStringNotContainsString('320.00 97.80 Td', $snapshotBytes);
        $this->assertStringNotContainsString('320.00 451.80 Td', $snapshotBytes);
        $this->assertSame(5, preg_match_all('/\/Type\s*\/Page(?!s)/', $snapshotBytes));
        $this->assertSame(5, preg_match_all('/\/Type\s*\/Page(?!s)/', $workingBytes));
        $this->assertSame(0, DB::table('i983_certifications')->where('case_id', $caseId)->count());
        $studentCertification = DB::table('i983_student_certifications')->where('case_id', $caseId)->first();
        $this->assertNotNull($studentCertification);
        $this->assertSame('i983/'.$company->id.'/'.$caseId.'/student-signature-v1.png', $studentCertification->signature_path);
        $this->assertTrue(Storage::disk('local')->exists($studentCertification->signature_path));
        $this->assertFalse(is_file(public_path((string) $studentCertification->signature_path)));
        $this->assertSame(hash('sha256', (string) Storage::disk('local')->get($studentCertification->signature_path)), $studentCertification->signature_sha256);

        $this->actingAs($company->hr)->post('/i983/signature', [
            'signatory_name' => 'Priya Shah',
            'signatory_title' => 'HR Manager',
            'signature' => UploadedFile::fake()->image('tiny.png', 10, 10),
        ])->assertSessionHasErrors('signature');
        $this->actingAs($company->hr)->post('/i983/signature', [
            'signatory_name' => 'Priya Shah',
            'signatory_title' => 'HR Manager',
            'signature' => UploadedFile::fake()->image('signature.jpg', 360, 80),
        ])->assertRedirect();
        $this->assertSame(0, DB::table('i983_documents')->where('case_id', $caseId)->where('kind', 'employer_signed')->count());
        $image = $this->actingAs($company->hr)->get('/i983/signature')->assertOk();
        $this->assertStringContainsString('private', (string) $image->headers->get('cache-control'));
        $this->assertStringContainsString('no-store', (string) $image->headers->get('cache-control'));
        $this->actingAs($company->lead)->get('/i983/signature')->assertForbidden();
        $this->actingAs($other->hr)->get('/i983/signature')->assertNotFound();

        $preview = $this->actingAs($company->hr)->get('/i983/'.$caseId.'/documents/'.$snapshot->id.'/preview');
        $preview->assertOk();
        $preview->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('private', (string) $preview->headers->get('cache-control'));
        $this->assertStringContainsString('no-store', (string) $preview->headers->get('cache-control'));
        $this->assertStringContainsString('inline; filename="I983_Riley_Quinn_Student_Submitted_v1.pdf"', (string) $preview->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF', $preview->getContent());
        $download = $this->actingAs($company->hr)->get('/i983/'.$caseId.'/documents/'.$working->id.'/download');
        $download->assertOk();
        $this->assertStringContainsString('attachment; filename="I983_Riley_Quinn_HR_Working_v1.pdf"', (string) $download->headers->get('content-disposition'));
        $this->assertSame($workingBytes, $download->getContent());
        $this->actingAs($company->lead)->get('/i983/'.$caseId.'/documents/'.$working->id.'/preview')->assertForbidden();
        $this->actingAs($other->hr)->get('/i983/'.$caseId.'/documents/'.$working->id.'/download')->assertNotFound();
        $this->actingAs($company->hr)->get('/i983/'.$caseId.'/documents/999999/preview')->assertNotFound();

        $this->actingAs($company->lead)->post('/i983/'.$caseId.'/signature', ['confirm' => '1'])->assertForbidden();
        $this->actingAs($company->employee)->post('/i983/'.$caseId.'/finalize', ['confirm' => '1'])->assertForbidden();
        $this->actingAs($other->hr)->post('/i983/'.$caseId.'/signature', ['confirm' => '1'])->assertNotFound();
        $this->actingAs($owner)->post('/i983/'.$caseId.'/signature', [])->assertSessionHasErrors('confirm');
        $this->assertSame(0, DB::table('i983_documents')->where('case_id', $caseId)->where('kind', 'employer_signed')->count());

        $this->actingAs($owner)->post('/i983/'.$caseId.'/signature', ['confirm' => '1'])->assertRedirect();
        $this->actingAs($owner)->post('/i983/'.$caseId.'/signature', ['confirm' => '1'])->assertSessionHasErrors('form');
        $signed = DB::table('i983_documents')->where('case_id', $caseId)->where('kind', 'employer_signed')->first();
        $signedBytes = Storage::disk('local')->get($signed->file_path);
        $this->assertSame($workingBytes, Storage::disk('local')->get($working->file_path));
        $this->assertSame($snapshotBytes, Storage::disk('local')->get($snapshot->file_path));
        $this->assertNotSame($working->sha256, $signed->sha256);
        $this->assertSame(hash('sha256', $signedBytes), $signed->sha256);
        $this->assertSame((int) $working->id, (int) $signed->source_document_id);
        $this->assertSame(5, preg_match_all('/\/Type\s*\/Page(?!s)/', $signedBytes));
        $this->assertStringContainsString('144.00 173.90 Td (Quinn, Riley)', $signedBytes);
        $this->assertStringContainsString('491.00 173.90 Td ('.$studentDate.')', $signedBytes);
        $this->assertStringContainsString('320.00 97.80 Td (Priya Shah, HR Manager)', $signedBytes);
        $this->assertStringContainsString('126.00 73.80 Td ('.now()->format('m-d-Y').')', $signedBytes);
        $this->assertStringContainsString('372.00 73.80 Td (Acme People LLC)', $signedBytes);
        $this->assertStringContainsString('320.00 451.80 Td (Priya Shah, HR Manager)', $signedBytes);
        $this->assertStringContainsString('126.00 433.80 Td ('.now()->format('m-d-Y').')', $signedBytes);
        $this->assertStringNotContainsString('PHASE2 EVALUATION', $signedBytes);
        $this->assertSame('Priya Shah', DB::table('i983_certifications')->where('case_id', $caseId)->where('party', 'employer')->value('printed_name'));
        $this->assertSame('signature_applied', DB::table('i983_events')->where('case_id', $caseId)->where('actor_id', $owner->id)->value('action'));

        $this->actingAs($owner)->post('/i983/'.$caseId.'/finalize', ['confirm' => '1'])->assertRedirect();
        $this->actingAs($owner)->post('/i983/'.$caseId.'/finalize', ['confirm' => '1'])->assertSessionHasErrors('form');
        $this->actingAs($owner)->post('/i983/'.$caseId.'/signature', ['confirm' => '1'])->assertSessionHasErrors('form');
        $final = DB::table('i983_documents')->where('case_id', $caseId)->where('kind', 'final_signed')->first();
        $this->assertSame($signedBytes, Storage::disk('local')->get($final->file_path));
        $this->assertSame($signed->sha256, $final->sha256);
        $this->assertSame('I983_Riley_Quinn_Final_Signed.pdf', $final->original_name);
        $this->assertSame('finalized', DB::table('i983_cases')->where('id', $caseId)->value('status'));
        $this->assertSame($workingBytes, Storage::disk('local')->get($working->file_path));
        $this->assertSame($snapshotBytes, Storage::disk('local')->get($snapshot->file_path));
        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/training', $this->training('Changed after finalization.'))->assertSessionHasErrors('form');
        $this->assertSame('Complete one reviewed feature and discuss the result with the hiring manager during the weekly review meeting. The second sentence names the next skill to practice.', DB::table('i983_training_plans')->where('case_id', $caseId)->value('goals'));
        $this->actingAs($company->hr)->get('/i983/'.$caseId.'/documents/'.$final->id.'/download')->assertOk();
        $this->actingAs($company->hr)->get('/i983/'.$caseId)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('documents', fn (mixed $documents): bool => collect($documents)->every(
                fn (array $document): bool => ! array_key_exists('file_path', $document) && strlen((string) $document['sha256']) === 64
            ))
            ->where('canSign', true)
        );
        $this->actingAs($owner)->get('/i983/'.$caseId)->assertInertia(fn (Assert $page) => $page->where('canSign', true));
        $this->assertNotNull(DB::table('i983_events')->where('case_id', $caseId)->where('action', 'i983_finalized')->where('subject', $final->sha256)->first());
        $this->assertSame($officialHash, hash_file('sha256', $official));
        Mail::assertNothingSent();
    }

    public function test_student_section_two_is_versioned_private_and_separate_from_the_employer_signature(): void
    {
        Mail::fake();
        $official = storage_path('app/private/i983/official-i983.pdf');
        $officialHash = hash_file('sha256', $official);
        Carbon::setTestNow(Carbon::parse('2026-11-04 03:30:00', 'UTC'));

        try {
            $company = CompanyFixture::make('Acme People', 'acme-i983-section-two');
            DB::table('tenants')->where('id', $company->id)->update(['timezone' => 'America/New_York']);
            $owner = $this->owner($company);
            $this->actingAs($company->hr)->post('/i983', [
                'candidate_name' => 'Riley Quinn',
                'candidate_email' => 'riley.section2@example.test',
            ])->assertRedirect();
            $link = (string) session('i983_link');
            $caseId = (int) DB::table('i983_cases')->where('tenant_id', $company->id)->value('id');
            $sectionOne = [
                'middle_name' => 'Morgan',
                'student_role' => 'Data analyst trainee',
                'goals' => 'Complete one reviewed feature and discuss the result with the hiring manager.',
            ];

            $this->post($link, $this->student([
                ...$sectionOne,
                'signature' => UploadedFile::fake()->image('draft-signature.png', 680, 48),
                'confirm_certification' => '1',
                'confirm_name' => '1',
            ], false))->assertRedirect();
            $this->assertNull(DB::table('i983_cases')->where('id', $caseId)->value('student_submitted_at'));
            $this->assertSame(0, DB::table('i983_student_certifications')->where('case_id', $caseId)->count());

            $this->post($link, $this->student([...$sectionOne, 'confirm_certification' => '0'], true))
                ->assertSessionHasErrors('confirm_certification');
            $this->post($link, $this->student([...$sectionOne, 'signature' => 'Riley Quinn'], true))
                ->assertSessionHasErrors('signature');
            $this->post($link, $this->student([...$sectionOne, 'signature' => $this->blankSignature()], true))
                ->assertSessionHasErrors('signature');
            $this->assertNull(DB::table('i983_cases')->where('id', $caseId)->value('student_submitted_at'));
            $this->assertSame(0, DB::table('i983_documents')->where('case_id', $caseId)->count());

            $this->actingAs($company->hr)->post('/i983/signature', [
                'signatory_name' => 'Priya Shah',
                'signatory_title' => 'HR Manager',
                'signature' => UploadedFile::fake()->image('employer-signature.jpg', 240, 60),
            ])->assertRedirect();
            $employerPath = (string) DB::table('i983_signatories')->where('tenant_id', $company->id)->value('image_path');
            $this->post($link, $this->student([
                ...$sectionOne,
                'signature' => new UploadedFile(Storage::disk('local')->path($employerPath), 'employer-signature.jpg', 'image/jpeg', null, true),
            ], true))->assertSessionHasErrors('signature');
            $this->assertSame(0, DB::table('i983_student_certifications')->count());
            $this->actingAs($company->hr)->post('/i983/'.$caseId.'/student-certification', $sectionOne)->assertNotFound();
            $this->actingAs($owner)->post('/i983/'.$caseId.'/student-certification', $sectionOne)->assertNotFound();

            $this->post($link, $this->student($sectionOne, true))->assertRedirect();
            $this->post($link, $this->student($sectionOne, true))->assertSessionHasErrors('student');

            $first = DB::table('i983_student_certifications')->where('case_id', $caseId)->first();
            $firstBytes = Storage::disk('local')->get($first->signature_path);
            $linkId = (int) DB::table('i983_links')->where('case_id', $caseId)->orderByDesc('id')->value('id');
            $this->assertSame(1, (int) $first->version);
            $this->assertSame($linkId, (int) $first->link_id);
            $this->assertSame('Quinn, Riley Morgan', $first->printed_name);
            $this->assertSame('riley.quinn@example.test', $first->student_email);
            $this->assertSame(1, (int) $first->acknowledged);
            $this->assertSame('2026-11-03', Carbon::parse((string) $first->certified_on)->toDateString());
            $this->assertSame('America/New_York', $first->timezone);
            $this->assertTrue(Carbon::parse($first->certified_at)->equalTo(now()));
            $this->assertSame('i983/'.$company->id.'/'.$caseId.'/student-signature-v1.png', $first->signature_path);
            $this->assertSame(hash('sha256', (string) $firstBytes), $first->signature_sha256);
            $this->assertFalse(is_file(public_path($first->signature_path)));
            $this->assertSame('student_submitted', DB::table('i983_cases')->where('id', $caseId)->value('status'));
            $this->assertSame(0, DB::table('i983_certifications')->where('case_id', $caseId)->count());
            $this->assertNotNull(DB::table('i983_events')->where('case_id', $caseId)->where('action', 'student_certified')->where('subject', 'v1 link '.$linkId)->first());

            $snapshot = DB::table('i983_documents')->where('case_id', $caseId)->where('kind', 'student_snapshot')->first();
            $snapshotBytes = Storage::disk('local')->get($snapshot->file_path);
            $this->assertSame(5, preg_match_all('/\/Type\s*\/Page(?!s)/', $snapshotBytes));
            $this->assertStringContainsString('40.00 646.40 Td (Quinn, Riley)', $snapshotBytes);
            $this->assertStringContainsString('144.00 173.90 Td (Quinn, Riley Morgan)', $snapshotBytes);
            $this->assertStringContainsString('491.00 173.90 Td (11-03-2026)', $snapshotBytes);
            $this->assertStringNotContainsString('320.00 97.80 Td', $snapshotBytes);
            $this->assertStringNotContainsString('320.00 451.80 Td', $snapshotBytes);

            $this->actingAs($company->hr)->post('/i983', [
                'candidate_name' => 'Avery Cole',
                'candidate_email' => 'avery.section2@example.test',
            ])->assertRedirect();
            $otherLink = (string) session('i983_link');
            $otherCaseId = (int) DB::table('i983_cases')->where('tenant_id', $company->id)->where('candidate_email', 'avery.section2@example.test')->value('id');
            $reused = storage_path('framework/testing/reused-student-signature.png');
            file_put_contents($reused, $firstBytes);
            $this->post($otherLink, $this->student([
                'surname' => 'Cole',
                'given_name' => 'Avery',
                'email' => 'avery.cole@example.test',
                'student_role' => 'Data analyst trainee',
                'goals' => 'Complete one reviewed feature and discuss the result with the hiring manager.',
                'signature' => new UploadedFile($reused, 'reused-student-signature.png', 'image/png', null, true),
            ], true))->assertSessionHasErrors('signature');
            $this->assertSame(0, DB::table('i983_student_certifications')->where('case_id', $otherCaseId)->count());
            $this->assertNull(DB::table('i983_cases')->where('id', $otherCaseId)->value('student_submitted_at'));
            @unlink($reused);

            $this->actingAs($company->hr)->post('/i983/'.$caseId.'/correction', [
                'reason' => 'Please confirm the middle name.',
            ])->assertRedirect();
            $this->post($link, $this->student($sectionOne, true))->assertRedirect();
            $second = DB::table('i983_student_certifications')->where('case_id', $caseId)->where('version', 2)->first();
            $kept = DB::table('i983_student_certifications')->where('case_id', $caseId)->where('version', 1)->first();
            $this->assertNotNull($second);
            $this->assertSame($first->signature_path, $kept->signature_path);
            $this->assertSame($first->signature_sha256, $kept->signature_sha256);
            $this->assertSame($first->printed_name, $kept->printed_name);
            $this->assertSame($first->certified_at, $kept->certified_at);
            $this->assertNotSame($first->signature_path, $second->signature_path);
            $this->assertTrue(Storage::disk('local')->exists($first->signature_path));
            $this->assertTrue(Storage::disk('local')->exists($second->signature_path));
            $this->assertSame($firstBytes, Storage::disk('local')->get($first->signature_path));
            $this->assertSame(2, DB::table('i983_documents')->where('case_id', $caseId)->where('kind', 'student_snapshot')->count());
            $this->assertSame($snapshotBytes, Storage::disk('local')->get($snapshot->file_path));
            $this->assertSame('resubmitted', DB::table('i983_cases')->where('id', $caseId)->value('status'));

            $this->actingAs($company->hr)->post('/i983/'.$caseId.'/employer', $this->employer())->assertRedirect();
            $this->actingAs($company->hr)->post('/i983/'.$caseId.'/training', $this->training('Complete one reviewed feature and discuss the result with the hiring manager.'))->assertRedirect();
            $this->actingAs($company->hr)->post('/i983/'.$caseId.'/documents/working')->assertRedirect();
            $working = DB::table('i983_documents')->where('case_id', $caseId)->where('kind', 'hr_working')->first();
            $workingBytes = Storage::disk('local')->get($working->file_path);
            $this->assertSame(5, preg_match_all('/\/Type\s*\/Page(?!s)/', $workingBytes));
            $this->assertStringContainsString('144.00 173.90 Td (Quinn, Riley Morgan)', $workingBytes);
            $this->assertStringContainsString('491.00 173.90 Td (11-03-2026)', $workingBytes);
            $this->assertStringContainsString('320.00 97.80 Td (Priya Shah, HR Manager)', $workingBytes);
            $this->assertStringContainsString('320.00 451.80 Td (Priya Shah, HR Manager)', $workingBytes);

            $this->actingAs($company->lead)->post('/i983/'.$caseId.'/signature', ['confirm' => '1'])->assertForbidden();
            $this->actingAs($company->employee)->post('/i983/'.$caseId.'/finalize', ['confirm' => '1'])->assertForbidden();
            $this->actingAs($company->hr)->post('/i983/'.$caseId.'/signature', ['confirm' => '1'])->assertRedirect();
            $this->actingAs($owner)->post('/i983/'.$caseId.'/signature', ['confirm' => '1'])->assertSessionHasErrors('form');
            $this->assertSame($company->hr->id, (int) DB::table('i983_events')->where('case_id', $caseId)->where('action', 'signature_applied')->value('actor_id'));
            $signed = DB::table('i983_documents')->where('case_id', $caseId)->where('kind', 'employer_signed')->first();
            $signedBytes = Storage::disk('local')->get($signed->file_path);
            $this->assertSame(5, preg_match_all('/\/Type\s*\/Page(?!s)/', $signedBytes));
            $this->assertStringContainsString('144.00 173.90 Td (Quinn, Riley Morgan)', $signedBytes);
            $this->assertStringContainsString('491.00 173.90 Td (11-03-2026)', $signedBytes);
            $this->assertStringContainsString('320.00 97.80 Td (Priya Shah, HR Manager)', $signedBytes);
            $this->assertStringContainsString('126.00 73.80 Td ('.now()->format('m-d-Y').')', $signedBytes);
            $this->assertStringContainsString('372.00 73.80 Td (Acme People LLC)', $signedBytes);
            $this->assertStringContainsString('320.00 451.80 Td (Priya Shah, HR Manager)', $signedBytes);
            $this->assertStringContainsString('126.00 433.80 Td ('.now()->format('m-d-Y').')', $signedBytes);
            $this->assertStringNotContainsString('372.00 433.80 Td', $signedBytes);
            $this->assertSame($workingBytes, Storage::disk('local')->get($working->file_path));
            $this->assertSame($snapshotBytes, Storage::disk('local')->get($snapshot->file_path));
            $this->assertSame('Priya Shah', DB::table('i983_certifications')->where('case_id', $caseId)->where('party', 'employer')->value('printed_name'));
            $this->assertSame(2, DB::table('i983_student_certifications')->where('case_id', $caseId)->count());

            $this->actingAs($owner)->post('/i983/'.$caseId.'/finalize', ['confirm' => '1'])->assertRedirect();
            $final = DB::table('i983_documents')->where('case_id', $caseId)->where('kind', 'final_signed')->first();
            $this->assertSame($signedBytes, Storage::disk('local')->get($final->file_path));
            $this->assertSame($signed->sha256, $final->sha256);
            $this->assertSame(hash('sha256', $signedBytes), $final->sha256);
            $this->assertSame(5, preg_match_all('/\/Type\s*\/Page(?!s)/', Storage::disk('local')->get($final->file_path)));
            $download = $this->get($link.'/final/download');
            $download->assertOk();
            $this->assertSame($signedBytes, $download->getContent());
            $this->assertSame($officialHash, hash_file('sha256', $official));
            $this->assertSame('c1fffede432651cf1d6b5c9a0647d0aad96754b28ae9b40d5fd2d6d8ca0d7202', $officialHash);
            Mail::assertNothingSent();
        } finally {
            Carbon::setTestNow();
        }
    }

    private function blankSignature(): UploadedFile
    {
        $image = imagecreatetruecolor(680, 48);
        $white = imagecolorallocate($image, 255, 255, 255);
        imagefill($image, 0, 0, $white);
        $directory = storage_path('framework/testing');
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        $path = $directory.'/blank-student-signature.png';
        imagepng($image, $path);
        imagedestroy($image);

        return new UploadedFile($path, 'blank-student-signature.png', 'image/png', null, true);
    }

    private function owner(CompanyFixture $company): User
    {
        $user = User::factory()->create();
        $user->forceFill([
            'tenant_id' => $company->id,
            'is_platform_admin' => false,
            'invite_accepted_at' => now(),
        ])->save();
        DB::table('model_has_roles')->insert([
            'role_id' => DB::table('roles')->where('tenant_id', $company->id)->where('name', 'Owner')->value('id'),
            'model_type' => User::class,
            'model_id' => $user->id,
            'tenant_id' => $company->id,
        ]);

        return $user->refresh();
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
            'middle_name' => '',
            'email' => 'riley.quinn@example.test',
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
            'goals' => '',
            'submit' => $submit ? 1 : 0,
            ...($submit ? I983StudentSignature::fields() : []),
            ...$overrides,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function employer(): array
    {
        return [
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
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function training(?string $goals = null): array
    {
        return [
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
            'goals' => $goals ?? 'Complete one reviewed feature and discuss the result with the hiring manager during the weekly review meeting. The second sentence names the next skill to practice.',
            'oversight' => 'Weekly check-in with the hiring manager.',
            'measures' => 'Reviewed project notes each month.',
            'remarks' => 'Sample remarks for the training plan.',
        ];
    }
}
