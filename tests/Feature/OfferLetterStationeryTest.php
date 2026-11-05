<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Hrms\OfferLetterPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CompanyFixture;
use Tests\TestCase;

class OfferLetterStationeryTest extends TestCase
{
    use RefreshDatabase;

    public function test_letterhead_and_signature_stay_private_and_the_preview_places_them(): void
    {
        Storage::fake('local');
        $company = CompanyFixture::make('Acme People', 'acme-stationery');
        $other = CompanyFixture::make('Other People', 'other-stationery');
        $hr = $company->hr;
        $outsider = $this->owner($other);

        $this->actingAs($company->employee)->get('/company/settings')->assertInertia(fn (Assert $page) => $page->component('hrms/forbidden'));
        $this->actingAs($company->employee)->post(route('hrms.offer-letters.letterheads.store'), [
            'country' => 'india',
            'letterhead' => UploadedFile::fake()->image('blocked.png', 40, 40),
        ])->assertNotFound();

        $this->actingAs($hr)->get('/company/settings')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('settings/company')
            ->where('payrollSettings', null)
            ->has('stationery.india')
            ->has('stationery.usa'));

        $this->actingAs($hr)->post(route('hrms.offer-letters.letterheads.store'), [
            'country' => 'india',
            'letterhead' => UploadedFile::fake()->create('badge.gif', 20, 'image/gif'),
        ])->assertSessionHasErrors('letterhead');

        $this->actingAs($hr)->post(route('hrms.offer-letters.letterheads.store'), [
            'country' => 'india',
            'letterhead' => UploadedFile::fake()->image('india.png', 90, 120),
            'margin_top' => 96,
            'margin_right' => 54,
            'margin_bottom' => 72,
            'margin_left' => 54,
            'header_height' => 80,
            'footer_height' => 40,
        ])->assertRedirect();

        $letterheadId = (int) DB::table('offer_letterheads')->where('tenant_id', $company->id)->where('country', 'india')->value('id');
        $this->assertSame('letterhead_uploaded', DB::table('company_legal_profile_events')->where('tenant_id', $company->id)->orderByDesc('id')->value('action'));

        $this->actingAs($hr)->get('/company/settings')->assertInertia(fn (Assert $page) => $page
            ->where('stationery.india.letterheads.0.imageWidth', 90)
            ->where('stationery.india.letterheads.0.imageHeight', 120)
            ->where('stationery.india.letterheads.0.status', 'active')
            ->where('stationery.usa.letterheads', []));

        $this->actingAs($outsider)->get(route('hrms.offer-letters.letterheads.preview', $letterheadId))->assertNotFound();
        $preview = $this->actingAs($hr)->get(route('hrms.offer-letters.letterheads.preview', $letterheadId));
        $preview->assertOk();
        $this->assertStringContainsString('image/png', (string) $preview->headers->get('content-type'));
        $this->assertStringStartsWith("\x89PNG", $preview->streamedContent());

        $this->actingAs($hr)->post(route('hrms.offer-letters.legal'), [
            'country' => 'india',
            'legal_name' => 'Acme People Private Limited',
            'legal_address' => 'Registered office, Hyderabad',
            'signatory_name' => 'Priya Shah',
            'signatory_designation' => 'Director',
            'hr_email' => 'hr@acme.test',
        ])->assertRedirect();
        $this->actingAs($hr)->post(route('hrms.offer-letters.signature.store'), [
            'country' => 'india',
            'signature' => UploadedFile::fake()->image('sign.png', 80, 30),
        ])->assertRedirect();
        $this->actingAs($hr)->post(route('hrms.offer-letters.signature.settings'), [
            'country' => 'india',
            'enabled' => 1,
            'width' => 140,
            'height' => 48,
            'align' => 'left',
            'gap_before' => 8,
            'gap_after' => 6,
        ])->assertRedirect();

        $pdf = $this->samplePdf($hr, $this->letter());
        $this->assertGreaterThan(1, substr_count($pdf, '/Type /Page') - substr_count($pdf, '/Type /Pages'));
        $this->assertGreaterThanOrEqual(2, substr_count($pdf, ' Do'));
        $this->assertStringContainsString('Second page wording', $this->pdfText($pdf));
        $this->assertSignatureSitsInTheClosing($pdf);

