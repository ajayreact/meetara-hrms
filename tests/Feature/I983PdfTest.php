<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Hrms\I983Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CompanyFixture;
use Tests\Support\I983StudentSignature;
use Tests\TestCase;

class I983PdfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->isolatePrivateDisk();
    }

    protected function tearDown(): void
    {
        foreach (['i983_documents' => 'file_path', 'i983_student_certifications' => 'signature_path'] as $table => $column) {
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

    public function test_official_form_versions_are_private_black_and_leave_earlier_files_unchanged(): void
    {
        Mail::fake();
        $official = storage_path('app/private/i983/official-i983.pdf');
        $this->assertFileExists($official);
        $officialHash = hash_file('sha256', $official);
        $company = CompanyFixture::make('Acme People', 'acme-i983-pdf');
        $other = CompanyFixture::make('Other Company', 'other-i983-pdf');
        $created = $this->actingAs($company->hr)->post('/i983', [
            'candidate_name' => 'Riley Quinn',
            'candidate_email' => 'riley.pdf@example.test',
        ]);
        $link = (string) session('i983_link');
        $caseId = (int) DB::table('i983_cases')->where('tenant_id', $company->id)->value('id');
        $goals = 'Complete one reviewed feature and discuss the result with the hiring manager during the weekly review meeting. The second sentence names the next skill to practice.';

        $this->post($link, $this->student([
            'dso_email' => 'designated.official@state-university.example.edu',
            'student_role' => 'Data analyst trainee',
            'goals' => $goals,
        ], true))->assertSessionHasErrors('dso_email');
        $this->assertNull(DB::table('i983_cases')->where('id', $caseId)->value('student_submitted_at'));
        $this->assertSame(0, DB::table('i983_documents')->where('case_id', $caseId)->count());
        $this->assertSame(0, DB::table('i983_student_certifications')->where('case_id', $caseId)->count());

        $this->post($link, $this->student([
            'student_role' => 'Data analyst trainee',
            'goals' => $goals,
        ], true))->assertRedirect();

        $snapshot = DB::table('i983_documents')->where('case_id', $caseId)->where('kind', 'student_snapshot')->first();
        $this->assertNotNull($snapshot);
        $this->assertSame(1, (int) $snapshot->version);
        $this->assertSame($company->id, (int) $snapshot->tenant_id);
        $this->assertSame('i983/'.$company->id.'/'.$caseId.'/student_snapshot-v1.pdf', $snapshot->file_path);
        $this->assertFalse(is_file(public_path($snapshot->file_path)));
        $bytes = Storage::disk('local')->get($snapshot->file_path);
        $this->assertSame($snapshot->sha256, hash('sha256', $bytes));
        $this->assertSame(5, preg_match_all('/\/Type\s*\/Page(?!s)/', $bytes));
        $this->assertStringContainsString('0.000 g', $bytes);
        $this->assertStringNotContainsString('0.275 0.549 rg', $bytes);
        $this->assertStringContainsString('(Quinn, Riley)', $bytes);
        $this->assertStringContainsString('264.00 730.68 Td (Quinn, Riley)', $bytes);
        $this->assertStringContainsString('(Complete one reviewed feature and discuss the result with the hiring manager during the weekly review meeting. The second sentence names the)', $bytes);
        $this->assertStringContainsString('(next skill to practice.)', $bytes);
        $this->assertStringNotContainsString('PHASE2 EVALUATION', $bytes);
        $this->assertStringNotContainsString('(SAMPLE EVALUATION', $bytes);

        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/employer', $this->employer())->assertRedirect();
        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/training', $this->training($goals))->assertRedirect();
        $this->assertSame($bytes, Storage::disk('local')->get($snapshot->file_path));
        $this->actingAs($company->hr)->post('/i983/signature', [
            'signatory_name' => 'Priya Shah',
            'signatory_title' => 'HR Manager',
            'signature' => UploadedFile::fake()->image('signature.jpg', 240, 60),
        ])->assertRedirect();

        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/documents/working')->assertRedirect();
        $working = DB::table('i983_documents')->where('case_id', $caseId)->where('kind', 'hr_working')->orderBy('id')->first();
        $workingBytes = Storage::disk('local')->get($working->file_path);
        $this->assertNotSame($snapshot->sha256, $working->sha256);
        $this->assertSame(5, preg_match_all('/\/Type\s*\/Page(?!s)/', $workingBytes));
        $this->assertStringContainsString('0.000 g', $workingBytes);
        $this->assertStringContainsString('118.00 706.68 Td (Acme People LLC)', $workingBytes);
        $this->assertStringContainsString('300.00 652.00 Td (500 Training Plaza, Austin, TX 78701)', $workingBytes);
        $this->assertStringContainsString('346.00 625.70 Td (72000.00)', $workingBytes);
        $this->assertStringNotContainsString('per hour', $workingBytes);
        $this->assertStringContainsString('320.00 97.80 Td (Priya Shah, HR Manager)', $workingBytes);
        $this->assertStringContainsString('372.00 73.80 Td (Acme People LLC)', $workingBytes);
        $this->assertStringContainsString('320.00 451.80 Td (Priya Shah, HR Manager)', $workingBytes);
        $this->assertStringNotContainsString('PHASE2 EVALUATION', $workingBytes);
        $this->assertSame($bytes, Storage::disk('local')->get($snapshot->file_path));

        DB::table('i983_training_plans')->where('case_id', $caseId)->update(['site_address' => 'Michigan']);
        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/documents/working')->assertSessionHasErrors('site_address');
        $this->assertSame('Michigan', DB::table('i983_training_plans')->where('case_id', $caseId)->value('site_address'));
        $this->assertSame(1, DB::table('i983_documents')->where('case_id', $caseId)->where('kind', 'hr_working')->count());

        DB::table('i983_training_plans')->where('case_id', $caseId)->update(['site_address' => '500 Training Plaza, Austin, TX 78701']);
        $overflow = trim(str_repeat('The student will practice a reviewed workflow. ', 30));
        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/training', $this->training($overflow))->assertRedirect();
        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/documents/working')->assertSessionHasErrors('goals');
        $this->assertSame($overflow, DB::table('i983_training_plans')->where('case_id', $caseId)->value('goals'));
        $this->assertSame(1, DB::table('i983_documents')->where('case_id', $caseId)->where('kind', 'hr_working')->count());
        $this->assertSame($workingBytes, Storage::disk('local')->get($working->file_path));
        $this->assertSame($bytes, Storage::disk('local')->get($snapshot->file_path));

        $revised = 'Practice one reviewed workflow and write down the result.';
        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/training', $this->training($revised))->assertRedirect();
        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/documents/working')->assertRedirect();
        $second = DB::table('i983_documents')->where('case_id', $caseId)->where('kind', 'hr_working')->orderByDesc('id')->first();
        $this->assertSame(2, (int) $second->version);
        $this->assertNotSame($working->sha256, $second->sha256);
        $this->assertSame($bytes, Storage::disk('local')->get($snapshot->file_path));
        $this->assertSame($workingBytes, Storage::disk('local')->get($working->file_path));

        $this->actingAs($company->lead)->post('/i983/'.$caseId.'/documents/working')->assertForbidden();
        $this->actingAs($other->hr)->post('/i983/'.$caseId.'/documents/working')->assertNotFound();
        $this->actingAs($company->hr)->get('/i983/'.$caseId.'/pdf')->assertNotFound();
        $this->assertSame($officialHash, hash_file('sha256', $official));
        Mail::assertNothingSent();
    }

    public function test_degree_labels_and_prior_degree_marks_follow_the_stored_answer(): void
    {
        $pdf = app(I983Pdf::class);
        $base = $this->values();
        $tick = '130.70 476.70 m 132.70 473.90 l S';
        $tickStem = '132.70 473.90 m 136.30 479.20 l S';
        $cross = '130.70 479.30 m 136.30 473.70 l S';
        $crossStem = '130.70 473.70 m 136.30 479.30 l S';
        $unknown = '131.36 473.92 Td (?)';

        $master = $pdf->render([...$base, 'degree_level' => 'Master', 'prior_degree' => 'yes']);
        $this->assertStringContainsString("172.00 511.80 Td (Master's)", $master);
        $this->assertDoesNotMatchRegularExpression('/172\\.00 511\\.80 Td \\(Master\\)/', $master);
        $this->assertStringContainsString($tick, $master);
        $this->assertStringContainsString($tickStem, $master);
        $this->assertStringNotContainsString($cross, $master);

        $bachelor = $pdf->render([...$base, 'degree_level' => "Bachelor's", 'prior_degree' => 'no']);
        $this->assertStringContainsString("172.00 511.80 Td (Bachelor's)", $bachelor);
        $this->assertStringContainsString($cross, $bachelor);
        $this->assertStringContainsString($crossStem, $bachelor);
        $this->assertStringNotContainsString($tick, $bachelor);

        $curly = $pdf->render([...$base, 'degree_level' => "Master\u{2019}s", 'prior_degree' => false]);
        $this->assertStringContainsString("172.00 511.80 Td (Master's)", $curly);
        $this->assertStringContainsString($cross, $curly);

        $associate = $pdf->render([...$base, 'degree_level' => 'Associate', 'prior_degree' => 0]);
        $this->assertStringContainsString("172.00 511.80 Td (Associate's)", $associate);
        $this->assertStringContainsString($cross, $associate);

        $doctorate = $pdf->render([...$base, 'degree_level' => 'Doctorate', 'prior_degree' => 'true']);
        $this->assertStringContainsString('172.00 511.80 Td (Doctorate)', $doctorate);
        $this->assertStringContainsString($tick, $doctorate);
        $this->assertStringNotContainsString($unknown, $doctorate);

        $custom = $pdf->render([...$base, 'degree_level' => 'Ph.D.', 'prior_degree' => null]);
        $this->assertStringContainsString('172.00 511.80 Td (Ph.D.)', $custom);
        $this->assertStringContainsString($unknown, $custom);
        $this->assertStringNotContainsString($tick, $custom);
        $this->assertStringNotContainsString($cross, $custom);

        $blank = $pdf->render([...$base, 'degree_level' => 'Doctorate', 'prior_degree' => '']);
        $this->assertStringContainsString($unknown, $blank);
        $this->assertStringNotContainsString($tick, $blank);
        $this->assertStringNotContainsString($cross, $blank);

        $pdf->assertFits([...$base, 'degree_level' => 'Master'], 'student');
        $pdf->assertFits([...$base, 'degree_level' => "Bachelor's"], 'student');
        $pdf->assertFits([...$base, 'degree_level' => 'Associate'], 'student');
        $pdf->assertFits([...$base, 'degree_level' => 'Doctorate'], 'student');

        Mail::fake();
        $official = storage_path('app/private/i983/official-i983.pdf');
        $officialHash = hash_file('sha256', $official);
        $company = CompanyFixture::make('Acme People', 'acme-i983-degree');
        $this->actingAs($company->hr)->post('/i983', [
            'candidate_name' => 'Riley Quinn',
            'candidate_email' => 'riley.degree@example.test',
        ])->assertRedirect();
        $caseId = (int) DB::table('i983_cases')->where('tenant_id', $company->id)->value('id');
        $this->post((string) session('i983_link'), $this->student(['prior_degree' => 'yes'], true))->assertRedirect();

        $this->assertSame('Master', DB::table('i983_students')->where('case_id', $caseId)->value('degree_level'));
        $this->assertSame(1, (int) DB::table('i983_students')->where('case_id', $caseId)->value('prior_degree'));
        $snapshot = Storage::disk('local')->get(
            (string) DB::table('i983_documents')->where('case_id', $caseId)->where('kind', 'student_snapshot')->value('file_path')
        );
        $this->assertStringContainsString("172.00 511.80 Td (Master's)", $snapshot);
        $this->assertStringContainsString($tick, $snapshot);
        $this->assertStringNotContainsString($cross, $snapshot);
        $this->assertSame($officialHash, hash_file('sha256', $official));
    }

    public function test_section_two_uses_the_measured_lines_and_does_not_sign_the_employer_sections(): void
    {
        $image = imagecreatetruecolor(680, 48);
        $ink = imagecolorallocate($image, 17, 24, 39);
        imagefilledrectangle($image, 30, 18, 420, 30, $ink);
        $directory = storage_path('framework/testing');
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        $path = $directory.'/section-two-signature.png';
        imagepng($image, $path);
        imagedestroy($image);

        $bytes = app(I983Pdf::class)->render($this->values(), null, [
            'image' => $path,
            'printed_name' => 'Quinn, Riley Morgan',
            'date' => '11-03-2026',
        ]);
        unlink($path);

        $this->assertSame(5, preg_match_all('/\/Type\s*\/Page(?!s)/', $bytes));
        $this->assertStringContainsString('40.00 646.40 Td (Quinn, Riley)', $bytes);
        $this->assertStringContainsString('144.00 173.90 Td (Quinn, Riley Morgan)', $bytes);
        $this->assertStringContainsString('491.00 173.90 Td (11-03-2026)', $bytes);
        $this->assertStringContainsString('/Subtype /Image', $bytes);
        $this->assertStringNotContainsString('320.00 97.80 Td', $bytes);
        $this->assertStringNotContainsString('320.00 451.80 Td', $bytes);
        $this->assertStringNotContainsString('372.00 73.80 Td', $bytes);
    }

    public function test_the_student_email_stays_on_one_line_inside_its_field(): void
    {
        $pdf = app(I983Pdf::class);
        $base = $this->values();
        $emails = [
            'a@b.co',
            'riley.quinn@example.test',
            'student.name@very-long-university.example.edu',
        ];

        foreach ($emails as $email) {
            $pdf->assertFits([...$base, 'email' => $email], 'student');
            $bytes = $pdf->render([...$base, 'email' => $email]);

            $this->assertSame(1, preg_match_all('/330\.00 646\.40 Td \('.preg_quote($email, '/').'\) Tj/', $bytes));
            $this->assertStringNotContainsString('316.00 646.40 Td', $bytes);
            $this->assertDoesNotMatchRegularExpression('/\/F\d+ (?!8\.00)\d+\.\d+ Tf/', $bytes);
            $this->assertStringContainsString('40.00 646.40 Td (Quinn, Riley)', $bytes);
            $this->assertStringContainsString('36.00 595.60 Td (Example State University)', $bytes);
        }

        $wide = 'alexandra.montgomery@stem-extension-office.university.example.edu';
        $pdf->assertFits([...$base, 'email' => $wide], 'student');
        $bytes = $pdf->render([...$base, 'email' => $wide]);
        $emailAt = strpos($bytes, '330.00 646.40 Td ('.$wide.')');
        $schoolAt = strpos($bytes, '36.00 595.60 Td (Example State University)');

        $this->assertNotFalse($emailAt);
        $this->assertNotFalse($schoolAt);
        $this->assertSame(1, substr_count($bytes, $wide));
        preg_match_all('/BT \/F1 ([0-9.]+) Tf ET/', substr($bytes, 0, $emailAt), $fonts);
        $this->assertSame('7.50', (string) end($fonts[1]));
        $this->assertStringContainsString('BT /F1 8.00 Tf ET', substr($bytes, $emailAt, $schoolAt - $emailAt));
        $this->assertStringContainsString('40.00 646.40 Td (Quinn, Riley)', $bytes);

        foreach ([
            'a@b.co' => null,
            'dso@ex.edu' => null,
            'long-dso-name@example.test' => '7.00',
        ] as $dsoEmail => $size) {
            $pdf->assertFits([...$base, 'dso_email' => $dsoEmail], 'student');
            $bytes = $pdf->render([...$base, 'dso_email' => $dsoEmail]);

            $this->assertSame(1, preg_match_all('/127\.00 549\.50 Td \('.preg_quote($dsoEmail, '/').'\) Tj/', $bytes));
            $this->assertStringNotContainsString('172.00 549.50 Td', $bytes);
            $this->assertStringContainsString('330.00 646.40 Td (riley.quinn@example.test)', $bytes);
            $this->assertStringContainsString('36.00 549.50 Td (Avery Cole)', $bytes);
            $this->assertStringContainsString('234.00 549.50 Td (+1 \(248\) 555-0199)', $bytes);

            if ($size === null) {
                $this->assertDoesNotMatchRegularExpression('/\/F\d+ (?!8\.00)\d+\.\d+ Tf/', $bytes);

                continue;
            }

            $dsoAt = strpos($bytes, '127.00 549.50 Td ('.$dsoEmail.')');
            $phoneAt = strpos($bytes, '234.00 549.50 Td');
            $this->assertNotFalse($dsoAt);
            $this->assertNotFalse($phoneAt);
            preg_match_all('/BT \/F1 ([0-9.]+) Tf ET/', substr($bytes, 0, $dsoAt), $dsoFonts);
            $this->assertSame($size, (string) end($dsoFonts[1]));
            $this->assertStringContainsString('BT /F1 8.00 Tf ET', substr($bytes, $dsoAt, $phoneAt - $dsoAt));
        }

        $tooLongDso = 'designated.official@state-university.example.edu';

        try {
            $pdf->assertFits([...$base, 'dso_email' => $tooLongDso], 'student');
            $this->fail('A DSO email wider than its cell at 6 points was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('dso_email', $exception->errors());
        }

        $tooLong = str_repeat('m', 80).'@example.test';

        try {
            $pdf->assertFits([...$base, 'email' => $tooLong], 'student');
            $this->fail('An email wider than the field at 6 points was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('email', $exception->errors());
        }

        try {
            $pdf->render([...$base, 'email' => $tooLong]);
            $this->fail('An email wider than the field at 6 points was printed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('email', $exception->errors());
        }
    }

    public function test_a_long_employer_street_does_not_block_the_student_and_is_checked_before_the_final_pdf(): void
    {
        $long = trim(str_repeat('NorthIndustrialOverflow ', 8));
        $pdf = app(I983Pdf::class);
        $pdf->assertFits([...$this->values(), 'street' => $long, 'employer_name' => 'Veltrix Digital Solutions Inc', 'ein' => '43-2316091'], 'student');
        $studentCopy = $pdf->render([
            ...$this->values(),
            'street' => $long,
            'employer_name' => 'Veltrix Digital Solutions Inc',
            'ein' => '43-2316091',
            'student_name' => 'Quinn, Riley',
        ], null, null, false, true);
        $this->assertStringContainsString('(Quinn, Riley)', $studentCopy);
        $this->assertStringNotContainsString('NorthIndustrialOverflow', $studentCopy);
        $this->assertStringNotContainsString('Veltrix Digital Solutions Inc', $studentCopy);
        $this->assertStringNotContainsString('43-2316091', $studentCopy);
        $fitted = $pdf->render([...$this->values(), 'street' => '1 Main Street', 'city' => 'Farmington', 'salary' => '28000.00'], null, null, false, true);
        $this->assertStringContainsString('(Quinn, Riley)', $fitted);
        $this->assertStringNotContainsString('(1 Main Street)', $fitted);
        $this->assertStringNotContainsString('(Farmington)', $fitted);
        $this->assertStringNotContainsString('(28000.00)', $fitted);

        try {
            $pdf->assertEmployerAddressLayout(['street' => $long]);
            $this->fail('The employer street was accepted even though it does not fit the official box.');
        } catch (ValidationException $exception) {
            $this->assertSame('Street address does not fit in its box on the official form. Shorten it.', $exception->errors()['street'][0]);
        }

        $company = CompanyFixture::make('Acme People', 'acme-i983-street');
        $this->actingAs($company->hr)->post('/i983', [
            'candidate_name' => 'Riley Quinn',
            'candidate_email' => 'riley.street@example.test',
        ])->assertRedirect();
        $link = (string) session('i983_link');
        $caseId = (int) DB::table('i983_cases')->where('tenant_id', $company->id)->value('id');
        DB::table('i983_employers')->where('case_id', $caseId)->update(['street' => $long]);

        $this->get($link)->assertOk()->assertDontSee('Street address does not fit');
        $this->post($link, $this->student(['student_role' => 'Data analyst trainee', 'goals' => 'Practice one reviewed workflow.'], false))
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();
        $this->post($link, $this->student(['student_role' => 'Data analyst trainee', 'goals' => 'Practice one reviewed workflow.'], true))
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $this->assertNotNull(DB::table('i983_cases')->where('id', $caseId)->value('student_submitted_at'));
        $this->assertSame(1, DB::table('i983_student_certifications')->where('case_id', $caseId)->count());
        $this->assertSame($long, DB::table('i983_employers')->where('case_id', $caseId)->value('street'));
        $snapshot = Storage::disk('local')->get('i983/'.$company->id.'/'.$caseId.'/student_snapshot-v1.pdf');
        $this->assertIsString($snapshot);
        $this->assertStringNotContainsString('NorthIndustrialOverflow', $snapshot);
        $this->actingAs($company->hr)->get('/i983/'.$caseId)->assertOk()->assertSee('Street address does not fit in its box on the official form. Shorten it.');

        $rejected = $this->employer();
        $rejected['street'] = $long;
        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/employer', $rejected)->assertSessionHasErrors('street');
        $this->assertSame($long, DB::table('i983_employers')->where('case_id', $caseId)->value('street'));

        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/employer', $this->employer())->assertRedirect();
        $this->assertSame('1 Main Street', DB::table('i983_employers')->where('case_id', $caseId)->value('street'));
        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/training', $this->training('Practice one reviewed workflow.'))->assertRedirect();
        $this->actingAs($company->hr)->post('/i983/signature', [
            'signatory_name' => 'Priya Shah',
            'signatory_title' => 'HR Manager',
            'signature' => UploadedFile::fake()->image('signature.jpg', 240, 60),
        ])->assertRedirect();
        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/final', ['confirm' => '1'])->assertRedirect();
        $this->assertSame('finalized', DB::table('i983_cases')->where('id', $caseId)->value('status'));
        $this->assertSame(1, DB::table('i983_documents')->where('case_id', $caseId)->where('kind', 'final_signed')->count());
    }

    public function test_student_submitted_pdf_leaves_employer_section_blank_and_later_versions_keep_it(): void
    {
        Mail::fake();
        $company = CompanyFixture::make('Veltrix Digital Solutions', 'i983-student-blank-employer');
        $this->actingAs($company->hr)->put('/company/settings/i983-employer', [
            'legal_name' => 'Veltrix Digital Solutions Inc',
            'website' => 'https://veltrixds.com',
            'street' => '37085 Grand River Ave',
            'suite' => '1105',
            'city' => 'Farmington',
            'state' => 'MI',
            'zip' => '48335',
            'ein' => '43-2316091',
            'employee_count' => 20,
            'naics' => '541',
            'opt_hours' => 20,
            'hourly_rate' => '20.00',
            'salary_frequency' => 'hour',
        ])->assertRedirect();
        $this->actingAs($company->hr)->post('/i983', [
            'candidate_name' => 'Riley Quinn',
            'candidate_email' => 'riley.blank-employer@example.test',
        ])->assertRedirect();
        $link = (string) session('i983_link');
        $caseId = (int) DB::table('i983_cases')->where('tenant_id', $company->id)->value('id');
        DB::table('i983_employers')->where('case_id', $caseId)->update([
            'start_date' => '2026-06-15',
            'other_compensation' => 'Transit stipend only',
        ]);
        $role = 'Student data analyst trainee for the submitted copy.';
        $goals = 'Practice one reviewed student workflow and keep the employer section blank.';

        $this->post($link, $this->student([
            'email' => 'riley.blank-employer@example.test',
            'student_role' => $role,
            'goals' => $goals,
        ], true))->assertRedirect();

        $snapshot = DB::table('i983_documents')->where('case_id', $caseId)->where('kind', 'student_snapshot')->first();
        $bytes = Storage::disk('local')->get($snapshot->file_path);
        $this->assertSame($snapshot->sha256, hash('sha256', $bytes));
        $this->assertStringContainsString('(Quinn, Riley)', $bytes);
        $this->assertStringContainsString('('.$role.')', $bytes);
        $this->assertStringContainsString('(Practice one reviewed student workflow and keep the employer section blank.)', $bytes);
        $this->assertStringContainsString('144.00 173.90 Td (Quinn, Riley)', $bytes);
        foreach ([
            'Veltrix Digital Solutions Inc',
            '37085 Grand River Ave',
            'veltrixds.com',
            'Farmington',
            '43-2316091',
            '318.00 660.50 Td (541)',
            '20800.00',
            '06-15-2026',
            'Transit stipend only',
            'direct supervision and guidance',
        ] as $employerText) {
            $this->assertStringNotContainsString($employerText, $bytes, $employerText);
        }
        $this->assertStringNotContainsString('320.00 97.80 Td', $bytes);
        $this->assertStringNotContainsString('320.00 451.80 Td', $bytes);
        $this->assertStringNotContainsString('372.00 73.80 Td', $bytes);

        $preview = $this->actingAs($company->hr)->get('/i983/'.$caseId.'/documents/'.$snapshot->id.'/preview');
        $download = $this->actingAs($company->hr)->get('/i983/'.$caseId.'/documents/'.$snapshot->id.'/download');
        $preview->assertOk();
        $download->assertOk();
        $this->assertSame($bytes, $preview->getContent());
        $this->assertSame($bytes, $download->getContent());

        $this->actingAs($company->hr)->post('/i983/signature', [
            'signatory_name' => 'Priya Shah',
            'signatory_title' => 'HR Manager',
            'signature' => UploadedFile::fake()->image('signature.jpg', 240, 60),
        ])->assertRedirect();
        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/documents/working')->assertRedirect();
        $working = DB::table('i983_documents')->where('case_id', $caseId)->where('kind', 'hr_working')->first();
        $workingBytes = Storage::disk('local')->get($working->file_path);
        $this->assertStringContainsString('42.00 718.80 Td (Veltrix Digital Solutions Inc)', $workingBytes);
        $this->assertStringContainsString('318.00 718.80 Td (37085 Grand River Ave)', $workingBytes);
        $this->assertStringContainsString('42.00 660.50 Td (43-2316091)', $workingBytes);
        $this->assertStringContainsString('216.00 660.50 Td (20)', $workingBytes);
        $this->assertStringContainsString('318.00 660.50 Td (541)', $workingBytes);
        $this->assertStringContainsString('42.00 623.00 Td (20)', $workingBytes);
        $this->assertStringContainsString('346.00 625.70 Td (20800.00)', $workingBytes);
        $this->assertStringContainsString('42.00 593.00 Td (06-15-2026)', $workingBytes);
        $this->assertStringContainsString('(Transit stipend only)', $workingBytes);
        $this->assertStringContainsString('320.00 97.80 Td (Priya Shah, HR Manager)', $workingBytes);
        $this->assertSame($bytes, Storage::disk('local')->get($snapshot->file_path));
        $this->assertSame($snapshot->sha256, hash('sha256', $bytes));

        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/documents/student')->assertRedirect();
        $this->assertSame(1, DB::table('i983_documents')->where('case_id', $caseId)->where('kind', 'student_snapshot')->count());
        $this->assertSame($bytes, Storage::disk('local')->get($snapshot->file_path));
        $this->assertSame($snapshot->sha256, DB::table('i983_documents')->where('id', $snapshot->id)->value('sha256'));
        $this->assertSame($workingBytes, Storage::disk('local')->get($working->file_path));
        Mail::assertNothingSent();
    }

    public function test_the_workflow_keeps_the_student_working_and_final_pdfs(): void
    {
        Mail::fake();
        $company = CompanyFixture::make('Acme People', 'acme-i983-three-versions');
        $owner = $this->owner($company);
        $this->actingAs($company->hr)->post('/i983', [
            'candidate_name' => 'Riley Quinn',
            'candidate_email' => 'riley.three@example.test',
        ])->assertRedirect();
        $link = (string) session('i983_link');
        $caseId = (int) DB::table('i983_cases')->where('tenant_id', $company->id)->value('id');
        $this->post($link, $this->student([
            'email' => 'riley.three@example.test',
            'student_role' => 'Data analyst trainee',
            'goals' => 'Practice one reviewed workflow.',
        ], true))->assertRedirect();
        $student = DB::table('i983_documents')->where('case_id', $caseId)->where('kind', 'student_snapshot')->first();
        $studentBytes = Storage::disk('local')->get($student->file_path);

        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/employer', $this->employer())->assertRedirect();
        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/training', $this->training('Practice one reviewed workflow.'))->assertRedirect();
        $this->actingAs($company->hr)->post('/i983/signature', [
            'signatory_name' => 'Priya Shah',
            'signatory_title' => 'HR Manager',
            'signature' => UploadedFile::fake()->image('signature.jpg', 240, 60),
        ])->assertRedirect();
        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/documents/working')->assertRedirect();
        $firstWorking = DB::table('i983_documents')->where('case_id', $caseId)->where('kind', 'hr_working')->first();
        $firstWorkingBytes = Storage::disk('local')->get($firstWorking->file_path);
        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/training', $this->training('Practice the revised workflow.'))->assertRedirect();
        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/documents/working')->assertRedirect();
        $latestWorking = DB::table('i983_documents')->where('case_id', $caseId)->where('kind', 'hr_working')->orderByDesc('id')->first();

        $this->actingAs($company->hr)->get('/i983/'.$caseId)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('documents', fn (mixed $documents): bool => collect($documents)->pluck('kind')->all() === ['student_snapshot', 'hr_working']
                && (int) $documents[0]['id'] === (int) $student->id
                && (int) $documents[1]['id'] === (int) $latestWorking->id)
        );
        $this->assertSame($studentBytes, Storage::disk('local')->get($student->file_path));
        $this->assertSame($firstWorkingBytes, Storage::disk('local')->get($firstWorking->file_path));
        $this->assertSame($firstWorking->sha256, hash('sha256', $firstWorkingBytes));

        $this->actingAs($owner)->post('/i983/'.$caseId.'/final', ['confirm' => '1'])->assertRedirect();
        $final = DB::table('i983_documents')->where('case_id', $caseId)->where('kind', 'final_signed')->first();
        $finalBytes = Storage::disk('local')->get($final->file_path);
        $this->assertStringContainsString('(Acme People LLC)', $finalBytes);
        $this->assertStringContainsString('320.00 97.80 Td (Priya Shah, HR Manager)', $finalBytes);
        $this->assertStringNotContainsString('(Acme People LLC)', $studentBytes);
        $this->actingAs($owner)->post('/i983/'.$caseId.'/final', ['confirm' => '1'])->assertSessionHasErrors('form');
        $this->actingAs($company->hr)->get('/i983/'.$caseId)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('documents', fn (mixed $documents): bool => collect($documents)->pluck('kind')->all() === ['student_snapshot', 'hr_working', 'final_signed']
                && collect($documents)->pluck('kind')->doesntContain('employer_signed')
                && (int) $documents[0]['id'] === (int) $student->id
                && (int) $documents[2]['id'] === (int) $final->id)
        );
        $this->assertSame($studentBytes, Storage::disk('local')->get($student->file_path));
        $this->assertSame($finalBytes, Storage::disk('local')->get($final->file_path));
        $this->assertSame($final->sha256, hash('sha256', $finalBytes));
        $this->assertSame($studentBytes, $this->actingAs($company->hr)->get('/i983/'.$caseId.'/documents/'.$student->id.'/download')->getContent());
        $this->assertSame($finalBytes, $this->actingAs($company->hr)->get('/i983/'.$caseId.'/documents/'.$final->id.'/preview')->getContent());
        $this->assertSame(
            Storage::disk('local')->get($latestWorking->file_path),
            $this->actingAs($company->hr)->get('/i983/'.$caseId.'/documents/'.$latestWorking->id.'/download')->getContent()
        );
        $this->assertGreaterThan(0, DB::table('i983_documents')->where('case_id', $caseId)->where('kind', 'employer_signed')->count());
        $this->assertSame(1, DB::table('i983_documents')->where('case_id', $caseId)->where('kind', 'final_signed')->count());
        $this->assertSame(1, DB::table('i983_documents')->where('case_id', $caseId)->where('kind', 'student_snapshot')->count());
        Mail::assertNothingSent();
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
    private function training(string $goals): array
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
            'goals' => $goals,
            'oversight' => 'Weekly check-in with the hiring manager.',
            'measures' => 'Reviewed project notes each month.',
            'remarks' => 'Sample remarks for the training plan.',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function values(): array
    {
        return [
            'student_name' => 'Quinn, Riley',
            'email' => 'riley.quinn@example.test',
            'school_recommending' => 'Example State University',
            'school_degree' => 'Example State University',
            'sevis_school_code' => 'DET214F00001000',
            'dso_name' => 'Avery Cole',
            'dso_email' => 'dso@ex.edu',
            'dso_phone' => '+1 (248) 555-0199',
            'sevis_id' => 'N0000000001',
            'opt_start' => '04-06-2026',
            'opt_end' => '04-05-2028',
            'major_cip' => 'Computer Science 11.0701',
            'degree_level' => 'Master',
            'degree_awarded_on' => '05-01-2026',
            'prior_degree' => 'no',
            'ead' => 'IOE0000000001',
        ];
    }
}
