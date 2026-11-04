<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Hrms\UsaDesignationCatalog;
use App\Services\Hrms\UsaOfferPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CompanyFixture;
use Tests\TestCase;

class UsaOfferDesignationTest extends TestCase
{
    use RefreshDatabase;

    public function test_designation_defaults_are_unique_and_populate_an_offer_snapshot(): void
    {
        $this->withoutVite();
        Storage::fake('local');
        Mail::fake();
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix-usa');
        $other = CompanyFixture::make('Other Company', 'other-usa');
        $indiaLetters = DB::table('offer_letters')->count();

        $this->actingAs($company->hr)->get('/usa-offers/settings')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('usa-offers/settings')
            ->where('designations', function ($rows): bool {
                $names = collect($rows)->pluck('name');

                return count($rows) === 36
                    && $names->unique()->count() === 36
                    && $names->contains('Software Engineer')
                    && $names->contains('.NET Full Stack Developer')
                    && $names->contains('AI/ML Engineer')
                    && collect($rows)->every(fn ($row) => count($row['responsibilities']) >= 8)
                    && collect($rows)->firstWhere('name', 'Software Engineer')['responsibilities'][0] === 'Design, develop, test and maintain scalable software applications.';
            })
        );

        $this->actingAs($company->hr)->post('/usa-offers/designations', [
            'name' => 'Data Engineer',
            'group' => 'data',
            'responsibilities' => "Design pipelines.\n",
        ])->assertSessionHasErrors('name');

