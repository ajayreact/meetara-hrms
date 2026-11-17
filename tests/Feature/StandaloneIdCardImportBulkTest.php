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

class StandaloneIdCardImportBulkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_excel_preview_validates_duplicates_and_imports_drafts_without_photos_or_employees(): void
    {
        Storage::fake('local');
        $company = CompanyFixture::make('Standalone Import Co', 'standalone-import');
        $other = CompanyFixture::make('Other Standalone Import Co', 'other-standalone-import');
        $employeeSnapshot = $this->employeesSnapshot($company->id);

        $this->seedCompanyProfile($company, 'india');
        $this->seedCompanyProfile($company, 'usa');

        DB::table('standalone_id_cards')->insert([
            'tenant_id' => $company->id,
            'country' => 'india',
            'full_name' => 'Existing Card',
            'card_number' => 'STC-EXIST',
            'designation' => 'Ops',
            'department' => 'Ops',
            'blood_group' => 'O+',
            'emergency_contact_number' => '+91-1',
            'status' => 'draft',
            'qr_token' => str_repeat('11', 32),
            'created_by' => $company->hr->id,
            'updated_by' => $company->hr->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($company->employee)->get('/id-cards/standalone/import')->assertForbidden();
        $this->actingAs($company->lead)->post('/id-cards/standalone/import', $this->upload($this->sampleBook()))->assertForbidden();

        $template = $this->actingAs($company->hr)->get('/id-cards/standalone/import/template');
        $template->assertOk();
        $template->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringStartsWith('PK', $template->getContent());

        $this->actingAs($company->hr)->post('/id-cards/standalone/import', [
            'file' => UploadedFile::fake()->createWithContent('cards.csv', "Full Name\nAda\n"),
        ])->assertSessionHas('error');
        $this->assertSame(0, DB::table('standalone_id_card_import_batches')->count());

        $this->actingAs($company->hr)->post('/id-cards/standalone/import', $this->upload($this->sampleBook(), 'Standalone Import.xlsx'))
            ->assertRedirect()
            ->assertSessionMissing('error');

        $batchId = (int) DB::table('standalone_id_card_import_batches')->where('tenant_id', $company->id)->value('id');
        $batch = DB::table('standalone_id_card_import_batches')->where('id', $batchId)->first();
        $this->assertSame('preview', $batch->status);
        $this->assertSame('Standalone Import.xlsx', $batch->original_name);
        $this->assertSame(7, (int) $batch->row_count);
        $this->assertSame(3, (int) $batch->created_count);
        $this->assertSame(2, (int) $batch->duplicate_count);
        $this->assertSame(2, (int) $batch->failed_count);
        $this->assertStringStartsWith('id-cards/'.$company->id.'/standalone/imports/', (string) $batch->stored_path);
        Storage::disk('local')->assertExists($batch->stored_path);
        $this->assertSame(1, DB::table('standalone_id_cards')->where('tenant_id', $company->id)->count());

        $this->actingAs($other->hr)->get('/id-cards/standalone/import/'.$batchId)->assertNotFound();
        $this->actingAs($other->hr)->post('/id-cards/standalone/import/'.$batchId.'/confirm')->assertNotFound();

        $this->actingAs($company->hr)->get('/id-cards/standalone/import/'.$batchId)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('id-cards/standalone/import')
                ->where('preview.canConfirm', true)
                ->where('preview.rowCount', 7)
                ->where('preview.createdCount', 3)
                ->where('preview.duplicateCount', 2)
                ->where('preview.failedCount', 2)
                ->where('batches.0.id', $batchId)
                ->where('preview.rows', function (mixed $rows): bool {
                    $byName = collect($rows)->keyBy('fullName');

                    return ($byName['Valid India']['outcome'] ?? null) === 'new'
                        && ($byName['Auto Number']['outcome'] ?? null) === 'new'
                        && ($byName['Valid USA']['outcome'] ?? null) === 'new'
                        && ($byName['Dup File']['reason'] ?? null) === 'Duplicate card number in this file.'
                        && ($byName['Dup Existing']['reason'] ?? null) === 'Card number already exists for this company.'
                        && ($byName['']['reason'] ?? null) === 'Full name is required.'
                        && ($byName['Bad Country']['reason'] ?? null) === 'Country must be India or USA.';
                }));

        $this->actingAs($company->hr)->post('/id-cards/standalone/import/'.$batchId.'/confirm')->assertRedirect();
        $imported = DB::table('standalone_id_card_import_batches')->where('id', $batchId)->first();
        $this->assertSame('imported', $imported->status);
        $this->assertSame(3, (int) $imported->created_count);

        $cards = DB::table('standalone_id_cards')
            ->where('tenant_id', $company->id)
            ->where('import_batch_id', $batchId)
            ->orderBy('id')
            ->get();
        $this->assertCount(3, $cards);
        foreach ($cards as $card) {
            $this->assertSame('draft', $card->status);
            $this->assertNull($card->photo_path);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $card->qr_token);
            $this->assertNotSame('', (string) $card->card_number);
        }
        $numbers = $cards->pluck('card_number');
        $this->assertSame($numbers->count(), $numbers->unique()->count());
        $this->assertTrue($cards->contains(fn ($card) => $card->card_number === 'STC-100'));
        $this->assertTrue($cards->contains(fn ($card) => $card->full_name === 'Auto Number' && str_starts_with((string) $card->card_number, 'STC-')));

        $this->assertSame($employeeSnapshot, $this->employeesSnapshot($company->id));
        $this->assertSame(0, DB::table('employee_id_cards')->where('tenant_id', $company->id)->count());

        $this->actingAs($company->hr)->post('/id-cards/standalone/import/'.$batchId.'/confirm')
            ->assertSessionHas('error');
    }

    public function test_imported_drafts_need_photo_then_support_manual_upload_and_bulk_issue(): void
    {
        Storage::fake('local');
        $company = CompanyFixture::make('Standalone Bulk Co', 'standalone-bulk');
        $other = CompanyFixture::make('Other Standalone Bulk Co', 'other-standalone-bulk');
        $this->seedCompanyProfile($company, 'india');

        $this->actingAs($company->hr)->post('/id-cards/standalone/import', $this->upload($this->readyBook()))->assertRedirect();
        $batchId = (int) DB::table('standalone_id_card_import_batches')->where('tenant_id', $company->id)->value('id');
        $this->actingAs($company->hr)->post('/id-cards/standalone/import/'.$batchId.'/confirm')->assertRedirect();

        $cardIds = DB::table('standalone_id_cards')
            ->where('tenant_id', $company->id)
            ->where('import_batch_id', $batchId)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $this->assertCount(2, $cardIds);

        $bulkPage = $this->actingAs($company->hr)->get('/id-cards/standalone/bulk')->assertOk();
        $bulkPage->assertInertia(fn (Assert $page) => $page
            ->component('id-cards/standalone/bulk')
            ->where('summary.eligible', 0)
            ->where('summary.notReady', 2));
        $candidates = $bulkPage->original->getData()['page']['props']['candidates'] ?? null;
        $this->assertIsArray($candidates);
        $this->assertCount(2, $candidates);
        foreach ($candidates as $row) {
            $this->assertSame('not_ready', $row['state']);
            $this->assertContains('Photo is required.', $row['blockers'], json_encode($row));
        }

        $this->actingAs($company->hr)->from('/id-cards/standalone/bulk')->post('/id-cards/standalone/bulk', [
            'card_ids' => $cardIds,
            'format' => 'zip',
        ])->assertRedirect('/id-cards/standalone/bulk');

        $result = session('standaloneIdCardBulkResult');
        $this->assertIsArray($result);
        $this->assertSame(0, $result['successCount']);
        $this->assertSame(2, $result['failureCount']);
        $this->assertTrue(collect($result['rows'])->every(fn (array $row): bool => ($row['state'] ?? '') === 'not_ready'));

        foreach ($cardIds as $cardId) {
            $card = DB::table('standalone_id_cards')->where('id', $cardId)->first();
            $this->actingAs($company->hr)->post('/id-cards/standalone/'.$cardId, [
                'full_name' => $card->full_name,
                'card_number' => $card->card_number,
                'designation' => $card->designation,
                'department' => $card->department,
                'email' => $card->email,
                'phone' => $card->phone,
                'country' => $card->country,
                'blood_group' => $card->blood_group,
                'emergency_contact_number' => $card->emergency_contact_number,
                'photo' => UploadedFile::fake()->image('face-'.$cardId.'.png', 160, 200),
            ])->assertRedirect();
            $photoPath = (string) DB::table('standalone_id_cards')->where('id', $cardId)->value('photo_path');
            $this->assertStringStartsWith('id-cards/'.$company->id.'/standalone/photos/', $photoPath);
            Storage::disk('local')->assertExists($photoPath);
        }

        $this->actingAs($company->employee)->get('/id-cards/standalone/bulk')->assertForbidden();
        $this->actingAs($company->hr)->get('/id-cards/standalone/bulk')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('summary.eligible', 2));

        $this->actingAs($company->hr)->post('/id-cards/standalone/bulk/preview', [
            'card_ids' => $cardIds,
        ])->assertRedirect('/id-cards/standalone/bulk');

        $this->actingAs($company->hr)->post('/id-cards/standalone/bulk', [
            'card_ids' => $cardIds,
            'format' => 'zip',
        ])->assertRedirect('/id-cards/standalone/bulk');

        $issued = session('standaloneIdCardBulkResult');
        $this->assertSame(2, $issued['successCount']);
        $this->assertSame(0, $issued['failureCount']);
        $this->assertNotNull($issued['downloadUrl']);
        $this->assertMatchesRegularExpression('#^/id-cards/standalone/bulk/download/[a-f0-9]{64}$#', (string) $issued['downloadUrl']);

        $token = basename((string) $issued['downloadUrl']);
        $download = $this->actingAs($company->hr)->get('/id-cards/standalone/bulk/download/'.$token);
        $download->assertOk();
        $download->assertHeader('Content-Type', 'application/zip');
        $zipPath = tempnam(sys_get_temp_dir(), 'standalone-zip-');
        file_put_contents($zipPath, $download->streamedContent());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipPath) === true);
        $this->assertSame(2, $zip->numFiles);
        $zip->close();
        @unlink($zipPath);

        foreach ($cardIds as $cardId) {
            $this->assertSame('active', DB::table('standalone_id_cards')->where('id', $cardId)->value('status'));
            $pdfPath = DB::table('standalone_id_card_issues')->where('id_card_id', $cardId)->orderByDesc('id')->value('pdf_path');
            $this->assertStringStartsWith('id-cards/'.$company->id.'/standalone/pdfs/', (string) $pdfPath);
            Storage::disk('local')->assertExists((string) $pdfPath);
            $this->assertSame(2, (new EmployeeIdCardPdf)->pages((string) Storage::disk('local')->get((string) $pdfPath)));
        }

        $this->actingAs($other->hr)->get('/id-cards/standalone/bulk/download/'.$token)->assertNotFound();
        $this->actingAs($other->hr)->post('/id-cards/standalone/bulk', [
            'card_ids' => $cardIds,
            'format' => 'combined',
        ])->assertRedirect();
        $otherResult = session('standaloneIdCardBulkResult');
        $this->assertSame(0, $otherResult['successCount']);
        $this->assertTrue(collect($otherResult['rows'])->every(fn (array $row): bool => ($row['state'] ?? '') === 'missing'));
    }

    public function test_combined_pdf_bulk_issue_and_tenant_isolation(): void
    {
        Storage::fake('local');
        $company = CompanyFixture::make('Standalone Combined Co', 'standalone-combined');
        $this->seedCompanyProfile($company, 'usa');

        $cardId = $this->createReadyCard($company, 'STC-COMBINED', 'usa');
        $this->actingAs($company->hr)->post('/id-cards/standalone/bulk', [
            'card_ids' => [$cardId],
            'format' => 'combined',
        ])->assertRedirect();

        $result = session('standaloneIdCardBulkResult');
        $this->assertSame(1, $result['successCount']);
        $token = basename((string) $result['downloadUrl']);
        $download = $this->actingAs($company->hr)->get('/id-cards/standalone/bulk/download/'.$token);
        $download->assertOk();
        $download->assertHeader('Content-Type', 'application/pdf');
        $bytes = $download->streamedContent();
        $this->assertStringStartsWith('%PDF', $bytes);
        $this->assertSame(2, (new EmployeeIdCardPdf)->pages($bytes));
    }

    private function sampleBook(): string
    {
        return $this->workbook([
            [
                'name' => 'Sheet1',
                'headers' => ['Full Name', 'Card Number', 'Designation', 'Department', 'Email', 'Phone Number', 'Blood Group', 'Emergency Contact', 'Country'],
                'rows' => [
                    ['Valid India', 'STC-100', 'Consultant', 'Operations', 'india@example.test', '+91-9000000100', 'A+', '+91-9000000101', 'India'],
                    ['Auto Number', '', 'Contractor', 'Facilities', 'auto@example.test', '+91-9000000200', 'B+', '+91-9000000201', 'India'],
                    ['Valid USA', 'STC-USA-1', 'Advisor', 'Support', 'usa@example.test', '+1-555-0100', 'O+', '+1-555-0101', 'USA'],
                    ['Dup File', 'STC-100', 'X', 'Y', 'dup@example.test', '+91-1', 'O+', '+91-2', 'India'],
                    ['Dup Existing', 'STC-EXIST', 'X', 'Y', 'exist@example.test', '+91-3', 'O+', '+91-4', 'India'],
                    ['', 'STC-EMPTY', 'X', 'Y', 'empty@example.test', '+91-5', 'O+', '+91-6', 'India'],
                    ['Bad Country', 'STC-BAD', 'X', 'Y', 'bad@example.test', '+91-7', 'O+', '+91-8', 'Canada'],
                ],
            ],
            [
                'name' => 'Instructions',
                'headers' => ['Note'],
                'rows' => [['Ignored sheet']],
            ],
        ]);
    }

    private function readyBook(): string
    {
        return $this->workbook([
            [
                'name' => 'Sheet1',
                'headers' => ['Full Name', 'Card Number', 'Designation', 'Department', 'Email', 'Phone Number', 'Blood Group', 'Emergency Contact', 'Country'],
                'rows' => [
                    ['Bulk One', 'STC-B1', 'Guest', 'Ops', 'one@example.test', '+91-1', 'A+', '+91-11', 'India'],
                    ['Bulk Two', 'STC-B2', 'Guest', 'Ops', 'two@example.test', '+91-2', 'B+', '+91-22', 'India'],
                ],
            ],
        ]);
    }

    private function createReadyCard(CompanyFixture $company, string $cardNumber, string $country): int
    {
        $this->actingAs($company->hr)->post('/id-cards/standalone', [
            'full_name' => 'Ready '.$cardNumber,
            'card_number' => $cardNumber,
            'designation' => 'Guest',
            'department' => 'Ops',
            'email' => strtolower($cardNumber).'@example.test',
            'phone' => '+1-555-0000',
            'country' => $country,
            'blood_group' => 'O+',
            'emergency_contact_number' => '+1-555-0001',
            'photo' => UploadedFile::fake()->image($cardNumber.'.png', 160, 200),
        ])->assertRedirect();

        return (int) DB::table('standalone_id_cards')
            ->where('tenant_id', $company->id)
            ->where('card_number', $cardNumber)
            ->value('id');
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

    /**
     * @return list<array<string, mixed>>
     */
    private function employeesSnapshot(int $tenantId): array
    {
        return DB::table('employees')
            ->where('tenant_id', $tenantId)
            ->orderBy('id')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->values()
            ->all();
    }

    /**
     * @return array{file: UploadedFile}
     */
    private function upload(string $path, string $name = 'cards.xlsx'): array
    {
        return [
            'file' => new UploadedFile($path, $name, null, null, true),
        ];
    }

    /**
     * @param  list<array{name: string, headers: list<string>, rows: list<list<string>>}>  $sheets
     */
    private function workbook(array $sheets): string
    {
        $strings = [];
        $index = [];
        $remember = function (string $value) use (&$strings, &$index): int {
            if (! isset($index[$value])) {
                $index[$value] = count($strings);
                $strings[] = $value;
            }

            return $index[$value];
        };
        $sheetFiles = [];
        $workbookSheets = '';
        $relationships = '';
        $overrides = '';

        foreach ($sheets as $position => $sheet) {
            $number = $position + 1;
            $sheetRows = '';
            $line = 1;

            foreach ([$sheet['headers'], ...$sheet['rows']] as $row) {
                $cells = '';
                $column = 'A';

                foreach ($row as $value) {
                    $cells .= '<c r="'.$column.$line.'" t="s"><v>'.$remember($value).'</v></c>';
                    $column++;
                }

                $sheetRows .= '<row r="'.$line.'">'.$cells.'</row>';
                $line++;
            }

            $sheetFiles['xl/worksheets/sheet'.$number.'.xml'] = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$sheetRows.'</sheetData></worksheet>';
            $workbookSheets .= '<sheet name="'.htmlspecialchars($sheet['name'], ENT_XML1).'" sheetId="'.$number.'" r:id="rId'.$number.'"/>';
            $relationships .= '<Relationship Id="rId'.$number.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$number.'.xml"/>';
            $overrides .= '<Override PartName="/xl/worksheets/sheet'.$number.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        $shared = '';

        foreach ($strings as $value) {
            $shared .= '<si><t>'.htmlspecialchars($value, ENT_XML1).'</t></si>';
        }

        $path = tempnam(sys_get_temp_dir(), 'standalonexlsx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'.$overrides.'<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'.$workbookSheets.'</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$relationships.'</Relationships>');
        $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0" encoding="UTF-8"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'.count($strings).'" uniqueCount="'.count($strings).'">'.$shared.'</sst>');

        foreach ($sheetFiles as $name => $xml) {
            $zip->addFromString($name, $xml);
        }

        $zip->close();

        return $path;
    }
}