        $this->actingAs($outsider)->get(route('hrms.offer-letters.signature.show', ['country' => 'india']))->assertNotFound();
        $this->actingAs($hr)->post(route('hrms.offer-letters.signature.active'), [
            'country' => 'india',
            'active' => 0,
        ])->assertRedirect();
        $this->assertNotSame('', (string) DB::table('company_legal_profiles')->where('tenant_id', $company->id)->where('country', 'india')->value('signature_path'));
        $this->assertFalse((bool) DB::table('company_legal_profiles')->where('tenant_id', $company->id)->where('country', 'india')->value('signature_active'));
        $this->assertSame('signature_deactivated', DB::table('company_legal_profile_events')->where('tenant_id', $company->id)->orderByDesc('id')->value('action'));
        $this->actingAs($hr)->post(route('hrms.offer-letters.templates.sample-pdf'), [
            'country' => 'india',
            'body' => $this->letter(),
        ])->assertSessionHasErrors('letter');

        $this->actingAs($hr)->post(route('hrms.offer-letters.signature.settings'), [
            'country' => 'india',
            'enabled' => 0,
            'width' => 140,
            'height' => 48,
            'align' => 'left',
            'gap_before' => 8,
            'gap_after' => 6,
        ])->assertRedirect();
        $withoutSignature = $this->samplePdf($hr, $this->letter());
        $regards = strpos($withoutSignature, '(regards');
        $name = strpos($withoutSignature, '(SIGNATORYNAME)');
        $acceptance = strpos($withoutSignature, '(ACCEPTANCEBLOCK)');
        $image = $regards === false ? false : strpos($withoutSignature, ' Do', $regards);
        $this->assertNotFalse($regards);
        $this->assertNotFalse($name);
        $this->assertNotFalse($acceptance);
        $this->assertTrue($name < $acceptance);
        $this->assertTrue($image === false || $image > $acceptance);
    }

    public function test_an_issued_pdf_keeps_its_letterhead_and_signature_versions(): void
    {
        Storage::fake('local');
        $company = CompanyFixture::make('Acme People', 'acme-frozen');
        $hr = $company->hr;
        $owner = $this->owner($company);

        $this->actingAs($hr)->post(route('hrms.offer-letters.legal'), [
            'country' => 'india',
            'legal_name' => 'Acme People Private Limited',
            'legal_address' => 'Registered office, Hyderabad',
            'signatory_name' => 'Priya Shah',
            'signatory_designation' => 'Director',
            'hr_email' => 'hr@acme.test',
        ])->assertRedirect();
        $this->actingAs($owner)->post(route('hrms.offer-letters.legal.verify'), [
            'confirm' => 'VERIFY',
            'country' => 'india',
        ])->assertRedirect();
        $this->actingAs($hr)->post(route('hrms.offer-letters.letterheads.store'), [
            'country' => 'india',
            'letterhead' => UploadedFile::fake()->image('first.jpg', 80, 100),
            'margin_top' => 90,
        ])->assertRedirect();
        $firstLetterhead = (int) DB::table('offer_letterheads')->where('tenant_id', $company->id)->value('id');
        $firstPath = (string) DB::table('offer_letterheads')->where('id', $firstLetterhead)->value('source_path');
        $this->actingAs($hr)->post(route('hrms.offer-letters.signature.store'), [
            'country' => 'india',
            'signature' => UploadedFile::fake()->image('first-sign.png', 70, 24),
        ])->assertRedirect();
        $this->actingAs($hr)->post(route('hrms.offer-letters.signature.settings'), [
            'country' => 'india',
            'enabled' => 1,
            'width' => 120,
            'height' => 40,
            'align' => 'left',
            'gap_before' => 4,
            'gap_after' => 4,
        ])->assertRedirect();
        $this->actingAs($hr)->post(route('hrms.offer-letters.templates.save'), [
            'country' => 'india',
            'name' => 'India appointment',
            'body' => '<p>With warm regards,</p><p>For Acme People Private Limited</p><div data-signature="hr"></div><p>SIGNATORYNAME</p><h2>ACCEPTANCEBLOCK</h2>',
        ])->assertRedirect();
        $templateId = (int) DB::table('offer_letter_templates')->where('tenant_id', $company->id)->value('id');
        $this->actingAs($hr)->post(route('hrms.offer-letters.templates.submit', $templateId))->assertRedirect();
        $this->actingAs($owner)->post(route('hrms.offer-letters.templates.approve', $templateId), ['confirm' => 'APPROVE'])->assertRedirect();
        $this->actingAs($owner)->post(route('hrms.offer-letters.templates.activate', $templateId), ['confirm' => 'ACTIVATE'])->assertRedirect();
        $this->actingAs($hr)->post(route('hrms.offer-letters.store'), [
            'country' => 'india',
            'position_code' => 'opt_recruiter',
            'employee_id' => $company->reportEmployeeId,
            'monthly_salary' => '45000',
            'basic_salary' => '20000',
            'hra' => '8000',
            'special_allowance' => '5000',
            'joining_date' => '2026-04-01',
            'hr_name' => 'Meera Nair',
            'hr_signature' => 'Meera Nair',
        ])->assertRedirect();
        $letterId = (int) DB::table('offer_letters')->where('tenant_id', $company->id)->value('id');
        $this->actingAs($hr)->post(route('hrms.offer-letters.preview', $letterId))->assertRedirect();
        $this->actingAs($hr)->post(route('hrms.offer-letters.submit', $letterId))->assertRedirect();
        $this->actingAs($owner)->post(route('hrms.offer-letters.approve', $letterId), ['confirm' => 'APPROVE'])->assertRedirect();
        $this->actingAs($owner)->post(route('hrms.offer-letters.letterhead-confirmation'), [
            'country' => 'india',
            'confirm' => 'CONFIRM LETTERHEAD',
        ])->assertRedirect();
        $this->actingAs($hr)->post(route('hrms.offer-letters.issue', $letterId), ['confirm' => 'ISSUE'])->assertRedirect();

        $issued = DB::table('offer_letters')->where('id', $letterId)->first();
        $stored = Storage::disk('local')->get($issued->file_path);
        $this->assertStringStartsWith('%PDF', $stored);
        $this->assertSignatureSitsInTheClosing($stored);
        $frozen = json_decode((string) $issued->issued_values, true);
        $this->assertSame($firstLetterhead, (int) $frozen['letterhead_id']);
        $this->assertSame(1, (int) $frozen['signature_version']);

        $this->actingAs($hr)->post(route('hrms.offer-letters.letterheads.store'), [
            'country' => 'india',
            'letterhead' => UploadedFile::fake()->image('second.jpg', 60, 80),
        ])->assertRedirect();
        $this->actingAs($hr)->post(route('hrms.offer-letters.signature.store'), [
            'country' => 'india',
            'signature' => UploadedFile::fake()->image('second-sign.png', 90, 40),
        ])->assertRedirect();

        $this->assertSame('inactive', DB::table('offer_letterheads')->where('id', $firstLetterhead)->value('status'));
        $this->assertTrue(Storage::disk('local')->exists($firstPath));
        $this->assertSame(2, (int) DB::table('company_legal_profiles')->where('tenant_id', $company->id)->where('country', 'india')->value('signature_version'));
        $this->assertSame($stored, Storage::disk('local')->get($issued->file_path));
        $this->assertSame($stored, $this->actingAs($hr)->get(route('hrms.offer-letters.pdf', $letterId))->streamedContent());
        $this->assertSame($issued->issued_body, DB::table('offer_letters')->where('id', $letterId)->value('issued_body'));
        $this->assertSame($firstLetterhead, (int) json_decode((string) DB::table('offer_letters')->where('id', $letterId)->value('issued_values'), true)['letterhead_id']);
        $this->assertSame(1, (int) json_decode((string) DB::table('offer_letters')->where('id', $letterId)->value('issued_values'), true)['signature_version']);
    }

    public function test_editor_line_breaks_stay_on_separate_pdf_lines(): void
    {
        $pdf = (new OfferLetterPdf)->render(
            '<ol><li><p>Keep the marker with the item.</p></li></ol>'
            .'<p><strong>Work Location:</strong><br><strong>VELTRIX DIGITAL SOLUTIONS</strong><br>Flat - 1104, 11th Floor, B-Block, Asian Suncity,<br>Kondapur, Hyderabad, Telangana - 500084, India.</p>'
            .'<p>The next clause stays below the address.</p>'
            .'<p>Line one<br />'."\n".'Line two</p>',
            null,
            [],
            [
                'pageWidth' => 612,
                'pageHeight' => 792,
                'margins' => ['top' => 72, 'right' => 54, 'bottom' => 56, 'left' => 54],
            ],
        );

        $lines = $this->pdfLines($pdf);
        $line = function (string $needle) use ($lines): float {
            foreach ($lines as $row) {
                if (str_contains($row['text'], $needle)) {
                    return $row['y'];
                }
            }

            $this->fail('Missing PDF line: '.$needle);
        };

        $company = $line('VELTRIX');
        $street = $line('Asian Suncity');
        $city = $line('Kondapur');
        $next = $line('next clause');
        $this->assertGreaterThan($street, $company);
        $this->assertGreaterThan($city, $street);
        $this->assertGreaterThan($next, $city);
        $this->assertLessThan(22, $company - $street);
        $this->assertLessThan(22, $street - $city);

        $marker = $line('Keep the marker');
        $this->assertEqualsWithDelta($marker, $line('1.'), 0.2);

        $plain = $line('Line one') - $line('Line two');
        $this->assertGreaterThan(8, $plain);
        $this->assertLessThan(22, $plain);
    }

    public function test_later_pages_keep_the_letter_size_and_clear_the_footer(): void
    {
        $paragraphs = '';

        for ($line = 1; $line <= 80; $line++) {
            $paragraphs .= '<p>Clause line '.$line.' stays above the footer.</p>';
        }

        $paragraphs .= '<h2>17. Remuneration and Salary</h2><p>Your annualized salary will be 3,60,000.</p><p>Your salary will be processed according to the payroll schedule.</p>';
        $pdf = (new OfferLetterPdf)->render($paragraphs, null, [], [
            'pageWidth' => 612,
            'pageHeight' => 792,
            'headerHeight' => 96,
            'footerHeight' => 72,
            'margins' => ['top' => 110, 'right' => 54, 'bottom' => 70, 'left' => 54],
        ]);

        preg_match_all('/\/MediaBox \[0 0 ([0-9.]+) ([0-9.]+)\]/', $pdf, $boxes);
        $this->assertGreaterThan(0, count($boxes[2]));
        $this->assertStringNotContainsString('841.89', $pdf);

        foreach ($boxes[1] as $width) {
            $this->assertEqualsWithDelta(612, (float) $width, 0.2);
        }

        foreach ($boxes[2] as $height) {
            $this->assertEqualsWithDelta(792, (float) $height, 0.2);
        }

        preg_match_all('/BT\s+[0-9.]+\s+([0-9.]+)\s+Td/', $pdf, $positions);
        $this->assertNotEmpty($positions[1]);

        foreach ($positions[1] as $baseline) {
            $this->assertGreaterThan(60, (float) $baseline);
        }

        $this->assertStringContainsString('payroll schedule', $this->pdfText($pdf));
    }

    private function letter(): string
    {
        return '<p>With warm regards,</p><p>For Sample Company</p><div data-signature="hr"></div><p>SIGNATORYNAME</p><h2>ACCEPTANCEBLOCK</h2><div data-page-break="true"></div><p>Second page wording</p>';
    }

    private function samplePdf(User $hr, string $body): string
    {
        $response = $this->actingAs($hr)->post(route('hrms.offer-letters.templates.sample-pdf'), [
            'country' => 'india',
            'body' => $body,
        ]);
        $response->assertOk();
        $pdf = $response->getContent();
        $this->assertStringStartsWith('%PDF', $pdf);

        return $pdf;
    }

    private function assertSignatureSitsInTheClosing(string $pdf): void
    {
        $regards = strpos($pdf, '(regards');
        $name = strpos($pdf, '(SIGNATORYNAME)');
        $acceptance = strpos($pdf, '(ACCEPTANCEBLOCK)');
        $image = $regards === false ? false : strpos($pdf, ' Do', $regards);

        $this->assertNotFalse($regards);
        $this->assertNotFalse($name);
        $this->assertNotFalse($acceptance);
        $this->assertNotFalse($image);
        $this->assertTrue($image < $name);
        $this->assertTrue($name < $acceptance);
        $later = strpos($pdf, ' Do', $name);
        $this->assertTrue($later === false || $later > $acceptance);
    }

    public function test_signature_placement_settings_save_and_the_sample_pdf_uses_them(): void
    {
        Storage::fake('local');
        $company = CompanyFixture::make('Acme People', 'placement-save');
        $hr = $company->hr;

        $this->actingAs($hr)->post(route('hrms.offer-letters.legal'), [
            'country' => 'india',
            'legal_name' => 'Acme People Private Limited',
            'legal_address' => 'Registered office, Hyderabad',
            'signatory_name' => 'Priya Shah',
            'signatory_designation' => 'Director',
            'hr_email' => 'hr@acme.test',
        ])->assertRedirect();
        $this->actingAs($hr)->post(route('hrms.offer-letters.signature.store'), [
            'country' => 'india',
            'signature' => UploadedFile::fake()->image('sign.png', 80, 30),
        ])->assertRedirect();
        $this->actingAs($hr)->post(route('hrms.offer-letters.signature.settings'), [
            'country' => 'india',
            'enabled' => 1,
            'width' => 160,
            'height' => 40,
            'align' => 'center',
            'gap_before' => 4,
            'gap_after' => 5,
        ])->assertRedirect()->assertSessionHas('status', 'Signature placement settings saved.');

        $saved = DB::table('company_legal_profiles')->where('tenant_id', $company->id)->where('country', 'india')->first();
        $this->assertTrue((bool) $saved->signature_enabled);
        $this->assertSame(160, (int) $saved->signature_width);
        $this->assertSame(40, (int) $saved->signature_height);
        $this->assertSame('center', $saved->signature_align);
        $this->assertSame(4, (int) $saved->signature_gap_before);
        $this->assertSame(5, (int) $saved->signature_gap_after);
        $this->assertSame('draft', $saved->status);

        $this->actingAs($hr)->from(route('hrms.offer-letters.templates', ['country' => 'india']))
            ->post(route('hrms.offer-letters.signature.settings'), [
                'country' => 'india',
                'enabled' => 1,
                'width' => 10,
                'height' => 40,
                'align' => 'center',
                'gap_before' => 4,
                'gap_after' => 5,
            ])->assertSessionHasErrors('width');
        $this->assertSame(160, (int) DB::table('company_legal_profiles')->where('id', $saved->id)->value('signature_width'));

        $this->actingAs($hr)->get(route('hrms.offer-letters.templates', ['country' => 'india']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('offer-letters/india-settings')
                ->where('legal.signatureEnabled', true)
                ->where('legal.signatureWidth', 160)
                ->where('legal.signatureHeight', 40)
                ->where('legal.signatureAlign', 'center')
                ->where('legal.signatureGapBefore', 4)
                ->where('legal.signatureGapAfter', 5));

        $pdf = $this->samplePdf($hr, $this->letter());
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertSignatureSitsInTheClosing($pdf);
        $this->assertSame(0, DB::table('offer_letters')->count());
    }

    /**
     * @return list<array{y: float, text: string}>
     */
    private function pdfLines(string $pdf): array
    {
        preg_match_all('/BT\s+[0-9.]+\s+([0-9.]+)\s+Td\s*\(((?:\\\\.|[^\\\\)])*)\)\s*Tj/s', $pdf, $matches, PREG_SET_ORDER);
        $lines = [];

        foreach ($matches as $match) {
            $y = round((float) $match[1], 1);
            $text = stripcslashes($match[2]);
            $key = (string) $y;
            $lines[$key]['y'] = $y;
            $lines[$key]['text'] = ($lines[$key]['text'] ?? '').$text;
        }

        return array_values($lines);
    }

    private function pdfText(string $pdf): string
    {
        preg_match_all('/\(((?:\\\\.|[^\\\\)])*)\)\s*Tj/', $pdf, $matches);

        return implode('', array_map(fn (string $part): string => stripcslashes($part), $matches[1]));
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