        $software = DB::table('usa_offer_designations')->where('tenant_id', $company->id)->where('name', 'Software Engineer')->first();
        $analyst = DB::table('usa_offer_designations')->where('tenant_id', $company->id)->where('name', 'Data Analyst')->first();
        $this->actingAs($company->hr)->get('/usa-offers/create')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('usa-offers/form')
            ->where('designations', fn ($rows) => collect($rows)->firstWhere('name', 'Data Analyst')['responsibilities'][0] === 'Collect, clean and validate business data.')
        );

        $this->actingAs($company->hr)->post('/usa-offers/settings', $this->profile())->assertRedirect();
        $this->actingAs($company->hr)->post('/usa-offers', $this->offer((int) $software->id, [
            'Develop the assigned service.',
            'Review the assigned code.',
        ]))->assertRedirect();

        $offerId = (int) DB::table('usa_offers')->where('tenant_id', $company->id)->value('id');
        $this->assertSame('Software Engineer', DB::table('usa_offers')->where('id', $offerId)->value('designation_name'));
        $this->assertSame(['Develop the assigned service.', 'Review the assigned code.'], json_decode((string) DB::table('usa_offers')->where('id', $offerId)->value('responsibilities'), true));
        $this->assertSame(
            'Design, develop, test and maintain scalable software applications.',
            json_decode((string) DB::table('usa_offer_designations')->where('id', $software->id)->value('responsibilities'), true)[0]
        );

        $this->actingAs($company->hr)->post('/usa-offers/designations/'.$software->id, [
            'responsibilities' => "Changed default only.\n",
            'active' => 1,
        ])->assertRedirect();
        $this->assertSame(
            ['Develop the assigned service.', 'Review the assigned code.'],
            json_decode((string) DB::table('usa_offers')->where('id', $offerId)->value('responsibilities'), true)
        );
        $this->actingAs($company->hr)->post('/usa-offers/designations/'.$software->id.'/restore')->assertRedirect();
        $this->assertSame(
            'Design, develop, test and maintain scalable software applications.',
            json_decode((string) DB::table('usa_offer_designations')->where('id', $software->id)->value('responsibilities'), true)[0]
        );

        $this->actingAs($company->hr)->post('/usa-offers/'.$offerId, $this->offer((int) $analyst->id, [
            'Collect, clean and validate business data.',
            'Prepare the offer-specific dashboard note.',
        ]))->assertRedirect();
        $this->assertSame('Data Analyst', DB::table('usa_offers')->where('id', $offerId)->value('designation_name'));
        $this->assertSame(
            'Collect, clean and validate business data.',
            json_decode((string) DB::table('usa_offer_designations')->where('id', $analyst->id)->value('responsibilities'), true)[0]
        );

        $this->actingAs($company->hr)->post('/usa-offers/'.$offerId.'/generate')->assertSessionHasErrors('stationery');
        $this->assertSame('draft', DB::table('usa_offers')->where('id', $offerId)->value('status'));
        Storage::disk('local')->assertMissing('usa-offers/'.$company->id.'/'.$offerId.'.pdf');
        if (! class_exists(\Imagick::class)) {
            $this->actingAs($company->hr)->post('/usa-offers/settings/letterhead', [
                'letterhead' => UploadedFile::fake()->create('letterhead.pdf', 20, 'application/pdf'),
            ])->assertSessionHasErrors('letterhead');
        }
        $this->actingAs($company->hr)->post('/usa-offers/settings/letterhead', [
            'letterhead' => UploadedFile::fake()->image('letterhead.png', 900, 200),
        ])->assertRedirect();
        $this->actingAs($company->hr)->post('/usa-offers/settings/signature', [
            'signature' => UploadedFile::fake()->image('signature.jpg', 320, 80),
        ])->assertRedirect();
        $this->actingAs($other->hr)->get('/usa-offers/settings/letterhead')->assertNotFound();
        $this->actingAs($other->hr)->get('/usa-offers/settings/signature')->assertNotFound();
        $this->actingAs($company->hr)->get('/usa-offers/settings/letterhead')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAs($company->hr)->get('/usa-offers')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('offers.0.candidate', 'Sample Candidate')
            ->where('offers.0.email', 'sample@example.test')
            ->where('offers.0.payAmount', '20.00')
            ->where('offers.0.workingHours', '20 hours per week')
            ->where('offers.0.issueDate', '2026-10-30')
            ->where('offers.0.status', 'draft')
            ->where('stationery.letterhead', true)
            ->where('stationery.signature', true)
        );

        $preview = $this->actingAs($company->hr)->get('/usa-offers/'.$offerId.'/preview');
        $preview->assertOk();
        $this->assertStringStartsWith('%PDF', $preview->getContent());
        $this->assertSame(1, (new UsaOfferPdf)->pages($preview->getContent()));
        Storage::disk('local')->assertMissing('usa-offers/'.$company->id.'/'.$offerId.'.pdf');

        $this->actingAs($company->hr)->post('/usa-offers/'.$offerId.'/generate')->assertRedirect();
        $stored = (string) Storage::disk('local')->get('usa-offers/'.$company->id.'/'.$offerId.'.pdf');
        $this->assertStringStartsWith('%PDF', $stored);
        $this->assertSame(1, (new UsaOfferPdf)->pages($stored));
        $this->assertStringNotContainsString('public/', (string) DB::table('usa_offers')->where('id', $offerId)->value('file_path'));

        $this->actingAs($company->hr)->post('/usa-offers/designations/'.$analyst->id, [
            'responsibilities' => "A later default.\n",
            'active' => 1,
        ])->assertRedirect();
        $this->actingAs($company->hr)->post('/usa-offers/settings', ['company_name' => 'Renamed After Generation'])->assertRedirect();
        $download = $this->actingAs($company->hr)->get('/usa-offers/'.$offerId.'/download');
        $download->assertOk();
        $this->assertSame($stored, $download->getContent());
        $this->assertStringNotContainsString('Renamed After Generation', $download->getContent());
        $this->actingAs($company->hr)->post('/usa-offers/settings/letterhead', [
            'letterhead' => UploadedFile::fake()->image('replacement.png', 640, 120),
        ])->assertRedirect();
        $this->assertSame($stored, Storage::disk('local')->get('usa-offers/'.$company->id.'/'.$offerId.'.pdf'));

        $this->actingAs($company->hr)->post('/usa-offers/'.$offerId, $this->offer((int) $analyst->id, ['Changed after generation.']))
            ->assertSessionHasErrors('offer');
        $this->actingAs($this->member($company, 'Owner'))->get('/usa-offers')->assertOk();
        $this->actingAs($company->lead)->get('/usa-offers')->assertForbidden();
        $this->actingAs($company->employee)->get('/usa-offers')->assertForbidden();
        $this->actingAs($this->accounts($company))->get('/usa-offers/'.$offerId)->assertForbidden();
        $this->actingAs($other->hr)->get('/usa-offers/'.$offerId)->assertNotFound();
        $this->actingAs($other->hr)->get('/usa-offers/'.$offerId.'/download')->assertNotFound();

        $this->assertSame($indiaLetters, DB::table('offer_letters')->count());
        $this->assertFalse(Schema::hasColumn('offer_letters', 'responsibilities'));
        Mail::assertNothingSent();
        $this->assertSame(36, count(UsaDesignationCatalog::defaults()));
        $this->assertSame(36, collect(UsaDesignationCatalog::defaults())->pluck('key')->unique()->count());
    }

    public function test_long_responsibilities_continue_onto_another_page_without_dropping_the_signature(): void
    {
        $lines = [];

        for ($index = 1; $index <= 30; $index++) {
            $lines[] = 'Responsibility '.$index.' '.str_repeat('detail ', 18);
        }

        $pdf = (new UsaOfferPdf)->render([
            'designation_name' => 'AI/ML Engineer',
            'responsibilities' => $lines,
            'candidate_name' => 'Casey Sample',
            'candidate_email' => 'casey@example.test',
            'candidate_phone' => '+1 555 0100',
            'candidate_address' => '100 Sample Street',
            'pay_type' => 'hourly',
            'pay_amount' => '25.00',
            'working_hours' => '20 hours per week',
            'work_arrangement' => 'completely remote position',
            'university' => 'Sample University',
            'start_date' => '2026-02-11',
            'issue_date' => '2026-10-30',
        ], $this->profile());

        $this->assertGreaterThanOrEqual(3, (new UsaOfferPdf)->pages($pdf));

        $directory = storage_path('app/usa-offer-samples');
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        foreach ([
            'software-engineer.pdf' => ['Software Engineer', ['Design, develop, test and maintain scalable software applications.', 'Develop backend applications, REST APIs and server-side components.']],
            'data-analyst.pdf' => ['Data Analyst', ['Collect, clean and validate business data.', 'Develop reports and dashboards.']],
            'ai-ml-engineer.pdf' => ['AI/ML Engineer', ['Develop artificial intelligence and machine learning solutions.', 'Train and evaluate machine learning models.']],
        ] as $file => [$designation, $responsibilities]) {
            $bytes = (new UsaOfferPdf)->render([
                'designation_name' => $designation,
                'responsibilities' => $responsibilities,
                'candidate_name' => 'Sample Candidate',
                'candidate_email' => 'sample@example.test',
                'candidate_phone' => '+1 555 0100',
                'candidate_address' => '100 Sample Street',
                'pay_type' => 'hourly',
                'pay_amount' => '20.00',
                'working_hours' => '20 hours per week',
                'work_arrangement' => 'completely remote position',
                'university' => 'Sample University',
                'start_date' => '2026-02-11',
                'issue_date' => '2026-10-30',
            ], $this->profile());
            $this->assertSame(1, (new UsaOfferPdf)->pages($bytes));
            file_put_contents($directory.'/'.$file, $bytes);
        }
    }

    public function test_a_new_usa_letter_uses_only_the_saved_company_profile(): void
    {
        $offer = [
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
        $meetara = [
            'company_name' => 'Meetara',
            'address_line' => '12 Sample Road',
            'city_line' => 'Sample City',
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
        $pdf = new UsaOfferPdf;
        $meetaraPdf = $pdf->render($offer, $meetara);
        $profilePdf = $pdf->render($offer, $this->profile());
        $this->assertStringStartsWith('%PDF', $meetaraPdf);
        $this->assertNotSame($meetaraPdf, $profilePdf);
        $source = (string) file_get_contents(app_path('Services/Hrms/UsaOfferPdf.php'));
        $settings = (string) file_get_contents(resource_path('js/pages/usa-offers/settings.tsx'));

        foreach (['Veltrix', 'veltrix', 'Apali', '43-2316091', '3099276', 'Grand River'] as $needle) {
            $this->assertStringNotContainsString($needle, $source);
            $this->assertStringNotContainsString($needle, $settings);
        }

        $this->expectException(ValidationException::class);
        $pdf->render($offer, ['company_name' => 'Meetara', 'address_line' => '', 'federal_id' => '']);
    }

    public function test_offer_details_preview_and_pdf_require_an_authorized_existing_offer(): void
    {
        $this->withoutVite();
        Storage::fake('local');
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix-offer-access');
        $other = CompanyFixture::make('Other Company', 'other-offer-access');
        $this->actingAs($company->hr)->get('/usa-offers/settings')->assertOk();
        $this->actingAs($company->hr)->post('/usa-offers/settings', $this->profile())->assertRedirect();
        $this->actingAs($company->hr)->post('/usa-offers/settings/letterhead', [
            'letterhead' => UploadedFile::fake()->image('letterhead.png', 900, 200),
        ])->assertRedirect();
        $this->actingAs($company->hr)->post('/usa-offers/settings/signature', [
            'signature' => UploadedFile::fake()->image('signature.jpg', 320, 80),
        ])->assertRedirect();
        $designationId = (int) DB::table('usa_offer_designations')->where('tenant_id', $company->id)->where('name', 'Software Engineer')->value('id');
        $this->actingAs($company->hr)->post('/usa-offers', $this->offer($designationId, [
            'Design, develop, test and maintain scalable software applications.',
        ]))->assertRedirect();
        $offerId = (int) DB::table('usa_offers')->where('tenant_id', $company->id)->value('id');

        $this->actingAs($company->hr)->get('/usa-offers/'.$offerId)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('usa-offers/form')
            ->where('offer.candidateName', 'Sample Candidate')
            ->where('offer.designationName', 'Software Engineer')
            ->where('offer.payAmount', fn ($amount) => number_format((float) $amount, 2, '.', '') === '20.00')
            ->where('offer.workingHours', '20 hours per week')
            ->where('offer.status', 'draft')
            ->where('offer.locked', false)
        );

        $preview = $this->actingAs($company->hr)->get('/usa-offers/'.$offerId.'/preview');
        $preview->assertOk();
        $preview->assertHeader('Content-Type', 'application/pdf');
        $preview->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringStartsWith('%PDF', $preview->getContent());
        Storage::disk('local')->assertMissing('usa-offers/'.$company->id.'/'.$offerId.'.pdf');

        $this->actingAs($company->hr)->post('/usa-offers/'.$offerId.'/generate')->assertRedirect();
        $stored = (string) Storage::disk('local')->get('usa-offers/'.$company->id.'/'.$offerId.'.pdf');
        $this->assertStringStartsWith('%PDF', $stored);
        $this->assertSame('generated', DB::table('usa_offers')->where('id', $offerId)->value('status'));
        $this->assertStringStartsWith('usa-offers/'.$company->id.'/', (string) DB::table('usa_offers')->where('id', $offerId)->value('file_path'));
        $this->assertSame($stored, $this->actingAs($company->hr)->get('/usa-offers/'.$offerId.'/preview')->getContent());
        $this->actingAs($company->hr)->get('/usa-offers/'.$offerId.'/download')->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="usa-offer.pdf"');

        foreach ([$company->employee, $company->lead, $this->accounts($company)] as $user) {
            $this->actingAs($user)->get('/usa-offers/'.$offerId)->assertForbidden();
            $this->actingAs($user)->get('/usa-offers/'.$offerId.'/preview')->assertForbidden();
            $this->actingAs($user)->post('/usa-offers/'.$offerId.'/generate')->assertForbidden();
            $this->actingAs($user)->get('/usa-offers/'.$offerId.'/download')->assertForbidden();
        }

        $this->actingAs($other->hr)->get('/usa-offers/'.$offerId)->assertNotFound();
        $this->actingAs($other->hr)->get('/usa-offers/'.$offerId.'/preview')->assertNotFound();
        $this->actingAs($other->hr)->post('/usa-offers/'.$offerId.'/generate')->assertNotFound();
        $this->actingAs($company->hr)->get('/usa-offers/999999')->assertNotFound();
        $this->actingAs($company->hr)->get('/usa-offers/999999/preview')->assertNotFound();
        $this->actingAs($company->hr)->post('/usa-offers/999999/generate')->assertNotFound();
        $this->actingAs($company->hr)->get('/usa-offers/999999/download')->assertNotFound();
        $this->actingAs($company->hr)->get('/usa-offers/not-an-offer')->assertNotFound();
        auth()->logout();
        $this->get('/usa-offers/'.$offerId)->assertRedirect('/login');
        $this->get('/usa-offers/'.$offerId.'/preview')->assertRedirect('/login');
        $this->post('/usa-offers/'.$offerId.'/generate')->assertRedirect('/login');

        $this->assertLetterMarginsClear((new UsaOfferPdf)->render([
            'designation_name' => 'Software Engineer',
            'responsibilities' => array_map(
                fn (int $index): string => 'Responsibility '.$index.' covers delivery, review, and documentation for the assigned work.',
                range(1, 12),
            ),
            'candidate_name' => 'Alexandra Montgomery-Alexander',
            'candidate_email' => 'alexandra.montgomery.alexander@example.test',
            'candidate_phone' => '+1 555 0100',
            'candidate_address' => '1337 Jensen Court, Delafield, Wisconsin 53018, United States of America',
            'employment_type' => 'Contract',
            'pay_type' => 'hourly',
            'pay_amount' => '20.00',
            'working_hours' => '20 hours per week',
            'work_arrangement' => 'completely remote position',
            'university' => 'Concordia University',
            'start_date' => '2026-11-06',
            'issue_date' => '2026-11-04',
        ], $this->profile()));
        $this->assertFileExists(resource_path('fonts/LiberationSans-Regular.ttf'));
        $this->assertFileExists(resource_path('fonts/LiberationSans-Bold.ttf'));
        $this->assertStringNotContainsString('C:/Windows/Fonts', (string) file_get_contents(app_path('Services/Hrms/UsaOfferPdf.php')));
    }

    private function assertLetterMarginsClear(string $pdf): void
    {
        $this->assertGreaterThanOrEqual(2, (new UsaOfferPdf)->pages($pdf));
        $offset = 0;
        $page = 0;

        while (($start = strpos($pdf, "\xFF\xD8\xFF", $offset)) !== false) {
            $end = strpos($pdf, "\xFF\xD9", $start);
            $this->assertNotFalse($end);
            $page++;
            $image = imagecreatefromstring(substr($pdf, $start, $end - $start + 2));
            $this->assertNotFalse($image);
            $width = imagesx($image);
            $right = (int) round(566 * 2);
            $top = (int) round(80 * 2);
            $bodyBottom = (int) round(746 * 2);
            $footerCheck = (int) round(756 * 2);

            for ($y = $top; $y < $bodyBottom; $y += 2) {
                for ($x = $right; $x < $width; $x += 2) {
                    $this->assertGreaterThan(235, (imagecolorat($image, $x, $y) >> 16) & 255);
                }
            }

            for ($y = $bodyBottom; $y < min(imagesy($image), $footerCheck); $y += 2) {
                for ($x = (int) round(48 * 2); $x < $width - 80; $x += 6) {
                    $red = (imagecolorat($image, $x, $y) >> 16) & 255;
                    $this->assertGreaterThan(235, $red, 'Page '.$page.' pixel '.$x.','.$y.' red '.$red);
                }
            }

            imagedestroy($image);
            $offset = $end + 2;
        }

        $this->assertGreaterThan(0, $page);
    }

    /**
     * @param  list<string>  $responsibilities
     * @return array<string, mixed>
     */
    private function offer(int $designationId, array $responsibilities): array
    {
        return [
            'designation_id' => $designationId,
            'responsibilities' => implode("\n", $responsibilities),
            'candidate_name' => 'Sample Candidate',
            'candidate_email' => 'sample@example.test',
            'candidate_phone' => '+1 555 0100',
            'candidate_address' => '100 Sample Street',
            'pay_type' => 'hourly',
            'pay_amount' => '20.00',
            'working_hours' => '20 hours per week',
            'work_arrangement' => 'completely remote position',
            'university' => 'Sample University',
            'start_date' => '2026-02-11',
            'issue_date' => '2026-10-30',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function profile(): array
    {
        return [
            'company_name' => 'Veltrix Digital Solutions Inc',
            'address_line' => '37085 Grand River Ave',
            'city_line' => 'Suite 1105 Farmington, MI 48335',
            'country_line' => 'United States',
            'website' => 'www.veltrixds.com',
            'email' => 'hr@veltrixds.com',
            'phone' => '+1 (252) 666-8999',
            'e_verify' => '3099276',
            'federal_id' => '43-2316091',
            'manager_name' => 'Apali S',
            'manager_title' => 'HR Manager',
            'manager_email' => 'hr@veltrixds.com',
            'manager_phone' => '+1 (248) 755-3888',
            'hr_name' => 'Apali S',
            'hr_title' => 'HR Executive Manager',
            'hr_phone' => '+1 (248) 755-3888',
        ];
    }

    private function member(CompanyFixture $company, string $role): User
    {
        $user = User::factory()->create();
        $user->forceFill([
            'tenant_id' => $company->id,
            'is_platform_admin' => false,
            'invite_accepted_at' => now(),
        ])->save();
        DB::table('model_has_roles')->insert([
            'role_id' => DB::table('roles')->where('tenant_id', $company->id)->where('name', $role)->value('id'),
            'model_type' => User::class,
            'model_id' => $user->id,
            'tenant_id' => $company->id,
        ]);

        return $user->refresh();
    }

    private function accounts(CompanyFixture $company): User
    {
        DB::table('roles')->insert([
            'tenant_id' => $company->id,
            'name' => 'Accounts',
            'guard_name' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->member($company, 'Accounts');
    }
}
