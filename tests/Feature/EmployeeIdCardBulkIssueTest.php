<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Hrms\EmployeeIdCardPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CompanyFixture;
use Tests\TestCase;
use ZipArchive;

class EmployeeIdCardBulkIssueTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_preview_separates_eligible_not_ready_and_already_issued_cards(): void
    {
        $this->withoutVite();
        Storage::fake('local');
        $company = CompanyFixture::make('ID Card Bulk Preview', 'idcard-bulk-preview');
        $this->seedCompanyProfile($company, 'india');

        $readyId = $company->reportEmployeeId;
        $this->seedCardDetails($company, $readyId, 'india', 'O+', '+91-9000000101');

        $incompleteId = $company->outsideEmployeeId;
        $this->actingAs($company->hr)->post('/id-cards', [
            'employee_id' => $incompleteId,
            'country' => 'india',
            'blood_group' => 'A+',
            'emergency_contact_number' => '',
            'designation' => 'Software Engineer',
            'department' => 'Engineering',
        ])->assertRedirect();

        $issuedId = $company->leadEmployeeId;
        $this->seedCardDetails($company, $issuedId, 'india', 'B+', '+91-9000000102');
        $issuedCardId = (int) DB::table('employee_id_cards')->where('tenant_id', $company->id)->where('employee_id', $issuedId)->value('id');
        $this->actingAs($company->hr)->post('/id-cards/'.$issuedCardId.'/issue')->assertRedirect();

        $this->actingAs($company->hr)->get('/id-cards/bulk')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('id-cards/bulk')
            ->where('summary.eligible', 1)
            ->where('summary.notReady', 1)
            ->where('summary.alreadyIssued', 1)
        );

        $this->actingAs($company->hr)->post('/id-cards/bulk/preview', [
            'employee_ids' => [$readyId, $incompleteId, $issuedId, 999999],
        ])->assertRedirect('/id-cards/bulk');

        $this->actingAs($company->hr)->get('/id-cards/bulk')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('result.mode', 'preview')
            ->where('result.eligibleCount', 1)
            ->where('result.failureCount', 1)
            ->where('result.skippedCount', 2)
            ->where('result.rows', function ($rows) use ($readyId, $incompleteId, $issuedId): bool {
                $byId = collect($rows)->keyBy('employeeId');

                return $byId[$readyId]['state'] === 'eligible'
                    && $byId[$incompleteId]['state'] === 'not_ready'
                    && collect($byId[$incompleteId]['blockers'])->contains(fn ($item) => str_contains((string) $item, 'Emergency'))
                    && $byId[$issuedId]['state'] === 'already_issued'
                    && $byId[999999]['state'] === 'missing';
            })
        );
    }

    public function test_bulk_issue_zip_handles_partial_failures_unique_qr_and_tenant_isolation(): void
    {
        $this->withoutVite();
        Storage::fake('local');
        abort_unless(class_exists(ZipArchive::class), 503);
        $company = CompanyFixture::make('ID Card Bulk Zip', 'idcard-bulk-zip');
        $other = CompanyFixture::make('Other ID Card Bulk Zip', 'other-idcard-bulk-zip');
        $this->seedCompanyProfile($company, 'india');

        $readyA = $company->reportEmployeeId;
        $readyB = $company->outsideEmployeeId;
        $incomplete = $company->leadEmployeeId;
        $this->seedCardDetails($company, $readyA, 'india', 'O+', '+91-9000000201');
        $this->seedCardDetails($company, $readyB, 'india', 'A-', '+91-9000000202');
        $this->actingAs($company->hr)->post('/id-cards', [
            'employee_id' => $incomplete,
            'country' => 'india',
            'blood_group' => 'AB+',
            'designation' => 'Software Engineer',
            'department' => 'Engineering',
        ])->assertRedirect();

        $tokenBefore = DB::table('employee_id_cards')->where('tenant_id', $company->id)->pluck('qr_token');
        $this->assertSame($tokenBefore->count(), $tokenBefore->unique()->count());

        $this->actingAs($company->hr)->post('/id-cards/bulk', [
            'employee_ids' => [$readyA, $readyB, $incomplete, $other->reportEmployeeId, $readyA],
            'format' => 'zip',
        ])->assertRedirect('/id-cards/bulk');

        $bulkResult = session('idCardBulkResult');
        $this->assertIsArray($bulkResult);
        $this->assertSame('issue', $bulkResult['mode']);
        $this->assertSame(2, $bulkResult['successCount']);
        $this->assertSame(1, $bulkResult['failureCount']);
        $this->assertSame(1, $bulkResult['skippedCount']);
        $this->assertSame('zip', $bulkResult['format']);
        $downloadUrl = $bulkResult['downloadUrl'];
        $this->assertIsString($downloadUrl);
        $this->assertStringStartsWith('/id-cards/bulk/download/', $downloadUrl);

        $issued = DB::table('employee_id_cards')
            ->where('tenant_id', $company->id)
            ->where('status', 'active')
            ->get();
        $this->assertCount(2, $issued);
        $this->assertSame(2, $issued->pluck('qr_token')->unique()->count());
        $this->assertSame(0, DB::table('employee_id_cards')->where('tenant_id', $other->id)->where('status', 'active')->count());
        $this->assertSame(1, DB::table('employee_id_cards')->where('tenant_id', $company->id)->where('employee_id', $readyA)->where('status', 'active')->count());

        $token = basename($downloadUrl);
        Storage::disk('local')->assertExists('id-cards/'.$company->id.'/bulk/'.$token.'.zip');

        $zipResponse = $this->actingAs($company->hr)->get($downloadUrl);
        $zipResponse->assertOk();
        $zipResponse->assertHeader('Content-Type', 'application/zip');
        $zipBytes = $zipResponse->streamedContent();
        $temp = tempnam(sys_get_temp_dir(), 'idcard-bulk-test-');
        file_put_contents($temp, $zipBytes);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($temp) === true);
        $this->assertSame(2, $zip->numFiles);
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            $this->assertStringEndsWith('-id-card.pdf', (string) $name);
            $contents = $zip->getFromIndex($index);
            $this->assertIsString($contents);
            $this->assertStringStartsWith('%PDF', $contents);
            $this->assertSame(2, (new EmployeeIdCardPdf)->pages($contents));
        }
        $zip->close();
        @unlink($temp);

        $this->actingAs($other->hr)->get($downloadUrl)->assertNotFound();
        $this->actingAs($company->employee)->get('/id-cards/bulk')->assertForbidden();
        $this->actingAs($company->lead)->post('/id-cards/bulk', [
            'employee_ids' => [$readyA],
            'format' => 'zip',
        ])->assertForbidden();
        $this->actingAs($other->hr)->post('/id-cards/bulk', [
            'employee_ids' => [$readyA],
            'format' => 'zip',
        ])->assertRedirect();
        $this->assertSame(0, DB::table('employee_id_cards')->where('tenant_id', $other->id)->where('status', 'active')->count());
    }

    public function test_bulk_combined_pdf_and_skips_duplicate_active_issues(): void
    {
        $this->withoutVite();
        Storage::fake('local');
        $company = CompanyFixture::make('ID Card Bulk Combined', 'idcard-bulk-combined');
        $this->seedCompanyProfile($company, 'usa');

        $first = $company->reportEmployeeId;
        $second = $company->outsideEmployeeId;
        $this->seedCardDetails($company, $first, 'usa', 'O+', '+1-555-0201');
        $this->seedCardDetails($company, $second, 'usa', 'B+', '+1-555-0202');

        $this->actingAs($company->hr)->post('/id-cards/bulk', [
            'employee_ids' => [$first, $second],
            'format' => 'combined',
        ])->assertRedirect('/id-cards/bulk');

        $bulkResult = session('idCardBulkResult');
        $this->assertIsArray($bulkResult);
        $this->assertSame(2, $bulkResult['successCount']);
        $this->assertSame('combined', $bulkResult['format']);
        $downloadUrl = $bulkResult['downloadUrl'];
        $this->assertIsString($downloadUrl);
        $token = basename($downloadUrl);
        Storage::disk('local')->assertExists('id-cards/'.$company->id.'/bulk/'.$token.'.pdf');

        $combined = $this->actingAs($company->hr)->get($downloadUrl);
        $combined->assertOk();
        $combined->assertHeader('Content-Type', 'application/pdf');
        $bytes = $combined->streamedContent();
        $this->assertStringStartsWith('%PDF', $bytes);
        $this->assertSame(4, (new EmployeeIdCardPdf)->pages($bytes));

        $issuesBefore = DB::table('employee_id_card_issues')->where('tenant_id', $company->id)->count();
        $this->actingAs($company->hr)->post('/id-cards/bulk', [
            'employee_ids' => [$first, $second],
            'format' => 'combined',
        ])->assertRedirect('/id-cards/bulk');
        $this->actingAs($company->hr)->get('/id-cards/bulk')->assertInertia(fn (Assert $page) => $page
            ->where('result.successCount', 0)
            ->where('result.skippedCount', 2)
            ->where('result.downloadUrl', null)
        );
        $this->assertSame($issuesBefore, DB::table('employee_id_card_issues')->where('tenant_id', $company->id)->count());
        $this->assertSame(2, DB::table('employee_id_cards')->where('tenant_id', $company->id)->where('status', 'active')->count());

        $cardId = (int) DB::table('employee_id_cards')->where('tenant_id', $company->id)->where('employee_id', $first)->value('id');
        $this->actingAs($company->hr)->post('/id-cards/'.$cardId.'/reissue')->assertRedirect();
        $this->assertSame(1, DB::table('employee_id_cards')->where('tenant_id', $company->id)->where('employee_id', $first)->where('status', 'active')->count());
    }

    private function seedCompanyProfile(CompanyFixture $company, string $country): void
    {
        $this->actingAs($company->hr)->post('/id-cards/settings', [
            'country' => $country,
            'legal_name' => $country === 'india' ? 'Veltrix India Pvt Ltd' : 'Veltrix Digital Solutions Inc',
            'display_name' => $country === 'india' ? 'Veltrix India' : 'Veltrix USA',
            'office_address' => $country === 'india' ? 'Hyderabad' : 'Farmington',
            'contact_number' => $country === 'india' ? '+91-40-00000000' : '+1-248-000-0000',
            'email' => $country.'@veltrix.test',
            'website' => 'https://veltrix.test',
            'footer_address' => $country === 'india' ? 'Hyderabad, India' : 'Farmington, MI, USA',
            'logo' => UploadedFile::fake()->image($country.'-logo.png', 120, 120),
        ])->assertRedirect();
    }

    private function seedCardDetails(CompanyFixture $company, int $employeeId, string $country, string $blood, string $emergency): void
    {
        $this->actingAs($company->hr)->post('/id-cards', [
            'employee_id' => $employeeId,
            'country' => $country,
            'blood_group' => $blood,
            'emergency_contact_number' => $emergency,
            'designation' => 'Software Engineer',
            'department' => 'Engineering',
            'photo' => UploadedFile::fake()->image('badge-'.$employeeId.'.png', 180, 220),
        ])->assertRedirect();
    }
}
