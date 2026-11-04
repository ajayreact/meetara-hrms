<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Hrms\PayrollTemplate;
use App\Services\Hrms\PayslipPdf;
use App\Services\Hrms\SpreadsheetTable;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CompanyFixture;
use Tests\TestCase;
use ZipArchive;

class PayrollImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_excel_import_validates_matches_and_generates_private_payslips_without_recalculating(): void
    {
        Storage::fake('local');
        Mail::fake();
        $state = new \stdClass;
        $state->fails = 1;
        $this->app->bind(PayslipPdf::class, function () use ($state) {
            return new class($state) extends PayslipPdf
            {
                public function __construct(private \stdClass $state) {}

                public function render(array $slip): string
                {
                    if (($slip['code'] ?? '') === 'FAIL' && $this->state->fails > 0) {
                        $this->state->fails--;

                        throw new \RuntimeException('pdf failed');
                    }

                    return parent::render($slip);
                }
            };
        });

        $company = CompanyFixture::make('Import People', 'importco');
        $other = CompanyFixture::make('Other Import People', 'importother');
        $accounts = $this->roleUser($company, 'Accounts');
        $admin = $this->roleUser($company, 'Owner');
        $salaryId = DB::table('salary_structures')->insertGetId([
            'tenant_id' => $company->id,
            'employee_id' => $company->reportEmployeeId,
            'basic' => '99999.00',
            'hra' => '1.00',
            'special_allowance' => '1.00',
            'effective_from' => '2026-01-01',
            'currency' => 'INR',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('employees')->insert([
            'tenant_id' => $company->id,
            'employee_code' => 'FAIL',
            'first_name' => 'Fail',
            'last_name' => 'Person',
            'email' => 'fail@example.test',
            'department_id' => $company->departmentId,
            'status' => 'active',
            'date_of_joining' => '2026-02-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($company->lead)->post('/payroll/imports', $this->upload(11, $this->sheet('importco-REP', '35200.00')))->assertForbidden();
        $this->actingAs($company->employee)->post('/payroll/imports', $this->upload(11, $this->sheet('importco-REP', '35200.00')))->assertForbidden();
        $this->actingAs($admin)->post('/payroll/imports', $this->upload(11, $this->sheet('importco-REP', '35200.00')))->assertRedirect();
        $this->actingAs($accounts)->post('/payroll/imports', $this->upload(10, $this->sheet('importco-REP', '35200.00')))->assertSessionHas('error');
        $this->assertSame('draft', DB::table('payroll_runs')->where('tenant_id', $company->id)->where('month', 10)->value('status'));

        $invalid = $this->sheet('importco-REP', '35200.00')
            ."importco-REP,Duplicate,11,2026,30000,1700,6000,0,37700,1800,0,200,500,0,2500,35200\n"
            ."importother-REP,Outside,11,2026,30000,1700,6000,0,37700,1800,0,200,500,0,2500,35200\n"
            ."MISSING,Nobody,11,2026,30000,1700,6000,0,37700,1800,0,200,500,0,2500,35200\n"
            ."importco-OUT,Outside,11,2026,10,10,10,10,999,1,1,1,1,1,5,100\n"
            ."importco-LEAD,Lead,11,2026,abc,0,0,0,0,0,0,0,0,0,0,0\n";
        $this->actingAs($company->hr)->post('/payroll/imports', $this->upload(11, $invalid))->assertRedirect();
        $runId = (int) DB::table('payroll_runs')->where('tenant_id', $company->id)->where('month', 11)->value('id');
        $this->assertSame('import_review', DB::table('payroll_runs')->where('id', $runId)->value('status'));
        $this->assertSame(0, DB::table('payroll_items')->where('payroll_run_id', $runId)->count());
        $this->actingAs($company->hr)->get("/payroll/imports/{$runId}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('payroll/import')
                ->where('review.canConfirm', false)
                ->where('review.errorCount', fn (int $count): bool => $count >= 4));
        $this->actingAs($company->hr)->post("/payroll/imports/{$runId}/confirm")->assertSessionHas('error');
        $this->assertSame(0, DB::table('payroll_items')->where('payroll_run_id', $runId)->count());
        $this->actingAs($other->hr)->post("/payroll/imports/{$runId}/confirm")->assertNotFound();
        $this->actingAs($other->hr)->get("/payroll/imports/{$runId}")->assertNotFound();

        $mapped = "Code,Take Home\nimportco-REP,35200\n";
        $this->actingAs($accounts)->post('/payroll/imports', $this->upload(11, $mapped, 'mapped.csv'))->assertRedirect();
        $this->actingAs($accounts)->post("/payroll/imports/{$runId}/mapping", [
            'mapping' => ['employee_id' => 'Code', 'net' => 'Take Home'],
        ])->assertRedirect();
        $this->actingAs($accounts)->get("/payroll/imports/{$runId}")
            ->assertInertia(fn (Assert $page) => $page->where('review.canConfirm', true)->where('review.errorCount', 0));

        $xlsx = $this->xlsx(['Employee ID', 'Net Salary'], [['importco-REP', '35200']]);
        $table = app(SpreadsheetTable::class)->read($xlsx, 'xlsx');
        $this->assertSame('Employee ID', $table['headers'][0]);
        $this->assertSame('importco-REP', $table['rows'][0][0]);
        $this->actingAs($accounts)->post('/payroll/imports', [
            'year' => 2026,
            'month' => 11,
            'file' => new UploadedFile($xlsx, 'payroll.xlsx', null, null, true),
        ])->assertRedirect();
        $this->actingAs($accounts)->get("/payroll/imports/{$runId}")
            ->assertInertia(fn (Assert $page) => $page->where('review.rows.0.employeeCode', 'importco-REP')->where('review.canConfirm', true));

        $valid = $this->sheet('importco-REP', '35200.00').$this->sheetLine('FAIL', 'Fail Person', '35200.00', '37700.00', '2500.00');
        $this->actingAs($accounts)->post('/payroll/imports', $this->upload(11, $valid))->assertRedirect();

        $this->actingAs($accounts)->post("/payroll/imports/{$runId}/confirm")->assertRedirect();
        $this->assertSame('processed', DB::table('payroll_runs')->where('id', $runId)->value('status'));
        $this->assertSame('partial', DB::table('payroll_runs')->where('id', $runId)->value('payslip_status'));
        $item = DB::table('payroll_items')->where('payroll_run_id', $runId)->where('employee_id', $company->reportEmployeeId)->first();
        $this->assertSame('30000.00', Money::format(Money::cents((string) $item->basic)));
        $this->assertSame('35200.00', Money::format(Money::cents((string) $item->net)));
        $this->assertSame('1800.00', Money::format(Money::cents((string) $item->pf_employee)));
        $this->assertSame(9999900, Money::cents((string) DB::table('salary_structures')->where('id', $salaryId)->value('basic')));
        $this->assertSame(0, DB::table('payroll_payments')->where('tenant_id', $company->id)->count());
        $this->assertNotNull($item->payslip_path);
        $stored = Storage::disk('local')->get($item->payslip_path);
        $this->assertStringStartsWith('%PDF', $stored);
        $this->assertStringContainsString('Net payable', $stored);
        $this->assertStringContainsString('35200.00', $stored);
        $this->assertStringNotContainsString('99999.00', $stored);
        $failed = DB::table('payroll_items')->where('payroll_run_id', $runId)->whereNotNull('payslip_error')->first();
        $this->assertNotNull($failed);

        $this->actingAs($accounts)->post("/payroll/imports/{$runId}/payslips")->assertRedirect();
        $this->assertSame($stored, Storage::disk('local')->get($item->payslip_path));
        $this->assertNull(DB::table('payroll_items')->where('id', $failed->id)->value('payslip_error'));
        $this->assertSame('complete', DB::table('payroll_runs')->where('id', $runId)->value('payslip_status'));
        $this->actingAs($accounts)->post('/payroll/imports', $this->upload(11, $valid))->assertSessionHas('error');
        $this->assertSame('35200.00', Money::format(Money::cents((string) DB::table('payroll_items')->where('id', $item->id)->value('net'))));

        $this->actingAs($company->employee)->get('/payslips?year=2026&month=11')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('payslips.0.id', $item->id)->where('canDownloadAll', false));
        $pdf = $this->actingAs($company->employee)->get("/payslips/{$item->id}/pdf")->assertOk()->getContent();
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->actingAs($company->employee)->get("/payslips/{$failed->id}/pdf")->assertNotFound();
        $this->actingAs($company->lead)->get('/payslips')->assertOk();
        $this->actingAs($company->lead)->get("/payslips/{$item->id}/pdf")->assertNotFound();
        $this->actingAs($other->hr)->get("/payslips/{$item->id}/pdf")->assertNotFound();
        $this->actingAs($company->hr)->get("/payslips/{$item->id}/pdf")->assertOk();
        $archive = $this->actingAs($company->hr)->get('/payslips/archive?year=2026&month=11')->assertOk();
        $this->assertStringStartsWith('PK', $archive->streamedContent());
        Mail::assertNothingSent();
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $company->employee->id)->where('data->key', 'payslip-'.$item->id)->count());
    }

    public function test_sample_workbook_matches_importer_columns_and_keeps_examples_on_the_first_sheet(): void
    {
        $company = CompanyFixture::make('Template People', 'templateco');
        $accounts = $this->roleUser($company, 'Accounts');

        $this->actingAs($company->employee)->get('/payroll/template')->assertForbidden();
        $this->actingAs($company->lead)->get('/payroll/template')->assertForbidden();

        $workbook = $this->actingAs($company->hr)->get('/payroll/template')->assertOk();
        $workbook->assertHeader('content-disposition', 'attachment; filename="Veltrix_India_Payroll_Template.xlsx"');
        $path = tempnam(sys_get_temp_dir(), 'template');
        file_put_contents($path, $workbook->getContent());
        $zip = new ZipArchive;
        $zip->open($path);
        $this->assertStringContainsString('name="Payroll Data"', $zip->getFromName('xl/workbook.xml'));
        $this->assertStringContainsString('name="Instructions"', $zip->getFromName('xl/workbook.xml'));
        $this->assertStringContainsString('state="frozen"', $zip->getFromName('xl/worksheets/sheet1.xml'));
        $this->assertStringContainsString('Remove both sample rows', $zip->getFromName('xl/sharedStrings.xml'));
        $zip->close();

        $table = app(SpreadsheetTable::class)->read($path, 'xlsx');
        unlink($path);
        $this->assertSame(PayrollTemplate::HEADERS, $table['headers']);
        $this->assertCount(2, $table['rows']);
        $this->assertSame(['SAMPLE-001', 'Sample Employee A', '30000.00', '12000.00', '5000.00', '2000.00', '49000.00', '1800.00', '368.00', '200.00', '1500.00', '500.00', '4368.00', '44632.00'], $table['rows'][0]);

        $csv = $this->actingAs($accounts)->get('/payroll/template?format=csv')->assertOk();
        $csv->assertHeader('content-disposition', 'attachment; filename="Veltrix_India_Payroll_Template.csv"');
        $this->assertStringContainsString('Employee ID', $csv->getContent());
        $this->assertStringContainsString('SAMPLE-002', $csv->getContent());
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function upload(int $month, string $csv, string $name = 'payroll.csv'): array
    {
        return [
            'year' => 2026,
            'month' => $month,
            'file' => UploadedFile::fake()->createWithContent($name, $csv),
        ];
    }

    private function sheet(string $code, string $net): string
    {
        return "Employee ID,Employee Name,Payroll Month,Payroll Year,Basic Salary,HRA,Special Allowance,Other Earnings,Gross Salary,PF,ESI,Professional Tax,TDS,Other Deductions,Total Deductions,Net Salary\n"
            .$this->sheetLine($code, 'Wrong Name', $net, '37700.00', '2500.00');
    }

    private function sheetLine(string $code, string $name, string $net, string $gross, string $deductions): string
    {
        return "{$code},{$name},11,2026,30000,1700,6000,0,{$gross},1800,0,200,500,0,{$deductions},{$net}\n";
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     */
    private function xlsx(array $headers, array $rows): string
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
        $sheetRows = '';
        $line = 1;

        foreach ([$headers, ...$rows] as $row) {
            $cells = '';
            $column = 'A';

            foreach ($row as $value) {
                $cells .= '<c r="'.$column.$line.'" t="s"><v>'.$remember($value).'</v></c>';
                $column++;
            }

            $sheetRows .= '<row r="'.$line.'">'.$cells.'</row>';
            $line++;
        }

        $shared = '';

        foreach ($strings as $value) {
            $shared .= '<si><t>'.htmlspecialchars($value, ENT_XML1).'</t></si>';
        }

        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Payroll" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/></Relationships>');
        $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0" encoding="UTF-8"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'.count($strings).'" uniqueCount="'.count($strings).'">'.$shared.'</sst>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$sheetRows.'</sheetData></worksheet>');
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
