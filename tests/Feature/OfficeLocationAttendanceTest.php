<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CompanyFixture;
use Tests\TestCase;

class OfficeLocationAttendanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-19 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_authorized_roles_can_save_coordinates_and_other_companies_cannot_see_them(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $other = CompanyFixture::make('Deep Data Inc', 'deep-data');
        $admin = $this->owner($company);

        $this->actingAs($admin)->put('/offices/'.$company->officeId, $this->payload())->assertSessionHasNoErrors();
        $saved = DB::table('locations')->where('id', $company->officeId)->first();
        $this->assertSame('12 Park Street, Kolkata', $saved->address);
        $this->assertEqualsWithDelta(22.572645, (float) $saved->latitude, 0.0000001);
        $this->assertEqualsWithDelta(88.363895, (float) $saved->longitude, 0.0000001);
        $this->assertSame(150, (int) $saved->verification_radius_meters);
        $this->assertSame(0, (int) $saved->gps_verification_enabled);

        $this->actingAs($company->hr)->put('/offices/'.$company->officeId, $this->payload([
            'verification_radius_meters' => 200,
        ]))->assertSessionHasNoErrors();
        $this->assertSame(200, (int) DB::table('locations')->where('id', $company->officeId)->value('verification_radius_meters'));

        $this->actingAs($company->employee)->put('/offices/'.$company->officeId, $this->payload(['name' => 'Employee edit']))->assertNotFound();
        $this->actingAs($company->lead)->put('/offices/'.$company->officeId, $this->payload(['name' => 'Lead edit']))->assertNotFound();
        $this->actingAs($company->hr)->put('/offices/'.$other->officeId, $this->payload(['name' => 'Taken']))->assertNotFound();
        $this->assertNull(DB::table('locations')->where('id', $other->officeId)->value('latitude'));
        $this->actingAs($other->hr)->get('/offices')->assertDontSee('12 Park Street, Kolkata');
        $this->assertSame('Head Office', DB::table('locations')->where('id', $company->officeId)->value('name'));
    }

    public function test_coordinates_and_radius_are_rejected_outside_the_allowed_range(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');

        $this->actingAs($company->hr)->put('/offices/'.$company->officeId, $this->payload(['latitude' => 90.1]))->assertSessionHasErrors('latitude');
        $this->actingAs($company->hr)->put('/offices/'.$company->officeId, $this->payload(['longitude' => -180.1]))->assertSessionHasErrors('longitude');
        $this->actingAs($company->hr)->put('/offices/'.$company->officeId, $this->payload(['latitude' => 22.5, 'longitude' => null]))->assertSessionHasErrors('longitude');
        $this->actingAs($company->hr)->put('/offices/'.$company->officeId, $this->payload(['verification_radius_meters' => 24]))->assertSessionHasErrors('verification_radius_meters');
        $this->actingAs($company->hr)->put('/offices/'.$company->officeId, $this->payload(['verification_radius_meters' => 2001]))->assertSessionHasErrors('verification_radius_meters');
        $this->actingAs($company->hr)->put('/offices/'.$company->officeId, $this->payload([
            'gps_verification_enabled' => true,
            'latitude' => null,
            'longitude' => null,
        ]))->assertSessionHasErrors('latitude');
        $this->assertNull(DB::table('locations')->where('id', $company->officeId)->value('latitude'));

        $this->actingAs($company->hr)->put('/offices/'.$company->officeId, $this->payload([
            'latitude' => -90,
            'longitude' => 180,
            'verification_radius_meters' => 2000,
            'gps_verification_enabled' => true,
        ]))->assertSessionHasNoErrors();
    }

    public function test_office_page_reports_ready_only_when_server_rules_allow_clock_in(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        DB::table('locations')->where('id', $company->officeId)->update(['status' => 'active', 'timezone' => 'UTC']);

        $this->actingAs($company->hr)->get('/offices')->assertInertia(fn (Assert $page) => $page
            ->component('offices/index')
            ->where('locationFieldsAvailable', true)
            ->where('offices.0.verification.locationConfigured', false)
            ->where('offices.0.verification.networkConfigured', false)
            ->where('offices.0.verification.attendanceReady', false)
            ->where('offices.0.verification.message', 'No approved public network is configured, so clock-in is blocked.'));

        DB::table('location_networks')->insert([
            'tenant_id' => $company->id,
            'location_id' => $company->officeId,
            'cidr' => '203.0.113.0/24',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($company->hr)->get('/offices')->assertInertia(fn (Assert $page) => $page
            ->where('offices.0.verification.networkConfigured', true)
            ->where('offices.0.verification.gpsEnabled', false)
            ->where('offices.0.verification.attendanceReady', true));

        DB::table('locations')->where('id', $company->officeId)->update(['gps_verification_enabled' => true]);

        $this->actingAs($company->hr)->get('/offices')->assertInertia(fn (Assert $page) => $page
            ->where('offices.0.verification.attendanceReady', false)
            ->where('offices.0.verification.gpsEnabled', true));
    }

    public function test_gps_is_an_additional_server_check_and_browser_claims_cannot_bypass_it(): void
    {
        $company = $this->prepare($company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix'));
        $before = DB::table('attendance_logs')->count();
        DB::table('locations')->where('id', $company->officeId)->update([
            'latitude' => 22.572645,
            'longitude' => 88.363895,
            'verification_radius_meters' => 150,
            'gps_verification_enabled' => true,
        ]);

        $spoof = [
            'verified' => true,
            'gps_verified' => true,
            'within_radius' => true,
            'distance' => 0,
            'ip' => '203.0.113.10',
            'office_id' => $company->officeId,
            'latitude' => 0,
            'longitude' => 0,
        ];

        $this->actingAs($company->employee)->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/attendance/clock-in', $spoof)
            ->assertSessionHasErrors(['attendance' => 'You are outside the configured office location. Clock-in is blocked.']);
        $this->assertSame($before, DB::table('attendance_logs')->count());

        $this->actingAs($company->employee)->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->post('/attendance/clock-in', ['latitude' => 22.572645, 'longitude' => 88.363895, 'verified' => true])
            ->assertSessionHasErrors(['attendance' => 'You are not connected through an approved office network. Clock-in is blocked.']);
        $this->assertSame($before, DB::table('attendance_logs')->count());

        $this->actingAs($company->employee)->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/attendance/clock-in', ['verified' => false])
            ->assertSessionHasErrors(['attendance' => 'A browser location inside the office radius is required. Clock-in is blocked.']);

        $other = CompanyFixture::make('Deep Data Inc', 'deep-data');
        $this->actingAs($company->employee)->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/attendance/clock-in', [
                'office_id' => $other->officeId,
                'latitude' => 22.572645,
                'longitude' => 88.363895,
            ])
            ->assertSessionHasErrors(['attendance' => 'You are not authorized to attend that office.']);

        $this->actingAs($company->employee)->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/attendance/clock-in', ['latitude' => 22.572645, 'longitude' => 88.363895, 'gps_verified' => false])
            ->assertSessionHasNoErrors();

        $log = DB::table('attendance_logs')->where('employee_id', $company->reportEmployeeId)->whereDate('work_date', '2026-10-19')->first();
        $this->assertSame('public_ip_and_gps', $log->verified_as);
        $this->assertSame('203.0.113.10', $log->check_in_ip);
        $this->assertSame($company->officeId, (int) $log->location_id);
        $this->assertEqualsWithDelta(22.57265, (float) $log->check_in_latitude, 0.0000001);
        $this->assertEqualsWithDelta(88.3639, (float) $log->check_in_longitude, 0.0000001);
        $this->assertSame(0, (int) $log->check_in_distance_meters);

        $this->actingAs($company->employee)->get('/my-attendance')->assertInertia(fn (Assert $page) => $page
            ->where('history.days', function ($days): bool {
                $day = collect($days)->firstWhere('date', '2026-10-19');

                return is_array($day)
                    && $day['networkStatus'] === 'verified'
                    && $day['verification'] === 'Approved public network and office GPS'
                    && (int) $day['clockInDistance'] === 0;
            }));

        $this->actingAs($company->employee)->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->post('/attendance/clock-out', ['latitude' => 22.572645, 'longitude' => 88.363895, 'ip' => '203.0.113.10', 'verified' => true, 'distance' => 0])
            ->assertSessionHasErrors(['attendance' => 'You are not connected through an approved office network. Clock-out is blocked.']);
        $this->actingAs($company->employee)->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/attendance/clock-out', ['latitude' => 0, 'longitude' => 0, 'verified' => true, 'distance' => 0, 'ip' => '203.0.113.10'])
            ->assertSessionHasErrors(['attendance' => 'You are outside the configured office location. Clock-out is blocked.']);
        $this->assertNull(DB::table('attendance_logs')->where('id', $log->id)->value('check_out'));

        $this->actingAs($company->employee)->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/attendance/clock-out', ['latitude' => 22.572645, 'longitude' => 88.363895, 'ip' => '192.168.0.174', 'verified' => true, 'distance' => 999])
            ->assertSessionHasNoErrors();
        $closed = DB::table('attendance_logs')->where('id', $log->id)->first();
        $this->assertSame('203.0.113.10', $closed->check_out_ip);
        $this->assertSame('203.0.113.10', $closed->check_in_ip);
        $this->assertSame(0, (int) $closed->check_out_distance_meters);
        $this->assertSame('public_ip_and_gps', $closed->verified_as);
    }

    public function test_gps_does_not_replace_the_network_requirement_and_disabled_gps_ignores_coordinates(): void
    {
        $company = $this->prepare(CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix'));
        DB::table('location_networks')->where('location_id', $company->officeId)->delete();
        DB::table('locations')->where('id', $company->officeId)->update([
            'latitude' => 22.572645,
            'longitude' => 88.363895,
            'verification_radius_meters' => 150,
            'gps_verification_enabled' => true,
        ]);

        $this->actingAs($company->employee)->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/attendance/clock-in', ['latitude' => 22.572645, 'longitude' => 88.363895])
            ->assertSessionHasErrors(['attendance' => 'You are not connected through an approved office network. Clock-in is blocked.']);
        $this->assertSame(0, DB::table('attendance_logs')->whereDate('work_date', '2026-10-19')->count());

        DB::table('location_networks')->insert([
            'tenant_id' => $company->id,
            'location_id' => $company->officeId,
            'cidr' => '203.0.113.0/24',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('locations')->where('id', $company->officeId)->update([
            'latitude' => null,
            'longitude' => null,
            'verification_radius_meters' => 10,
        ]);

        $this->actingAs($company->employee)->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/attendance/clock-in', ['latitude' => 22.572645, 'longitude' => 88.363895])
            ->assertSessionHasErrors(['attendance' => 'GPS verification is enabled, but this office has no saved location. Clock-in is blocked.']);

        DB::table('locations')->where('id', $company->officeId)->update([
            'latitude' => 22.572645,
            'longitude' => 88.363895,
        ]);
        $this->actingAs($company->employee)->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/attendance/clock-in', ['latitude' => 22.572645, 'longitude' => 88.363895])
            ->assertSessionHasErrors(['attendance' => 'This office location radius is not valid. Clock-in is blocked.']);

        DB::table('locations')->where('id', $company->officeId)->update([
            'gps_verification_enabled' => false,
            'verification_radius_meters' => 150,
        ]);
        $this->actingAs($company->employee)->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/attendance/clock-in', ['latitude' => 0, 'longitude' => 0, 'verified' => true, 'gps_verified' => true])
            ->assertSessionHasNoErrors();
        $log = DB::table('attendance_logs')->whereDate('work_date', '2026-10-19')->first();
        $this->assertSame('public_ip', $log->verified_as);
        $this->assertSame('203.0.113.10', $log->check_in_ip);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Head Office',
            'address' => '12 Park Street, Kolkata',
            'timezone' => 'Asia/Kolkata',
            'status' => 'active',
            'latitude' => 22.572645,
            'longitude' => 88.363895,
            'verification_radius_meters' => 150,
            'gps_verification_enabled' => false,
        ], $overrides);
    }

    private function owner(CompanyFixture $company): User
    {
        $roleId = DB::table('roles')->insertGetId([
            'tenant_id' => $company->id,
            'name' => 'Owner',
            'guard_name' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = User::factory()->create();
        $user->forceFill([
            'tenant_id' => $company->id,
            'is_platform_admin' => false,
            'invite_accepted_at' => now(),
        ])->save();
        DB::table('model_has_roles')->insert([
            'role_id' => $roleId,
            'model_type' => User::class,
            'model_id' => $user->id,
            'tenant_id' => $company->id,
        ]);

        return $user->refresh();
    }

    private function prepare(CompanyFixture $company): CompanyFixture
    {
        DB::table('locations')->where('id', $company->officeId)->update([
            'timezone' => 'UTC',
            'status' => 'active',
        ]);
        DB::table('tenants')->where('id', $company->id)->update(['timezone' => 'UTC']);
        DB::table('location_networks')->insert([
            'tenant_id' => $company->id,
            'location_id' => $company->officeId,
            'cidr' => '203.0.113.0/24',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $shiftId = DB::table('shifts')->insertGetId([
            'tenant_id' => $company->id,
            'name' => 'General',
            'type' => 'fixed',
            'start_time' => '09:30:00',
            'end_time' => '18:30:00',
            'expected_minutes' => 540,
            'grace_minutes' => 10,
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('employees')->where('tenant_id', $company->id)->update(['shift_id' => $shiftId, 'location_id' => $company->officeId]);

        return $company;
    }
}
