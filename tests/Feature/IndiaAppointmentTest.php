<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Hrms\IndiaAppointment;
use App\Services\Hrms\OfferLetterPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use setasign\Fpdi\Fpdi;
use Tests\Support\CompanyFixture;
use Tests\TestCase;

class IndiaAppointmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_india_source_loads_all_clauses_as_an_unapproved_draft(): void
    {
        $company = CompanyFixture::make('Acme People', 'acme-india-letter');
        $headings = IndiaAppointment::clauseHeadings();
        $this->assertCount(22, $headings);
        $this->assertSame('Acceptance and Commencement of Employment', $headings[0]);
        $this->assertSame('Remuneration and Salary', $headings[16]);
        $this->assertSame('Acceptance of Appointment', $headings[21]);
        $json = IndiaAppointment::json();
        $this->assertStringContainsString('2.1', $json);
        $this->assertStringContainsString('Outside Employment', $json);
        $this->assertStringContainsString('{{monthly_salary}}', $json);
        $this->assertStringContainsString('{{salary_in_words}}', $json);
        $this->assertStringContainsString('With warm regards,', $json);
        $this->assertStringContainsString('EMPLOYEE ACCEPTANCE', $json);
        $this->assertStringNotContainsString('PADAKANDLA', $json);
        $this->assertStringNotContainsString('VELTRIX', $json);
        $this->assertStringNotContainsString('OPT', $json);
        $this->assertStringNotContainsString('STEM', $json);
        $this->assertStringNotContainsString('U.S.', $json);
        $this->assertStringContainsString('{{salutation_line}}', $json);

        $before = DB::table('offer_letters')->count();
        $this->actingAs($company->hr)->post(route('hrms.offer-letters.templates.india-source'))->assertRedirect();
        $version = DB::table('offer_letter_template_versions')->where('tenant_id', $company->id)->first();
        $this->assertSame('draft', $version->status);
        $this->assertNull($version->approved_by);
        $this->assertStringContainsString('Protection of Company Information and Monthly Recruitment Targets', (string) $version->body);
        $this->assertSame($before, DB::table('offer_letters')->count());

        $this->actingAs($company->hr)->post(route('hrms.offer-letters.templates.submit', $version->template_id))->assertRedirect();
        $this->actingAs($company->hr)->post(route('hrms.offer-letters.templates.approve', $version->template_id), ['confirm' => 'APPROVE'])
            ->assertSessionHasErrors('template');
    }

    public function test_pdf_letterhead_keeps_its_page_size_and_a_completed_letter_is_not_reused(): void
    {
        Storage::fake('local');
        $company = CompanyFixture::make('Acme People', 'acme-letterhead');
        $clean = $this->pdf(['CLEANHEADER'], [612, 792]);
        $this->actingAs($company->hr)->post(route('hrms.offer-letters.letterheads.store'), [
            'country' => 'india',
            'letterhead' => UploadedFile::fake()->createWithContent('clean.pdf', $clean),
            'margin_top' => 110,
            'margin_right' => 54,
            'margin_bottom' => 70,
            'margin_left' => 54,
            'page_width' => 612,
            'page_height' => 792,
        ])->assertRedirect();

        $row = DB::table('offer_letterheads')->where('tenant_id', $company->id)->first();
        $this->assertSame('Letter', $row->page_size);
        $this->assertEquals(612, (float) $row->page_width);

        $preview = $this->actingAs($company->hr)->post(route('hrms.offer-letters.templates.sample-pdf'), [
            'country' => 'india',
            'body' => json_encode([
                'kind' => 'appointment',
                'blocks' => [
                    ['type' => 'paragraph', 'text' => 'Sample wording for Avery'],
                    ['type' => 'break'],
                    ['type' => 'paragraph', 'text' => 'Second page wording'],
                ],
            ], JSON_THROW_ON_ERROR),
        ]);
        $preview->assertOk();
        $this->assertStringStartsWith('%PDF', $preview->getContent());
        $this->assertStringContainsString('Sample wording for Avery', $preview->getContent());
        $this->assertStringContainsString('612', $preview->getContent());
        $this->assertStringContainsString('792', $preview->getContent());
        $this->assertGreaterThan(1, substr_count($preview->getContent(), '/Type /Page') - substr_count($preview->getContent(), '/Type /Pages'));
        $this->assertSame(0, DB::table('offer_letters')->where('tenant_id', $company->id)->count());

        $completed = $this->pdf(['PAGEONE', 'PAGETWO'], [612, 792]);
        $this->actingAs($company->hr)->post(route('hrms.offer-letters.letterheads.store'), [
            'country' => 'india',
            'letterhead' => UploadedFile::fake()->createWithContent('completed.pdf', $completed),
        ])->assertRedirect();
        $page = $this->actingAs($company->hr)->get(route('hrms.offer-letters.templates', ['country' => 'india']));
        $page->assertOk();
        $page->assertSee('not a single-page blank letterhead');
        $again = $this->actingAs($company->hr)->post(route('hrms.offer-letters.templates.sample-pdf'), [
            'country' => 'india',
            'body' => 'Hello from the blank page',
        ]);
        $again->assertOk();
        $this->assertStringNotContainsString('PAGETWO', $again->getContent());
    }

    public function test_signature_placement_is_private_versioned_and_not_on_the_employee_line(): void
    {
        Storage::fake('local');
        $company = CompanyFixture::make('Acme People', 'acme-signature');
        $owner = $this->owner($company);
        $this->actingAs($company->employee)->post(route('hrms.offer-letters.signature.store'), [
            'signature' => UploadedFile::fake()->image('hr.png'),
        ])->assertNotFound();
        $this->actingAs($company->hr)->post(route('hrms.offer-letters.signature.store'), [
            'signature' => UploadedFile::fake()->create('huge.png', 3000, 'image/png'),
        ])->assertSessionHasErrors('signature');

        $this->actingAs($company->hr)->post(route('hrms.offer-letters.legal'), [
            'legal_name' => 'Acme People Private Limited',
            'legal_address' => 'Registered office, Hyderabad',
            'signatory_name' => 'Priya Shah',
            'signatory_designation' => 'Director',
            'hr_email' => 'hr@acme.test',
        ])->assertRedirect();
        $this->actingAs($company->hr)->post(route('hrms.offer-letters.signature.store'), [
            'signature' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
        ])->assertSessionHasErrors('signature');
        $png = $this->png(200, 40);
        $this->actingAs($company->hr)->post(route('hrms.offer-letters.signature.store'), [
            'signature' => $png,
        ])->assertRedirect();
        $this->actingAs($company->hr)->post(route('hrms.offer-letters.signature.settings'), [
            'enabled' => 1,
            'width' => 100,
            'height' => 40,
            'align' => 'left',
            'gap_before' => 4,
            'gap_after' => 4,
        ])->assertRedirect();

        $profile = DB::table('company_legal_profiles')->where('tenant_id', $company->id)->first();
        $this->assertSame(1, (int) $profile->signature_version);
        $this->assertTrue((bool) $profile->signature_active);
        $this->assertStringStartsWith('offer-signatures/'.$company->id.'/', (string) $profile->signature_path);
        $this->assertTrue(Storage::disk('local')->exists($profile->signature_path));
        $this->actingAs($company->hr)->get(route('hrms.offer-letters.templates', ['country' => 'india']))
            ->assertOk()
            ->assertDontSee('offer-signatures/', false);
        $this->actingAs($company->hr)->get(route('hrms.offer-letters.signature.show'))->assertOk();
        $this->actingAs($company->employee)->get(route('hrms.offer-letters.signature.show'))->assertNotFound();

        $path = Storage::disk('local')->path($profile->signature_path);
        $pdf = (new OfferLetterPdf)->render($this->closingDocument(), null, ['top' => 110, 'right' => 54, 'bottom' => 70, 'left' => 54], [
            'pageWidth' => 612,
            'pageHeight' => 792,
            'signature' => [
                'enabled' => true,
                'path' => $path,
                'maxWidth' => 100,
                'maxHeight' => 40,
                'align' => 'left',
                'before' => 4,
                'after' => 4,
                'version' => 1,
            ],
        ]);
        $this->assertMatchesRegularExpression('/100(?:\.0+)? 0 0 20(?:\.0+)? 54(?:\.0+)? /', $pdf);
        $regards = strpos($pdf, 'With warm regards');
        $name = strpos($pdf, 'Priya Shah');
        $acceptance = strpos($pdf, 'EMPLOYEE ACCEPTANCE');
        $employeeLine = strpos($pdf, 'Signature:');
        $this->assertNotFalse($regards);
        $this->assertNotFalse($name);
        $this->assertNotFalse($acceptance);
        $this->assertTrue($regards < $name);
        $this->assertTrue($name < $acceptance);
        $this->assertTrue($acceptance < $employeeLine);
        $this->assertSame(1, substr_count($pdf, '/Subtype /Image'));
        $this->assertGreaterThan(1, substr_count($pdf, '/Type /Page') - substr_count($pdf, '/Type /Pages'));

        $this->actingAs($owner)->post(route('hrms.offer-letters.legal.verify'), ['confirm' => 'VERIFY'])->assertRedirect();
        $this->actingAs($company->hr)->post(route('hrms.offer-letters.templates.save'), [
            'country' => 'india',
            'name' => 'India appointment letter',
            'body' => $this->closingDocument('{{signatory_name}}'),
        ])->assertRedirect();
        $templateId = (int) DB::table('offer_letter_templates')->where('tenant_id', $company->id)->value('id');
        $this->actingAs($company->hr)->post(route('hrms.offer-letters.templates.submit', $templateId))->assertRedirect();
        $this->actingAs($owner)->post(route('hrms.offer-letters.templates.approve', $templateId), ['confirm' => 'APPROVE'])->assertRedirect();
        $this->actingAs($owner)->post(route('hrms.offer-letters.templates.activate', $templateId), ['confirm' => 'ACTIVATE'])->assertRedirect();
        $this->actingAs($company->hr)->post(route('hrms.offer-letters.store'), [
            'country' => 'india',
            'position_code' => 'opt_recruiter',
            'employee_id' => $company->reportEmployeeId,
            'monthly_salary' => '25000',
            'joining_date' => '2026-11-02',
            'notice_months' => 3,
            'probation_months' => 3,
            'work_mode' => 'Work From Office',
            'working_hours' => '6:30 PM to 3:30 AM (IST)',
            'hr_name' => 'Priya Shah',
            'hr_signature' => 'Priya Shah',
        ])->assertRedirect();
        $letterId = (int) DB::table('offer_letters')->where('tenant_id', $company->id)->value('id');
        $this->actingAs($company->hr)->post(route('hrms.offer-letters.preview', $letterId))->assertRedirect();
        $this->actingAs($company->hr)->post(route('hrms.offer-letters.submit', $letterId))->assertRedirect();
        $this->actingAs($owner)->post(route('hrms.offer-letters.approve', $letterId), ['confirm' => 'APPROVE'])->assertRedirect();
        $this->actingAs($company->hr)->post(route('hrms.offer-letters.issue', $letterId), ['confirm' => 'ISSUE'])->assertRedirect();
        $issued = DB::table('offer_letters')->where('id', $letterId)->first();
        $hash = md5((string) Storage::disk('local')->get($issued->file_path));
        $this->assertStringContainsString('"signature_version":1', (string) $issued->issued_values);

        $this->actingAs($company->hr)->post(route('hrms.offer-letters.signature.store'), [
            'signature' => $this->png(180, 30),
        ])->assertRedirect();
        $this->assertSame(2, (int) DB::table('company_legal_profiles')->where('tenant_id', $company->id)->value('signature_version'));
        $this->assertSame($hash, md5((string) Storage::disk('local')->get($issued->file_path)));
        $this->assertSame((string) $issued->issued_body, (string) DB::table('offer_letters')->where('id', $letterId)->value('issued_body'));

        $this->actingAs($company->hr)->post(route('hrms.offer-letters.signature.remove'))->assertRedirect();
        $this->actingAs($company->hr)->post(route('hrms.offer-letters.templates.sample-pdf'), [
            'country' => 'india',
            'body' => $this->closingDocument(),
        ])->assertSessionHasErrors('letter');
        $this->assertSame($hash, md5((string) Storage::disk('local')->get($issued->file_path)));
    }

    public function test_turning_signature_placement_off_allows_issue_without_an_image(): void
    {
        Storage::fake('local');
        $company = CompanyFixture::make('Acme People', 'acme-no-signature');
        $owner = $this->owner($company);
        $this->actingAs($company->hr)->post(route('hrms.offer-letters.legal'), [
            'legal_name' => 'Acme People Private Limited',
            'legal_address' => 'Registered office, Hyderabad',
            'signatory_name' => 'Priya Shah',
            'signatory_designation' => 'Director',
            'hr_email' => 'hr@acme.test',
        ])->assertRedirect();
        $this->actingAs($owner)->post(route('hrms.offer-letters.legal.verify'), ['confirm' => 'VERIFY'])->assertRedirect();
        $this->actingAs($company->hr)->post(route('hrms.offer-letters.signature.settings'), [
            'enabled' => 0,
            'width' => 140,
            'height' => 48,
            'align' => 'left',
            'gap_before' => 8,
            'gap_after' => 6,
        ])->assertRedirect();
        $document = json_decode($this->closingDocument('{{signatory_name}}'), true);
        array_splice($document['blocks'], 1, 0, [[
            'type' => 'salary',
            'lines' => [
                'Your monthly salary will be {{monthly_salary}} ({{salary_in_words}}).',
                'Your annualized salary will be {{annual_ctc}} ({{annual_ctc_in_words}}).',
            ],
            'notes' => [],
        ]]);
        $this->actingAs($company->hr)->post(route('hrms.offer-letters.templates.save'), [
            'country' => 'india',
            'name' => 'India appointment letter',
            'body' => json_encode($document),
        ])->assertRedirect();
        $templateId = (int) DB::table('offer_letter_templates')->where('tenant_id', $company->id)->value('id');
        $this->actingAs($company->hr)->post(route('hrms.offer-letters.templates.submit', $templateId))->assertRedirect();
        $this->actingAs($owner)->post(route('hrms.offer-letters.templates.approve', $templateId), ['confirm' => 'APPROVE'])->assertRedirect();
        $this->actingAs($owner)->post(route('hrms.offer-letters.templates.activate', $templateId), ['confirm' => 'ACTIVATE'])->assertRedirect();
        $this->actingAs($company->hr)->post(route('hrms.offer-letters.store'), [
            'country' => 'india',
            'position_code' => 'opt_recruiter',
            'employee_id' => $company->reportEmployeeId,
            'monthly_salary' => '25000',
            'joining_date' => '2026-11-02',
            'work_mode' => 'Work From Office',
            'working_hours' => '6:30 PM to 3:30 AM (IST)',
            'hr_name' => 'Priya Shah',
            'hr_signature' => 'Priya Shah',
        ])->assertRedirect();
        $letterId = (int) DB::table('offer_letters')->where('tenant_id', $company->id)->value('id');
        $this->actingAs($company->hr)->post(route('hrms.offer-letters.preview', $letterId))->assertRedirect();
        $preview = (string) DB::table('offer_letters')->where('id', $letterId)->value('preview_body');
        $this->assertStringContainsString('Rupees Twenty-Five Thousand Only.', $preview);
        $this->assertStringContainsString('Rupees Three Lakh Only.', $preview);
        $this->assertStringNotContainsString('{{', $preview);
        $this->actingAs($company->hr)->post(route('hrms.offer-letters.submit', $letterId))->assertRedirect();
        $this->actingAs($owner)->post(route('hrms.offer-letters.approve', $letterId), ['confirm' => 'APPROVE'])->assertRedirect();
        $this->actingAs($company->hr)->post(route('hrms.offer-letters.issue', $letterId), ['confirm' => 'ISSUE'])->assertRedirect();
        $file = (string) DB::table('offer_letters')->where('id', $letterId)->value('file_path');
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($file));
        $this->assertStringNotContainsString('/Subtype /Image', Storage::disk('local')->get($file));
    }

    private function closingDocument(string $signatory = 'Priya Shah'): string
    {
        return json_encode([
            'kind' => 'appointment',
            'blocks' => [
                ['type' => 'paragraph', 'text' => 'Opening page'],
                ['type' => 'break'],
                ['type' => 'closing', 'lines' => ['With warm regards,', 'For Acme People Private Limited', $signatory, 'Director']],
                ['type' => 'acceptance', 'heading' => 'EMPLOYEE ACCEPTANCE', 'body' => 'I accept the appointment.', 'lines' => [
                    'Employee Name: Avery Sample',
                    'Signature: __________________________',
                    'Date: _______________________________',
                    'Date of Joining: November 02, 2026',
                ]],
            ],
        ], JSON_UNESCAPED_SLASHES);
    }

    public function test_india_and_usa_legal_details_stay_separate_and_the_sample_png_letterhead_is_used(): void
    {
        Storage::fake('local');
        $company = CompanyFixture::make('Acme People', 'acme-letterhead-png');
        $source = base_path('tests/sample-letter-head/Letter Head-India - Empty.png');
        $this->assertFileExists($source);

        $this->actingAs($company->hr)->post(route('hrms.offer-letters.legal'), [
            'country' => 'india',
            'legal_name' => 'Veltrix Digital Solutions',
            'legal_address' => 'Kondapur, Hyderabad',
            'signatory_name' => 'Naga Raju',
            'signatory_designation' => 'HR Manager',
            'hr_email' => 'hr.india@example.com',
        ])->assertRedirect();
        $this->actingAs($company->hr)->post(route('hrms.offer-letters.legal'), [
            'country' => 'usa',
            'legal_name' => 'Veltrix Digital Solutions Inc',
            'legal_address' => 'Sample US office',
            'signatory_name' => 'Alex Morgan',
            'signatory_designation' => 'HR Manager',
            'hr_email' => 'hr.usa@example.com',
        ])->assertRedirect();

        $this->actingAs($company->hr)->get(route('hrms.offer-letters.templates', ['country' => 'india']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('legal.legalName', 'Veltrix Digital Solutions')
                ->where('legal.legalAddress', 'Kondapur, Hyderabad')
                ->where('legal.hasSignature', false)
                ->where('letterheads', []));
        $this->actingAs($company->hr)->get(route('hrms.offer-letters.templates', ['country' => 'usa']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('legal.legalName', 'Veltrix Digital Solutions Inc')
                ->where('legal.legalAddress', 'Sample US office')
                ->where('legal.hasSignature', false));

        $upload = new UploadedFile($source, 'Letter Head-India - Empty.png', 'image/png', null, true);
        $this->actingAs($company->hr)->post(route('hrms.offer-letters.letterheads.store'), [
            'country' => 'india',
            'letterhead' => $upload,
            'page_width' => 595,
            'page_height' => 842,
        ])->assertRedirect();
        $this->assertFileExists($source);

        $row = DB::table('offer_letterheads')->where('tenant_id', $company->id)->where('country', 'india')->where('status', 'active')->first();
        $this->assertSame('612.00', number_format((float) $row->page_width, 2, '.', ''));
        $this->assertSame('792.00', number_format((float) $row->page_height, 2, '.', ''));
        $this->assertNotNull($row->background_path);
        $this->actingAs($company->hr)->get(route('hrms.offer-letters.templates', ['country' => 'india']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('letterheads.0.mime', 'image/png')
                ->where('letterheads.0.imageWidth', 2550)
                ->where('letterheads.0.imageHeight', 3300)
                ->where('letterheads.0.backgroundReady', true)
                ->where('letterheads.0.pageWidth', 612)
                ->where('letterheads.0.pageHeight', 792));
        $this->actingAs($company->hr)->get(route('hrms.offer-letters.letterheads.preview', $row->id))
            ->assertOk()
            ->assertHeader('content-type', 'image/png');

        $pdf = (new OfferLetterPdf)->render('Appointment preview', Storage::disk('local')->path($row->background_path), ['top' => 110, 'right' => 54, 'bottom' => 70, 'left' => 54], [
            'pageWidth' => (float) $row->page_width,
            'pageHeight' => (float) $row->page_height,
        ]);
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertStringContainsString('/Subtype /Image', $pdf);
        $this->assertMatchesRegularExpression('/MediaBox \[0 0 612(?:\.0+)? 792(?:\.0+)?\]/', $pdf);

        $this->actingAs($company->hr)->post(route('hrms.offer-letters.signature.store'), [
            'country' => 'india',
            'signature' => $this->png(180, 40),
        ])->assertRedirect();
        $this->actingAs($company->hr)->get(route('hrms.offer-letters.templates', ['country' => 'india']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('legal.hasSignature', true));
        $this->actingAs($company->hr)->get(route('hrms.offer-letters.signature.show', ['country' => 'india']))
            ->assertOk()
            ->assertHeader('content-type', 'image/png');
        $this->actingAs($company->hr)->get(route('hrms.offer-letters.templates', ['country' => 'usa']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('legal.hasSignature', false));
    }

    private function png(int $width, int $height): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);
        $white = imagecolorallocate($image, 255, 255, 255);
        $ink = imagecolorallocate($image, 20, 20, 20);
        imagefilledrectangle($image, 0, 0, $width, $height, $white);
        imagefilledrectangle($image, 4, (int) ($height / 2), $width - 4, (int) ($height / 2) + 2, $ink);
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return UploadedFile::fake()->createWithContent('signature.png', $bytes);
    }

    /**
     * @param  list<string>  $pages
     * @param  array{0: int, 1: int}  $size
     */
    private function pdf(array $pages, array $size): string
    {
        $pdf = new Fpdi('P', 'pt', $size);
        $pdf->SetCompression(false);
        $pdf->SetFont('Helvetica', '', 12);

        foreach ($pages as $text) {
            $pdf->AddPage('P', $size);
            $pdf->Text(72, 40, $text);
        }

        return $pdf->Output('S');
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
}
