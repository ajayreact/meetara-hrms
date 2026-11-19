<?php

namespace Tests\Feature;

use App\Services\Hrms\UsaOfferAppointment;
use App\Services\Hrms\UsaOfferPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CompanyFixture;
use Tests\TestCase;

class UsaOfferAppointmentTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_default_template_is_the_existing_usa_letter_and_edits_apply_only_to_new_pdfs(): void
    {
        $this->withoutVite();
        Storage::fake('local');
        $company = CompanyFixture::make('Sample Company Inc', 'usa-template');
        $indiaLetters = DB::table('offer_letters')->count();
        $offer = $this->sampleOffer();
        $profile = $this->profile();
        $expected = 'This letter is to confirm that Sample Company Inc, with Federal Employer Identification Number (EIN) 12-3456789, is delighted to offer you the position of Software Engineer at an hourly rate of $30.00 USD. You will be working 40 hours per week in a completely remote position, with a start date of 12/01/2026. This employment will be associated with your employment authorization status under your academic program at Sample University. We look forward to your contributions and are excited about the skills and expertise you will bring to our team. Employment type: Full time.';

        $this->assertSame($expected, UsaOfferAppointment::plain(UsaOfferAppointment::defaults(), $offer, $profile));
        $this->assertTrue(UsaOfferAppointment::isBuiltIn(UsaOfferAppointment::defaults()));

        $pdf = new UsaOfferPdf;
        $builtIn = $pdf->render($offer, $profile);
        $explicitDefault = $pdf->render($offer, $profile, null, null, UsaOfferAppointment::defaults());
        $this->assertSame($this->withoutPdfTimestamp($builtIn), $this->withoutPdfTimestamp($explicitDefault));

        $custom = UsaOfferAppointment::defaults();
        $custom['opening'] = 'This letter confirms the existing offer of the position of {{designation}}';
        $customPdf = $pdf->render($offer, $profile, null, null, $custom);
        $this->assertNotSame($this->withoutPdfTimestamp($builtIn), $this->withoutPdfTimestamp($customPdf));
        $this->assertStringContainsString('This letter confirms the existing offer of the position of Software Engineer', UsaOfferAppointment::plain($custom, $offer, $profile));
        $this->assertStringNotContainsString('delighted to offer you', UsaOfferAppointment::plain($custom, $offer, $profile));

        $this->actingAs($company->employee)->post('/usa-offers/settings/appointment', $custom)->assertForbidden();
        $this->actingAs($company->lead)->post('/usa-offers/settings/appointment', $custom)->assertForbidden();
        $this->actingAs($company->hr)->get('/usa-offers/settings')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('usa-offers/settings')
            ->where('tab', 'company')
            ->where('appointment.opening', UsaOfferAppointment::defaults()['opening'])
            ->where('appointmentCustomized', false)
        );

        $this->actingAs($company->hr)->post('/usa-offers/settings', $profile)->assertRedirect('/usa-offers/settings?tab=company');
        $this->actingAs($company->hr)->post('/usa-offers/settings/appointment', $custom)->assertRedirect('/usa-offers/settings?tab=letter');
        $this->actingAs($company->hr)->get('/usa-offers/settings?tab=letter')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('tab', 'letter')
            ->where('appointment.opening', $custom['opening'])
            ->where('appointment.conditions', UsaOfferAppointment::defaults()['conditions'])
            ->where('appointmentCustomized', true)
        );

        $this->actingAs($company->hr)->post('/usa-offers/settings/letterhead', [
            'letterhead' => UploadedFile::fake()->image('letterhead.png', 900, 200),
        ])->assertRedirect('/usa-offers/settings?tab=letterhead');
        $this->actingAs($company->hr)->post('/usa-offers/settings/signature', [
            'signature' => UploadedFile::fake()->image('signature.jpg', 320, 80),
        ])->assertRedirect('/usa-offers/settings?tab=signature');

        $this->actingAs($company->hr)->post('/usa-offers/settings/appointment', UsaOfferAppointment::defaults())->assertRedirect();
        $designationId = (int) DB::table('usa_offer_designations')->where('tenant_id', $company->id)->where('name', 'Software Engineer')->value('id');
        $this->actingAs($company->hr)->post('/usa-offers', [
            'designation_id' => $designationId,
            'responsibilities' => "Write software.\n",
            'candidate_name' => 'Avery Sample',
            'candidate_email' => 'avery@example.test',
            'candidate_phone' => '+1 555 0199',
            'candidate_address' => '12 Sample Road',
            'employment_type' => 'Full time',
            'pay_type' => 'hourly',
            'pay_amount' => '30.00',
            'working_hours' => '40 hours per week',
            'work_arrangement' => 'completely remote position',
            'university' => 'Sample University',
            'start_date' => '2026-12-01',
            'issue_date' => '2026-11-03',
        ])->assertRedirect();
        $offerId = (int) DB::table('usa_offers')->where('tenant_id', $company->id)->value('id');
        $this->actingAs($company->hr)->post('/usa-offers/'.$offerId.'/generate')->assertRedirect();
        $stored = (string) Storage::disk('local')->get('usa-offers/'.$company->id.'/'.$offerId.'.pdf');
        $this->assertStringStartsWith('%PDF', $stored);

        $this->actingAs($company->hr)->post('/usa-offers/settings/appointment', $custom)->assertRedirect();
        $this->assertSame($stored, Storage::disk('local')->get('usa-offers/'.$company->id.'/'.$offerId.'.pdf'));
        $this->assertSame($stored, $this->actingAs($company->hr)->get('/usa-offers/'.$offerId.'/download')->getContent());

        $second = $this->actingAs($company->hr)->post('/usa-offers', [
            'designation_id' => $designationId,
            'responsibilities' => "Write software.\n",
            'candidate_name' => 'Blake Sample',
            'candidate_email' => 'blake@example.test',
            'candidate_phone' => '+1 555 0188',
            'candidate_address' => '14 Sample Road',
            'employment_type' => 'Full time',
            'pay_type' => 'hourly',
            'pay_amount' => '30.00',
            'working_hours' => '40 hours per week',
            'work_arrangement' => 'completely remote position',
            'university' => 'Sample University',
            'start_date' => '2026-12-01',
            'issue_date' => '2026-11-03',
        ]);
        $second->assertRedirect();
        $secondId = (int) DB::table('usa_offers')->where('tenant_id', $company->id)->where('candidate_name', 'Blake Sample')->value('id');
        $this->actingAs($company->hr)->post('/usa-offers/'.$secondId.'/generate')->assertRedirect();
        $updated = (string) Storage::disk('local')->get('usa-offers/'.$company->id.'/'.$secondId.'.pdf');
        $this->assertNotSame($stored, $updated);

        $this->actingAs($company->hr)->post('/usa-offers/settings/appointment', ['opening' => str_repeat('A', 4001)])->assertSessionHasErrors('opening');
        $this->assertSame($indiaLetters, DB::table('offer_letters')->count());
    }

    private function withoutPdfTimestamp(string $pdf): string
    {
        return preg_replace('/\/CreationDate \(D:[^)]+\)/', '/CreationDate (D:STABLE)', $pdf) ?? $pdf;
    }

    /**
     * @return array<string, mixed>
     */
    private function sampleOffer(): array
    {
        return [
            'designation_name' => 'Software Engineer',
            'responsibilities' => ['Write software.'],
            'candidate_name' => 'Avery Sample',
            'candidate_email' => 'avery@example.test',
            'candidate_phone' => '+1 555 0199',
            'candidate_address' => '12 Sample Road',
            'employment_type' => 'Full time',
            'pay_type' => 'hourly',
            'pay_amount' => '30.00',
            'working_hours' => '40 hours per week',
            'work_arrangement' => 'completely remote position',
            'university' => 'Sample University',
            'start_date' => '2026-12-01',
            'issue_date' => '2026-11-03',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function profile(): array
    {
        return [
            'company_name' => 'Sample Company Inc',
            'address_line' => '100 Sample Street',
            'city_line' => 'Sample City, MI 48000',
            'country_line' => 'United States',
            'website' => 'example.test',
            'email' => 'hr@example.test',
            'phone' => '+1 555 0101',
            'e_verify' => '1000001',
            'federal_id' => '12-3456789',
            'manager_name' => 'Sample Manager',
            'manager_title' => 'HR Manager',
            'manager_email' => 'manager@example.test',
            'manager_phone' => '+1 555 0102',
            'hr_name' => 'Sample Signatory',
            'hr_title' => 'HR',
            'hr_phone' => '+1 555 0103',
        ];
    }
}
