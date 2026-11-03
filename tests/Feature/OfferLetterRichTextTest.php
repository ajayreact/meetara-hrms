<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Hrms\IndiaAppointment;
use App\Services\Hrms\OfferLetterMarkup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CompanyFixture;
use Tests\TestCase;

class OfferLetterRichTextTest extends TestCase
{
    use RefreshDatabase;

    public function test_india_wording_stays_editable_html_and_formatting_reaches_the_pdf(): void
    {
        $headings = IndiaAppointment::clauseHeadings();
        $html = IndiaAppointment::html();
        $this->assertCount(22, $headings);
        $this->assertLessThan(60000, strlen($html));
        $this->assertStringContainsString('data-signature="hr"', $html);
        $this->assertStringContainsString('{{monthly_salary}}', $html);
        $this->assertStringContainsString('{{salary_in_words}}', $html);
        $this->assertStringContainsString('EMPLOYEE ACCEPTANCE', $html);
        $this->assertStringNotContainsString('PADAKANDLA', $html);
        $this->assertStringNotContainsString('<script', strtolower($html));

        foreach ($headings as $heading) {
            $this->assertStringContainsString($heading, $html);
        }

        $company = CompanyFixture::make('Acme People', 'acme-rich-letter');
        $unsafe = '<p style="font-family: Times New Roman; font-size: 16pt; text-align: center"><strong>APPOINTMENT LETTER</strong></p>'
            .'<p style="color: #1d4ed8">Dear {{employee_name}}</p>'
            .'<ul><li><p>Updated resume.</p></li></ul>'
            .'<ol type="i"><li><p>First duty.</p></li></ol>'
            .'<table><tr><th>Basic</th><td>{{basic_salary}}</td></tr></table>'
            .'<p onclick="alert(1)">Safe <strong>line</strong></p>'
            .'<script>alert(1)</script><a href="javascript:alert(1)">bad</a><a href="https://example.com">site</a>'
            .'<div data-page-break="true"></div><p>Second page wording</p>';

        $this->actingAs($company->hr)->post(route('hrms.offer-letters.templates.save'), [
            'country' => 'india',
            'name' => 'India appointment letter',
            'body' => $unsafe,
        ])->assertRedirect();

        $version = DB::table('offer_letter_template_versions')->where('tenant_id', $company->id)->first();
        $this->assertSame('draft', $version->status);
        $this->assertNull($version->approved_by);
        $this->assertStringContainsString('<strong>APPOINTMENT LETTER</strong>', (string) $version->body);
        $this->assertStringContainsString('https://example.com', (string) $version->body);
        $this->assertStringContainsString('{{employee_name}}', (string) $version->body);
        $this->assertStringNotContainsString('<script', strtolower((string) $version->body));
        $this->assertStringNotContainsString('onclick', strtolower((string) $version->body));
        $this->assertStringNotContainsString('javascript:', strtolower((string) $version->body));
        $this->assertSame($version->body, OfferLetterMarkup::sanitize((string) $version->body));

        $this->actingAs($company->hr)->post(route('hrms.offer-letters.templates.submit', $version->template_id))->assertRedirect();
        $this->actingAs($company->hr)->post(route('hrms.offer-letters.templates.approve', $version->template_id), ['confirm' => 'APPROVE'])
            ->assertSessionHasErrors('template');
        $this->assertNull(DB::table('offer_letter_template_versions')->where('id', $version->id)->value('approved_by'));

        $owner = $this->owner($company);
        $this->actingAs($owner)->post(route('hrms.offer-letters.templates.approve', $version->template_id), ['confirm' => 'APPROVE'])
            ->assertRedirect();
        $this->actingAs($owner)->post(route('hrms.offer-letters.templates.activate', $version->template_id), ['confirm' => 'ACTIVATE'])
            ->assertRedirect();
        $this->assertSame('active', DB::table('offer_letter_templates')->where('id', $version->template_id)->value('status'));

        $preview = $this->actingAs($company->hr)->post(route('hrms.offer-letters.templates.sample-pdf'), [
            'country' => 'india',
            'body' => $unsafe,
        ]);
        $preview->assertOk();
        $pdf = $preview->getContent();
        $this->assertStringStartsWith('%PDF', $pdf);
        $text = $this->pdfText($pdf);
        $this->assertStringContainsString('APPOINTMENT LETTER', $text);
        $this->assertStringContainsString('Avery Sample', $text);
        $this->assertStringContainsString('Updated resume.', $text);
        $this->assertStringContainsString('First duty.', $text);
        $this->assertStringContainsString('Basic', $text);
        $this->assertStringContainsString('500.00', $text);
        $this->assertStringContainsString('site', $text);
        $this->assertStringContainsString('Second page wording', $text);
        $this->assertStringNotContainsString('{{employee_name}}', $text);
        $this->assertStringNotContainsString('alert', $pdf);
        $this->assertStringContainsString('/BaseFont /Times-Bold', $pdf);
        $this->assertStringContainsString('/BaseFont /Helvetica-Bold', $pdf);
        $this->assertStringContainsString('0.114 0.306 0.847', $pdf);
        $this->assertGreaterThan(1, substr_count($pdf, '/Type /Page') - substr_count($pdf, '/Type /Pages'));

        $this->actingAs($company->hr)->post(route('hrms.offer-letters.templates.save'), [
            'country' => 'usa',
            'name' => 'USA offer letter',
            'body' => "Dear {{employee_name}}\n\nCompensation: {{salary}}",
        ])->assertRedirect();
        $usa = DB::table('offer_letter_template_versions')->where('country', 'usa')->value('body');
        $this->assertSame("Dear {{employee_name}}\n\nCompensation: {{salary}}", $usa);

        $usaPreview = $this->actingAs($company->hr)->post(route('hrms.offer-letters.templates.sample-pdf'), [
            'country' => 'usa',
            'body' => 'Dear {{employee_name}}',
        ]);
        $usaPreview->assertOk();
        $this->assertStringContainsString('Avery Sample', $usaPreview->getContent());
        $this->assertStringNotContainsString('{{employee_name}}', $usaPreview->getContent());
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
