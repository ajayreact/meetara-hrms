<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CompanyFixture;
use Tests\TestCase;

class I983EvaluationSignatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_second_signature_request_cannot_replace_the_accepted_signature(): void
    {
        [$company, $caseId, $link] = $this->submittedCase('i983-eval-race', 'mina.race@example.test');
        $studentBefore = $this->signatureFiles($company->id, $caseId, 'student');
        $employerBefore = $this->signatureFiles($company->id, $caseId, 'employer');
        $this->saveDates($company, $caseId);
        $this->post($link.'/evaluation', [
            'narrative' => 'I completed the assigned software projects and documented the results myself.',
            'signature' => $this->png(20, 30, 40),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->storeSignatory($company, 20, 30, 40);
        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/evaluation/signature')->assertRedirect()->assertSessionHasNoErrors();

        $saved = DB::table('i983_evaluations')->where('case_id', $caseId)->first();
        $studentHash = hash('sha256', (string) Storage::disk('local')->get((string) $saved->student_signature_path));
        $employerHash = hash('sha256', (string) Storage::disk('local')->get((string) $saved->employer_signature_path));
        $events = $this->events($caseId);

        $this->post($link.'/evaluation', [
            'narrative' => 'A second request tried to replace the narrative.',
            'printed_name' => 'Someone Else',
            'signature' => $this->png(200, 10, 10),
        ])->assertSessionHasErrors('narrative');
        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/evaluation/signature')->assertSessionHasErrors('signature');

        $again = DB::table('i983_evaluations')->where('case_id', $caseId)->first();
        $this->assertSame($saved->student_signature_path, $again->student_signature_path);
        $this->assertSame($saved->employer_signature_path, $again->employer_signature_path);
        $this->assertSame('I completed the assigned software projects and documented the results myself.', $again->narrative);
        $this->assertSame('Patel, Mina', $again->student_printed_name);
        $this->assertSame('Jordan Hale', $again->employer_printed_name);
        $this->assertSame('Director', $again->employer_title);
        $this->assertSame($studentHash, hash('sha256', (string) Storage::disk('local')->get((string) $again->student_signature_path)));
        $this->assertSame($employerHash, hash('sha256', (string) Storage::disk('local')->get((string) $again->employer_signature_path)));
        $this->assertSame($events, $this->events($caseId));
        $this->assertSame([$saved->student_signature_path], $this->newSignatureFiles($company->id, $caseId, 'student', $studentBefore));
        $this->assertSame([$saved->employer_signature_path], $this->newSignatureFiles($company->id, $caseId, 'employer', $employerBefore));
    }

    public function test_a_failed_signature_audit_removes_only_the_new_file(): void
    {
        [$company, $caseId, $link] = $this->submittedCase('i983-eval-audit', 'mina.audit@example.test');
        $this->saveDates($company, $caseId);
        $narrative = 'I completed the assigned software projects and documented the results myself.';
        $this->post($link.'/evaluation', [
            'narrative' => $narrative,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $studentBefore = $this->signatureFiles($company->id, $caseId, 'student');
        $employerBefore = $this->signatureFiles($company->id, $caseId, 'employer');
        DB::unprepared("CREATE TRIGGER fail_student_eval_audit BEFORE INSERT ON i983_events WHEN NEW.action = 'evaluation_student_signed' BEGIN SELECT RAISE(ABORT, 'audit failed'); END");

        $this->post($link.'/evaluation', [
            'narrative' => $narrative,
            'signature' => $this->png(20, 30, 40),
        ])->assertStatus(500);

        $row = DB::table('i983_evaluations')->where('case_id', $caseId)->first();
        $this->assertSame($narrative, $row->narrative);
        $this->assertNull($row->student_signature_path);
        $this->assertNull($row->student_printed_name);
        $this->assertSame([], $this->newSignatureFiles($company->id, $caseId, 'student', $studentBefore));
        $this->assertSame(0, DB::table('i983_events')->where('case_id', $caseId)->where('action', 'evaluation_student_signed')->count());
        DB::unprepared('DROP TRIGGER IF EXISTS fail_student_eval_audit');

        $this->post($link.'/evaluation', [
            'narrative' => $narrative,
            'signature' => $this->png(20, 30, 40),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $signed = DB::table('i983_evaluations')->where('case_id', $caseId)->first();
        $studentHash = hash('sha256', (string) Storage::disk('local')->get((string) $signed->student_signature_path));
        $this->storeSignatory($company, 12, 40, 70);
        $employerBefore = $this->signatureFiles($company->id, $caseId, 'employer');
        DB::unprepared("CREATE TRIGGER fail_employer_eval_audit BEFORE INSERT ON i983_events WHEN NEW.action = 'evaluation_employer_signed' BEGIN SELECT RAISE(ABORT, 'audit failed'); END");

        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/evaluation/signature')->assertStatus(500);

        $after = DB::table('i983_evaluations')->where('case_id', $caseId)->first();
        $this->assertSame($signed->student_signature_path, $after->student_signature_path);
        $this->assertSame($studentHash, hash('sha256', (string) Storage::disk('local')->get((string) $after->student_signature_path)));
        $this->assertSame($narrative, $after->narrative);
        $this->assertSame('Patel, Mina', $after->student_printed_name);
        $this->assertNull($after->employer_signature_path);
        $this->assertNull($after->employer_printed_name);
        $this->assertSame([], $this->newSignatureFiles($company->id, $caseId, 'employer', $employerBefore));
        $this->assertSame(0, DB::table('i983_events')->where('case_id', $caseId)->where('action', 'evaluation_employer_signed')->count());
        DB::unprepared('DROP TRIGGER IF EXISTS fail_employer_eval_audit');
    }

    public function test_evaluation_signature_reuse_is_limited_to_the_same_company(): void
    {
        $image = $this->png(30, 60, 90);
        $path = (string) $image->getRealPath();
        $upload = fn (): UploadedFile => new UploadedFile($path, 'signature.png', 'image/png', null, true);
        [$company, $caseId, $link] = $this->submittedCase('i983-eval-tenant-a', 'mina.tenant.a@example.test');
        $this->saveDates($company, $caseId);
        $this->post($link.'/evaluation', [
            'narrative' => 'I completed the assigned software projects and documented the results myself.',
            'signature' => $upload(),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $stored = (string) DB::table('i983_evaluations')->where('case_id', $caseId)->value('student_signature_path');
        $hash = hash('sha256', (string) Storage::disk('local')->get($stored));

        [$other, $otherCaseId, $otherLink] = $this->submittedCase('i983-eval-tenant-b', 'mina.tenant.b@example.test');
        $this->saveDates($other, $otherCaseId);
        $this->post($otherLink.'/evaluation', [
            'narrative' => 'I completed the assigned software projects and documented the results myself.',
            'signature' => $upload(),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNotSame($company->id, $other->id);
        $this->assertStringStartsWith('i983/'.$other->id.'/', (string) DB::table('i983_evaluations')->where('case_id', $otherCaseId)->value('student_signature_path'));

        [$again, $againCaseId, $againLink] = $this->submittedCase('i983-eval-tenant-a2', 'mina.tenant.a2@example.test', $company);
        $this->saveDates($again, $againCaseId);
        $this->post($againLink.'/evaluation', [
            'narrative' => 'I completed the assigned software projects and documented the results myself.',
            'signature' => $upload(),
        ])->assertSessionHasErrors('signature');
        $this->assertNull(DB::table('i983_evaluations')->where('case_id', $againCaseId)->value('student_signature_path'));
        $this->assertSame($stored, (string) DB::table('i983_evaluations')->where('case_id', $caseId)->value('student_signature_path'));
        $this->assertSame($hash, hash('sha256', (string) Storage::disk('local')->get($stored)));
        $this->assertSame(1, DB::table('i983_events')->where('case_id', $caseId)->where('action', 'evaluation_student_signed')->count());
    }

    /**
     * @return array{0: CompanyFixture, 1: int, 2: string}
     */
    private function submittedCase(string $slug, string $email, ?CompanyFixture $company = null): array
    {
        $company ??= CompanyFixture::make('Veltrix Digital Solutions', $slug);
        $this->actingAs($company->hr)->post('/i983', [
            'candidate_name' => 'Mina Patel',
            'candidate_email' => $email,
        ])->assertRedirect();
        $link = (string) session('i983_link');
        $caseId = (int) DB::table('i983_cases')->where('tenant_id', $company->id)->where('candidate_email', $email)->value('id');
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

        return [$company, $caseId, $link];
    }

    private function saveDates(CompanyFixture $company, int $caseId): void
    {
        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/evaluation', [
            'date_from' => '2026-06-01',
            'date_to' => '2028-01-14',
        ])->assertRedirect()->assertSessionHasNoErrors();
    }

    private function storeSignatory(CompanyFixture $company, int $red, int $green, int $blue): void
    {
        $file = $this->png($red, $green, $blue);
        Storage::disk('local')->put('i983/'.$company->id.'/signatory.png', (string) file_get_contents($file->getRealPath()));
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
    }

    private function png(int $red, int $green, int $blue): UploadedFile
    {
        $image = imagecreatetruecolor(680, 48);
        $ink = imagecolorallocate($image, $red, $green, $blue);
        imagefilledrectangle($image, 24, 14, 420, 32, $ink);
        $directory = storage_path('framework/testing');

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $path = $directory.'/eval-signature-'.bin2hex(random_bytes(8)).'.png';
        imagepng($image, $path);
        imagedestroy($image);

        return new UploadedFile($path, 'signature.png', 'image/png', null, true);
    }

    /**
     * @return list<array{id: int, action: string, subject: string|null}>
     */
    private function events(int $caseId): array
    {
        return DB::table('i983_events')
            ->where('case_id', $caseId)
            ->orderBy('id')
            ->get(['id', 'action', 'subject'])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'action' => (string) $row->action,
                'subject' => $row->subject === null ? null : (string) $row->subject,
            ])
            ->all();
    }

    /**
     * @param  list<string>  $before
     * @return list<string>
     */
    private function newSignatureFiles(int $tenantId, int $caseId, string $role, array $before): array
    {
        return array_values(array_diff($this->signatureFiles($tenantId, $caseId, $role), $before));
    }

    /**
     * @return list<string>
     */
    private function signatureFiles(int $tenantId, int $caseId, string $role): array
    {
        return array_values(array_filter(
            Storage::disk('local')->allFiles('i983/'.$tenantId.'/'.$caseId),
            fn (string $path): bool => str_contains($path, '/evaluation-'.$role.'-'),
        ));
    }
}
