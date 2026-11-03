<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\IndianAmount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia;
use Tests\Support\CompanyFixture;
use Tests\TestCase;

class OfferLetterSalaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-02');
    }

    public function test_the_offer_form_uses_the_salary_record_already_in_effect(): void
    {
        $company = CompanyFixture::make('Acme People', 'salary-offer');
        $this->salary($company->id, $company->reportEmployeeId, '2026-01-01', '10000', '0', '0');
        $this->salary($company->id, $company->reportEmployeeId, '2026-10-01', '30000', '1700', '6000');
        $this->salary($company->id, $company->reportEmployeeId, '2026-12-01', '90000', '0', '0');
        $this->salary($company->id, $company->leadEmployeeId, '2026-12-15', '80000', '0', '0');
        $usaEmployeeId = $this->employee($company, 'salary-offer-USA');
        $this->salary($company->id, $usaEmployeeId, '2026-10-01', '4000', '0', '0', 'USD');

        $employees = $this->employees($company->hr);
        $current = collect($employees)->firstWhere('id', $company->reportEmployeeId)['salary'];
        $this->assertSame([
            'basic' => '30000.00',
            'hra' => '1700.00',
            'special' => '6000.00',
            'monthly' => '37700.00',
            'currency' => 'INR',
            'effectiveFrom' => '2026-10-01',
            'appliesTo' => 'india',
        ], $current);
        $this->assertArrayNotHasKey('id', $current);
        $this->assertNull(collect($employees)->firstWhere('id', $company->outsideEmployeeId)['salary']);
        $this->assertNull(collect($employees)->firstWhere('id', $company->leadEmployeeId)['salary']);

        $usa = collect($employees)->firstWhere('id', $usaEmployeeId)['salary'];
        $this->assertSame('usa', $usa['appliesTo']);
        $this->assertSame('USD', $usa['currency']);
        $this->assertSame('4000.00', $usa['monthly']);

        $annual = (string) ((float) '37700.00' * 12);
        $this->actingAs($company->hr)
            ->getJson(route('hrms.offer-letters.amount-words', ['amount' => '37700.00']))
            ->assertOk()
            ->assertJson([
                'monthlyWords' => IndianAmount::words('37700.00'),
                'annual' => IndianAmount::figures($annual),
                'annualWords' => IndianAmount::words($annual),
            ]);
    }

    public function test_an_offer_salary_stays_separate_from_the_employee_salary_record(): void
    {
        $company = CompanyFixture::make('Acme People', 'salary-draft');
        $this->salary($company->id, $company->reportEmployeeId, '2026-01-01', '10000', '0', '0');
        $this->salary($company->id, $company->reportEmployeeId, '2026-10-01', '30000', '1700', '6000');
        $this->salary($company->id, $company->reportEmployeeId, '2026-12-01', '90000', '0', '0');
        $employeeBefore = (array) DB::table('employees')->where('id', $company->reportEmployeeId)->first();
        $structureBefore = DB::table('salary_structures')->where('employee_id', $company->reportEmployeeId)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $events = Schema::hasTable('salary_structure_events') ? DB::table('salary_structure_events')->count() : 0;

        $this->actingAs($company->hr)->post(route('hrms.offer-letters.legal'), [
            'country' => 'india',
            'legal_name' => 'Acme People Private Limited',
            'legal_address' => 'Registered office, Hyderabad',
            'signatory_name' => 'Priya Shah',
            'signatory_designation' => 'Director',
            'hr_email' => 'hr@acme.test',
        ])->assertRedirect();

        $this->actingAs($company->hr)
            ->post(route('hrms.offer-letters.store'), $this->offerPayload($company->reportEmployeeId, '40000', '25000', '10000', '5000'))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $letterId = (int) DB::table('offer_letters')->where('tenant_id', $company->id)->value('id');
        $letter = DB::table('offer_letters')->where('id', $letterId)->first();
        $this->assertSame('draft', $letter->status);
        $this->assertEquals(40000, (float) $letter->monthly_salary);
        $this->assertEquals(25000, (float) $letter->basic_salary);
        $this->assertEquals(10000, (float) $letter->hra);
        $this->assertEquals(5000, (float) $letter->special_allowance);
        $this->assertEquals(480000, (float) $letter->annual_ctc);
        $this->assertSame($employeeBefore, (array) DB::table('employees')->where('id', $company->reportEmployeeId)->first());
        $this->assertSame($structureBefore, DB::table('salary_structures')->where('employee_id', $company->reportEmployeeId)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertSame(3, DB::table('salary_structures')->where('employee_id', $company->reportEmployeeId)->count());
        if (Schema::hasTable('salary_structure_events')) {
            $this->assertSame($events, DB::table('salary_structure_events')->count());
        }

        $this->actingAs($company->hr)
            ->post(route('hrms.offer-letters.update', $letterId), $this->offerPayload($company->reportEmployeeId, '41000', '26000', '10000', '5000'))
            ->assertRedirect();

        $reopened = [];
        $this->actingAs($company->hr)
            ->get(route('hrms.offer-letters.create', ['letter' => $letterId]))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$reopened) {
                $reopened = $page->toArray()['props'];
            });

        $this->assertEquals(41000, (float) $reopened['offer']['monthlySalary']);
        $this->assertEquals(26000, (float) $reopened['offer']['basicSalary']);
        $this->assertEquals(10000, (float) $reopened['offer']['hra']);
        $this->assertEquals(5000, (float) $reopened['offer']['specialAllowance']);
        $this->assertSame('draft', DB::table('offer_letters')->where('id', $letterId)->value('status'));
        $saved = collect($reopened['employees'])->firstWhere('id', $company->reportEmployeeId)['salary'];
        $this->assertSame('37700.00', $saved['monthly']);
        $this->assertSame('30000.00', $saved['basic']);
        $this->assertEquals(30000, (float) DB::table('salary_structures')->where('employee_id', $company->reportEmployeeId)->whereDate('effective_from', '2026-10-01')->value('basic'));
        $this->assertSame($employeeBefore, (array) DB::table('employees')->where('id', $company->reportEmployeeId)->first());
    }

    public function test_a_usa_salary_record_is_not_stored_as_india_salary_components(): void
    {
        $company = CompanyFixture::make('Acme People', 'salary-usa');
        $usaEmployeeId = $this->employee($company, 'salary-usa-EMP');
        $this->salary($company->id, $usaEmployeeId, '2026-10-01', '25', '0', '0', 'USD');
        $before = DB::table('salary_structures')->where('employee_id', $usaEmployeeId)->first();

        $this->actingAs($company->hr)->post(route('hrms.offer-letters.store'), [
            'flow' => 'appointment',
            'country' => 'usa',
            'position_code' => 'opt_recruiter',
            'employee_id' => $usaEmployeeId,
            'monthly_salary' => '5000',
            'hourly_rate' => '40',
            'basic_salary' => '25',
            'hra' => '0',
            'special_allowance' => '0',
            'joining_date' => '2026-12-01',
            'date_of_issue' => '2026-11-02',
            'notice_unit' => 'months',
            'notice_months' => 1,
            'hr_name' => 'Priya Shah',
            'hr_signature' => 'Priya Shah',
        ])->assertRedirect();

        $letter = DB::table('offer_letters')->where('employee_id', $usaEmployeeId)->first();
        $this->assertSame('usa', $letter->country);
        $this->assertNull($letter->basic_salary);
        $this->assertNull($letter->hra);
        $this->assertNull($letter->special_allowance);
        $this->assertEquals(40, (float) $letter->hourly_rate);
        $this->assertEquals(83200, (float) $letter->annual_ctc);
        $after = DB::table('salary_structures')->where('employee_id', $usaEmployeeId)->first();
        $this->assertEquals((float) $before->basic, (float) $after->basic);
        $this->assertSame($before->currency, $after->currency);
        $this->assertSame(1, DB::table('salary_structures')->where('employee_id', $usaEmployeeId)->count());
    }

    public function test_salary_details_stay_inside_the_company_and_require_an_authorized_role(): void
    {
        $company = CompanyFixture::make('Acme People', 'salary-auth');
        $other = CompanyFixture::make('Other People', 'salary-other');
        $this->salary($company->id, $company->reportEmployeeId, '2026-10-01', '30000', '1700', '6000');
        $this->salary($other->id, $other->reportEmployeeId, '2026-10-01', '99999', '1', '1', 'INR');

        $this->actingAs($company->employee)->get(route('hrms.offer-letters.create'))->assertForbidden();
        $this->actingAs($company->lead)->get(route('hrms.offer-letters.create'))->assertForbidden();
        $this->actingAs($company->employee)->getJson(route('hrms.offer-letters.amount-words', ['amount' => '37700']))->assertForbidden();

        $own = collect($this->employees($company->hr))->pluck('id');
        $this->assertTrue($own->contains($company->reportEmployeeId));
        $this->assertFalse($own->contains($other->reportEmployeeId));

        $foreign = collect($this->employees($other->hr))->firstWhere('id', $other->reportEmployeeId)['salary'];
        $this->assertSame('99999.00', $foreign['basic']);
        $this->assertFalse(collect($this->employees($company->hr))->pluck('salary')->contains(fn ($salary) => is_array($salary) && $salary['basic'] === '99999.00'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function employees(User $user): array
    {
        $employees = [];
        $this->actingAs($user)
            ->get(route('hrms.offer-letters.create'))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$employees) {
                $employees = $page->toArray()['props']['employees'];
            });

        return $employees;
    }

    private function employee(CompanyFixture $company, string $code): int
    {
        $source = DB::table('employees')->where('id', $company->reportEmployeeId)->first();

        return DB::table('employees')->insertGetId([
            'tenant_id' => $company->id,
            'user_id' => null,
            'employee_code' => $code,
            'first_name' => $code,
            'last_name' => 'Person',
            'email' => strtolower($code).'@example.test',
            'department_id' => $source->department_id,
            'designation_id' => $source->designation_id,
            'location_id' => $source->location_id,
            'manager_id' => null,
            'employment_type' => 'full_time',
            'work_mode' => 'office',
            'holiday_calendar' => 'usa',
            'date_of_joining' => '2026-01-15',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function salary(int $tenantId, int $employeeId, string $effectiveFrom, string $basic, string $hra, string $special, string $currency = 'INR'): void
    {
        DB::table('salary_structures')->insert([
            'tenant_id' => $tenantId,
            'employee_id' => $employeeId,
            'basic' => $basic,
            'hra' => $hra,
            'special_allowance' => $special,
            'effective_from' => $effectiveFrom,
            'currency' => $currency,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array<string, int|string>
     */
    private function offerPayload(int $employeeId, string $monthly, string $basic, string $hra, string $special): array
    {
        return [
            'flow' => 'appointment',
            'country' => 'india',
            'position_code' => 'opt_recruiter',
            'employee_id' => $employeeId,
            'designation' => 'Recruiter',
            'monthly_salary' => $monthly,
            'basic_salary' => $basic,
            'hra' => $hra,
            'special_allowance' => $special,
            'joining_date' => '2026-12-01',
            'date_of_issue' => '2026-11-02',
            'notice_unit' => 'days',
            'notice_days' => 30,
            'working_hours' => '9:30 AM to 6:30 PM',
            'probation_months' => 6,
            'hr_name' => 'Priya Shah',
            'hr_signature' => 'Priya Shah',
        ];
    }
}
