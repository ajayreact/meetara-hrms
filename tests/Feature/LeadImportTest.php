<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CompanyFixture;
use Tests\TestCase;
use ZipArchive;

class LeadImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_preview_confirmation_storage_and_unassigned_leads(): void
    {
        Storage::fake('local');
        $company = CompanyFixture::make('Lead Import', 'leadimport');
        $other = CompanyFixture::make('Other Lead Import', 'leadimportother');
        $employees = DB::table('employees')->orderBy('id')->get(['id', 'tenant_id', 'email', 'employee_code']);
        $this->lead($company, 'Already kept', 'kept@example.test', null, null);
        $this->lead($other, 'Other only', 'other-only@example.test', '5550009999', 'linkedin.com/in/foreign');

        $this->actingAs($company->lead)->post('/leads/imports', $this->upload($this->sampleBook()))->assertForbidden();
        $this->actingAs($company->employee)->post('/leads/imports', $this->upload($this->sampleBook()))->assertForbidden();
        $this->actingAs($this->roleUser($company, 'Accounts'))->get('/leads')->assertForbidden();
        $this->actingAs($company->hr)->post('/leads/imports', [
            'file' => UploadedFile::fake()->createWithContent('leads.csv', "Name\nAda\n"),
        ])->assertSessionHas('error');
        $this->assertSame(0, DB::table('lead_import_batches')->count());

        $this->actingAs($company->hr)->post('/leads/imports', $this->upload($this->sampleBook(), 'Melani Total.xlsx'))->assertRedirect()->assertSessionMissing('error');
        $batchId = (int) DB::table('lead_import_batches')->where('tenant_id', $company->id)->value('id');
        $batch = DB::table('lead_import_batches')->where('id', $batchId)->first();
        $this->assertSame('preview', $batch->status);
        $this->assertSame('Melani Total.xlsx', $batch->original_name);
        $this->assertSame((int) $company->hr->id, (int) $batch->uploaded_by);
        $this->assertSame(13, (int) $batch->row_count);
        $this->assertSame(7, (int) $batch->created_count);
        $this->assertSame(4, (int) $batch->duplicate_count);
        $this->assertSame(2, (int) $batch->invalid_count);
        $this->assertStringStartsWith('lead-imports/'.$company->id.'/', (string) $batch->stored_path);
        $this->assertStringEndsWith('.xlsx', (string) $batch->stored_path);
        $this->assertStringNotContainsString('..', (string) $batch->stored_path);
        Storage::disk('local')->assertExists($batch->stored_path);
        $this->assertSame(0, DB::table('leads')->where('import_batch_id', $batchId)->count());
        $this->assertSame(0, DB::table('lead_assignments')->count());

        $this->actingAs($other->hr)->get("/leads/imports/{$batchId}")->assertNotFound();
        $this->actingAs($other->hr)->post("/leads/imports/{$batchId}/confirm")->assertNotFound();
        $this->actingAs($company->hr)->get("/leads/imports/{$batchId}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('leads/import')
                ->where('preview.canConfirm', true)
                ->where('preview.rowCount', 13)
                ->where('preview.createdCount', 7)
                ->where('preview.duplicateCount', 4)
                ->where('preview.invalidCount', 2)
                ->where('preview.rows', function (mixed $rows): bool {
                    $byName = collect($rows)->keyBy('name');

                    return ($byName['Email Copy']['reason'] ?? null) === 'Duplicate email in this file.'
                        && ($byName['Phone Copy']['reason'] ?? null) === 'Duplicate phone in this file.'
                        && ($byName['Link Copy']['reason'] ?? null) === 'Duplicate LinkedIn profile in this file.'
                        && ($byName['Kept']['reason'] ?? null) === 'Email already exists for this company.'
                        && ($byName['']['outcome'] ?? null) === 'invalid'
                        && ($byName['']['reason'] ?? null) === 'Candidate name is required.'
                        && ($byName['Too Long']['outcome'] ?? null) === 'invalid'
                        && ($byName['After Nameless']['outcome'] ?? null) === 'new'
                        && ($byName['Other Email']['outcome'] ?? null) === 'new'
                        && ($byName['Foreign Phone']['outcome'] ?? null) === 'new'
                        && ($byName['Foreign Link']['outcome'] ?? null) === 'new'
                        && ($byName['Detail Person']['graduationTerm'] ?? null) === 'MAY(2025)'
                        && ! collect($rows)->contains(fn (array $row): bool => ($row['name'] ?? null) === 'Hidden Candidate');
                }));

        $this->lead($company, 'Arrived later', 'after-nameless@example.test', null, null);
        $this->actingAs($company->hr)->get("/leads/imports/{$batchId}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('preview.createdCount', 6)
                ->where('preview.duplicateCount', 5));

        $this->actingAs($company->hr)->post("/leads/imports/{$batchId}/confirm")->assertRedirect();
        $imported = DB::table('lead_import_batches')->where('id', $batchId)->first();
        $this->assertSame('imported', $imported->status);
        $this->assertSame(13, (int) $imported->row_count);
        $this->assertSame(6, (int) $imported->created_count);
        $this->assertSame(5, (int) $imported->duplicate_count);
        $this->assertSame(2, (int) $imported->invalid_count);
        $this->assertSame(6, DB::table('leads')->where('import_batch_id', $batchId)->count());
        $this->assertSame(0, DB::table('leads')->where('import_batch_id', $batchId)->whereNotNull('team_lead_employee_id')->count());
        $this->assertSame(0, DB::table('leads')->where('import_batch_id', $batchId)->whereNotNull('assignee_employee_id')->count());
        $this->assertNull(DB::table('leads')->where('email_normalized', 'after-nameless@example.test')->where('import_batch_id', $batchId)->value('id'));
        $this->assertSame('Arrived later', DB::table('leads')->where('email_normalized', 'after-nameless@example.test')->value('name'));
        $this->assertSame(0, DB::table('leads')->where('name', 'Hidden Candidate')->count());
        $this->assertSame(0, DB::table('leads')->where('name', 'Too Long')->count());

        $person = DB::table('leads')->where('email_normalized', 'serial@example.test')->first();
        $this->assertNotNull($person);
        $this->assertSame('2026-02-20', substr((string) $person->imported_on, 0, 10));
        $this->assertSame((int) $company->hr->id, (int) $person->created_by);
        $this->assertSame('new', $person->pipeline_status);
        $this->assertSame($company->id, (int) $person->tenant_id);

        $keptFields = DB::table('leads')->where('email_normalized', 'new.person@example.com')->first();
        $this->assertSame('https://www.LinkedIn.com/in/First/', $keptFields->linkedin_url);
        $this->assertSame('linkedin.com/in/first', $keptFields->linkedin_key);
        $this->assertSame('5550102000', $keptFields->phone_digits);
        $this->assertSame('(555) 010-2000', $keptFields->phone);

        $detailed = DB::table('leads')->where('name', 'Detail Person')->first();
        $this->assertSame('MAY(2025)', $detailed->graduation_term);
        $this->assertSame('3', $detailed->experience);
        $this->assertSame('Analyst, Recruiter', $detailed->designations);
        $this->assertSame('TX', $detailed->state);
        $this->assertSame('State University', $detailed->university);
        $this->assertSame('2026-02-20', substr((string) $detailed->imported_on, 0, 10));
        $this->assertSame('https://www.linkedin.com/in/detail', $detailed->linkedin_url);
        $this->assertNull($detailed->team_lead_employee_id);
        $this->assertNull($detailed->assignee_employee_id);

        $this->actingAs($company->hr)->post("/leads/imports/{$batchId}/confirm")->assertSessionHas('error');
        $this->assertSame(6, DB::table('leads')->where('import_batch_id', $batchId)->count());
        $this->assertEquals($employees, DB::table('employees')->orderBy('id')->get(['id', 'tenant_id', 'email', 'employee_code']));

        $this->actingAs($this->roleUser($company, 'Owner'))->post('/leads/imports', $this->upload($this->workbook([
            $this->sheet('Sheet1', [$this->cells('Owner Lead', 'owner-lead@example.test')]),
        ])))->assertRedirect();
        $ownerBatch = (int) DB::table('lead_import_batches')->where('tenant_id', $company->id)->where('status', 'preview')->value('id');
        $this->actingAs($this->roleUser($company, 'Owner'))->post("/leads/imports/{$ownerBatch}/confirm")->assertRedirect();
        $this->assertSame(1, DB::table('leads')->where('email_normalized', 'owner-lead@example.test')->whereNull('team_lead_employee_id')->count());
    }

    public function test_sheet1_is_read_when_sheet2_is_the_first_worksheet(): void
    {
        Storage::fake('local');
        $company = CompanyFixture::make('Sheet Order', 'sheetorder');
        $book = $this->workbook([
            $this->sheet('Sheet2', [$this->cells('Hidden Candidate', 'hidden@example.test')]),
            $this->sheet('Sheet1', [$this->cells('Visible Candidate', 'visible@example.test')]),
        ]);

        $this->actingAs($company->hr)->post('/leads/imports', $this->upload($book))->assertRedirect();
        $batchId = (int) DB::table('lead_import_batches')->where('tenant_id', $company->id)->value('id');
        $this->actingAs($company->hr)->post("/leads/imports/{$batchId}/confirm")->assertRedirect();
        $this->assertSame(1, DB::table('leads')->where('tenant_id', $company->id)->where('import_batch_id', $batchId)->count());
        $this->assertSame('Visible Candidate', DB::table('leads')->where('import_batch_id', $batchId)->value('name'));
        $this->assertSame(0, DB::table('leads')->where('name', 'Hidden Candidate')->count());
    }

    public function test_a_workbook_without_sheet1_is_rejected(): void
    {
        Storage::fake('local');
        $company = CompanyFixture::make('Missing Sheet', 'missingsheet');
        $book = $this->workbook([
            $this->sheet('Sheet2', [$this->cells('Only Second', 'second@example.test')]),
        ]);

        $this->actingAs($company->hr)->post('/leads/imports', $this->upload($book))->assertSessionHas('error');
        $this->assertSame(0, DB::table('lead_import_batches')->count());
        $this->assertSame(0, DB::table('leads')->where('name', 'Only Second')->count());
        $this->assertSame([], Storage::disk('local')->allFiles('lead-imports/'.$company->id));
    }

    /**
     * @param  list<array{name: string, headers: list<string>, rows: list<list<string>>}>  $sheets
     */
    private function sampleBook(): string
    {
        return $this->workbook([
            $this->sheet('Sheet2', [$this->cells('Hidden Candidate', 'hidden@example.test')]),
            $this->sheet('Sheet1', [
                $this->cells('First Person', ' New.Person@Example.com ', '(555) 010-2000', 'https://www.LinkedIn.com/in/First/', '', '', '', '', ''),
                $this->cells('Email Copy', 'mailto:New.Person@Example.com'),
                $this->cells('Phone Copy', 'phone-copy@example.test', '555.010.2000'),
                $this->cells('Link Copy', 'link-copy@example.test', '5551110000', 'https://www.linkedin.com/in/First?trk=public'),
                $this->cells('Kept', 'kept@example.test'),
                $this->cells('Foreign Phone', 'foreign-phone@example.test', '555-000-9999'),
                $this->cells('Foreign Link', 'foreign-link@example.test', '', 'https://linkedin.com/in/foreign/'),
                $this->cells('Other Email', 'other-only@example.test'),
                $this->cells('', 'nameless@example.test'),
                $this->cells('After Nameless', 'after-nameless@example.test'),
                $this->cells('Detail Person', 'detail@example.test', '5552223333', 'https://www.linkedin.com/in/detail', 'TX', 'State University', 'MAY(2025)', '3', 'Analyst, Recruiter', '20/02/2026'),
                $this->cells('Serial Person', 'serial@example.test', '', '', '', '', '', '', '', '46073'),
                $this->cells('Too Long', 'too-long@example.test', '', '', '', '', '', '123456789012345678901'),
            ]),
        ]);
    }

    /**
     * @return list<string>
     */
    private function cells(
        string $name,
        string $email = '',
        string $phone = '',
        string $linkedin = '',
        string $state = '',
        string $university = '',
        string $year = '',
        string $experience = '',
        string $designations = '',
        string $date = '',
    ): array {
        return [$date, $linkedin, $state, $name, $email, $phone, $university, $year, $experience, $designations];
    }

    /**
     * @param  list<list<string>>  $rows
     * @return array{name: string, headers: list<string>, rows: list<list<string>>}
     */
    private function sheet(string $name, array $rows): array
    {
        return [
            'name' => $name,
            'headers' => ['Date', 'Linkedin', 'State', 'Name', 'Emaill', 'Phone', 'University', 'Year', 'Experience', 'Designations'],
            'rows' => $rows,
        ];
    }

    private function lead(CompanyFixture $company, string $name, string $email, ?string $phone, ?string $linkedin): void
    {
        $lead = new Lead;
        $lead->forceFill([
            'tenant_id' => $company->id,
            'name' => $name,
            'email' => $email,
            'email_normalized' => $email,
            'phone_digits' => $phone,
            'linkedin_key' => $linkedin,
            'pipeline_status' => 'new',
            'created_by' => $company->hr->id,
        ])->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function upload(string $path, string $name = 'leads.xlsx'): array
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

        $path = tempnam(sys_get_temp_dir(), 'leadsxlsx');
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

    private function roleUser(CompanyFixture $company, string $role): User
    {
        $user = User::factory()->create();
        $user->forceFill([
            'tenant_id' => $company->id,
            'is_platform_admin' => false,
            'invite_accepted_at' => now(),
        ])->save();
        $roleId = DB::table('roles')->where('tenant_id', $company->id)->where('name', $role)->value('id');

        if ($roleId === null) {
            $roleId = DB::table('roles')->insertGetId([
                'tenant_id' => $company->id,
                'name' => $role,
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('model_has_roles')->insert([
            'role_id' => $roleId,
            'model_type' => User::class,
            'model_id' => $user->id,
            'tenant_id' => $company->id,
        ]);

        return $user->refresh();
    }
}
