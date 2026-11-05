<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Hrms\GpsCapture;
use App\Support\MapTiles;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CompanyFixture;
use Tests\TestCase;

class RouteMapGpsTest extends TestCase
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

    public function test_gps_capture_is_disabled_until_a_company_admin_enables_it(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $owner = $this->owner($company);

        $this->actingAs($company->employee)->get('/my-attendance')->assertInertia(fn (Assert $page) => $page
            ->where('timing.gpsCapture', false)
            ->where('timing.gpsNoticePending', false));
        $this->assertFalse((bool) DB::table('company_attendance_settings')->where('tenant_id', $company->id)->value('gps_capture_enabled'));

        $this->actingAs($company->hr)->put('/company/settings/gps-capture', ['enabled' => true])->assertForbidden();
        $this->actingAs($company->employee)->put('/company/settings/gps-capture', ['enabled' => true])->assertForbidden();
        $this->actingAs($owner)->put('/company/settings/gps-capture', ['enabled' => true])->assertRedirect();
        $this->assertSame(1, (int) DB::table('company_attendance_settings')->where('tenant_id', $company->id)->value('gps_capture_enabled'));
        $this->assertSame(1, DB::table('company_attendance_setting_events')->where('tenant_id', $company->id)->where('action', 'enabled')->count());
        $this->actingAs($company->employee)->get('/my-attendance')->assertInertia(fn (Assert $page) => $page
            ->where('timing.gpsCapture', false)
            ->where('timing.gpsNoticePending', true));
    }

    public function test_coordinates_are_saved_only_after_acknowledgement_and_a_successful_attendance_action(): void
    {
        $company = $this->readyCompany();
        $owner = $this->owner($company);
        $this->actingAs($owner)->put('/company/settings/gps-capture', ['enabled' => true])->assertRedirect();

        $this->clock($company, ['latitude' => 22.572645, 'longitude' => 88.363895, 'capture_result' => 'recorded']);
        $this->assertSame(0, DB::table('attendance_location_records')->count());
        $this->assertSame(1, DB::table('attendance_logs')->where('employee_id', $company->reportEmployeeId)->whereDate('work_date', '2026-10-19')->count());
        $this->close($company);

        $this->actingAs($company->employee)->post('/route-map/acknowledgement')->assertRedirect();
        $this->assertSame(1, DB::table('gps_attendance_acknowledgements')->where('employee_id', $company->reportEmployeeId)->count());
        $this->actingAs($company->employee)->post('/route-map/acknowledgement')->assertRedirect();
        $this->assertSame(1, DB::table('gps_attendance_acknowledgements')->count());

        Carbon::setTestNow('2026-10-23 09:00:00');
        $this->clock($company, ['latitude' => 22.57264549, 'longitude' => 88.36389449, 'accuracy_meters' => 12, 'capture_result' => 'recorded']);
        $saved = DB::table('attendance_location_records')->where('event_type', 'clock_in')->first();
        $this->assertNotNull($saved);
        $this->assertEqualsWithDelta(22.57265, (float) $saved->latitude, 0.0000001);
        $this->assertEqualsWithDelta(88.36389, (float) $saved->longitude, 0.0000001);
        $this->assertSame('recorded', $saved->status);
        $this->assertSame(12, (int) $saved->accuracy_meters);
        $this->assertSame($company->id, (int) $saved->tenant_id);
        $this->assertSame($company->reportEmployeeId, (int) $saved->employee_id);

        $this->actingAs($company->employee)->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/attendance/clock-in', ['latitude' => 22.57, 'longitude' => 88.36, 'capture_result' => 'recorded'])
            ->assertSessionHasErrors('attendance');
        $this->assertSame(1, DB::table('attendance_location_records')->where('event_type', 'clock_in')->count());

        $this->actingAs($company->employee)->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/attendance/clock-out', ['latitude' => 22.5727, 'longitude' => 88.3639, 'capture_result' => 'recorded'])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('attendance_location_records')->where('event_type', 'clock_out')->where('status', 'recorded')->count());
        $this->actingAs($company->employee)->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/attendance/clock-out', ['latitude' => 22.5727, 'longitude' => 88.3639])
            ->assertSessionHasErrors('attendance');
        $this->assertSame(1, DB::table('attendance_location_records')->where('event_type', 'clock_out')->count());
    }

    public function test_failed_or_disabled_capture_does_not_store_coordinates_or_bypass_the_network_check(): void
    {
        $company = $this->readyCompany();
        $owner = $this->owner($company);
        $this->actingAs($owner)->put('/company/settings/gps-capture', ['enabled' => true])->assertRedirect();
        $this->actingAs($company->employee)->post('/route-map/acknowledgement')->assertRedirect();

        $this->actingAs($company->employee)->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->post('/attendance/clock-in', ['latitude' => 22.572645, 'longitude' => 88.363895, 'capture_result' => 'recorded'])
            ->assertSessionHasErrors('attendance');
        $this->assertSame(0, DB::table('attendance_logs')->whereDate('work_date', '2026-10-19')->count());
        $this->assertSame(0, DB::table('attendance_location_records')->count());

        $this->clock($company, ['latitude' => 22.572645, 'longitude' => 200, 'capture_result' => 'recorded']);
        $invalid = DB::table('attendance_location_records')->where('event_type', 'clock_in')->first();
        $this->assertSame('invalid', $invalid->status);
        $this->assertNull($invalid->latitude);
        $this->assertNull($invalid->longitude);
        $this->close($company);

        Carbon::setTestNow('2026-10-23 09:00:00');
        $this->clock($company, ['latitude' => 22.572645, 'longitude' => 88.363895, 'capture_result' => 'denied']);
        $denied = DB::table('attendance_location_records')->where('event_type', 'clock_in')->whereDate('captured_at', '2026-10-23')->first();
        $this->assertSame('denied', $denied->status);
        $this->assertNull($denied->latitude);
        $this->close($company);

        Carbon::setTestNow('2026-10-21 09:00:00');
        $this->clock($company, ['capture_result' => 'unavailable']);
        $this->assertSame('unavailable', DB::table('attendance_location_records')->where('event_type', 'clock_in')->whereDate('captured_at', '2026-10-21')->value('status'));
        $this->close($company);

        $this->actingAs($owner)->put('/company/settings/gps-capture', ['enabled' => false])->assertRedirect();
        Carbon::setTestNow('2026-10-22 09:00:00');
        $this->clock($company, ['latitude' => 22.572645, 'longitude' => 88.363895, 'capture_result' => 'recorded']);
        $this->assertSame(0, DB::table('attendance_location_records')->whereDate('captured_at', '2026-10-22')->count());
        $this->assertSame(1, DB::table('attendance_logs')->whereDate('work_date', '2026-10-22')->count());
    }

    public function test_precise_locations_stay_inside_company_and_reporting_scope(): void
    {
        $company = $this->readyCompany();
        $other = CompanyFixture::make('Deep Data Inc', 'deep');
        $owner = $this->owner($company);
        DB::table('locations')->where('id', $company->officeId)->update([
            'latitude' => 22.572645,
            'longitude' => 88.363895,
            'address' => '12 Park Street',
            'map_label' => 'Park Street office',
        ]);
        DB::table('locations')->where('id', $other->officeId)->update([
            'latitude' => 12.9716,
            'longitude' => 77.5946,
            'address' => 'Other Company Road',
            'status' => 'active',
        ]);
        $this->actingAs($owner)->put('/company/settings/gps-capture', ['enabled' => true])->assertRedirect();
        $this->actingAs($company->employee)->post('/route-map/acknowledgement')->assertRedirect();
        $this->clock($company, ['latitude' => 22.572645, 'longitude' => 88.363895, 'capture_result' => 'recorded']);

        $this->actingAs($company->hr)->get('/route-map')->assertOk()->assertSee('Park Street office')->assertDontSee('Other Company Road');
        $this->actingAs($company->hr)->get('/route-map?employee='.$other->reportEmployeeId)->assertNotFound();
        $this->actingAs($company->employee)->get('/route-map?employee='.$company->outsideEmployeeId)->assertNotFound();
        $this->actingAs($company->employee)->get('/route-map')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('map.points.0.status', 'Recorded')
            ->where('map.points.0.latitude', 22.57265)
            ->where('mapTiles.template', MapTiles::DEFAULT_TEMPLATE)
            ->where('mapTiles.disclosure', 'Opening this map loads tiles from OpenStreetMap and sends the area you are viewing to that service. No API key is used.'));
        $this->actingAs($company->lead)->get('/route-map')->assertInertia(fn (Assert $page) => $page->component('hrms/forbidden')->missing('map'));
        $this->actingAs($company->lead)->get('/route-map?employee='.$company->outsideEmployeeId)->assertInertia(fn (Assert $page) => $page->component('hrms/forbidden')->missing('map'));

        $accounts = $this->owner($company, 'Accounts');
        $this->actingAs($accounts)->get('/route-map')->assertInertia(fn (Assert $page) => $page->component('hrms/forbidden'));
        $platform = User::factory()->create();
        $platform->forceFill(['is_platform_admin' => true, 'tenant_id' => null, 'invite_accepted_at' => now()])->save();
        $this->actingAs($platform)->get('/route-map')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('map.canViewPreciseLocations', false)
            ->where('map.points', [])
            ->where('map.offices', fn ($offices) => collect($offices)->contains(fn ($office) => $office['mapLabel'] === 'Park Street office')));
        $views = DB::table('attendance_location_view_events')->where('tenant_id', $company->id)->orderBy('id')->get();
        $this->assertCount(2, $views);
        $this->assertSame([
            'c:e'.$company->reportEmployeeId.':20261013:20261019',
            's:e'.$company->reportEmployeeId.':20261013:20261019',
        ], $views->pluck('subject')->all());
        $this->assertTrue($views->every(fn ($view) => strlen((string) $view->subject) <= 40));
        $encoded = json_encode($views);
        $this->assertStringNotContainsString('22.57265', $encoded);
        $this->assertStringNotContainsString('88.36389', $encoded);
        $this->assertSame('c:n2:20261001:20261019', GpsCapture::viewAuditSubject('hr', [4, 9], '2026-10-01', '2026-10-19'));
        $this->assertSame('c', GpsCapture::viewAuditSubject('hr', [1], '2026-10-01', str_repeat('9', 40)));
    }

    public function test_map_tile_template_stays_on_openstreetmap_unless_the_replacement_is_a_safe_https_template(): void
    {
        $this->assertSame(MapTiles::DEFAULT_TEMPLATE, MapTiles::template());

        config(['hrms.map_tiles.url_template' => 'javascript:alert(1)']);
        $this->assertSame(MapTiles::DEFAULT_TEMPLATE, MapTiles::template());

        config(['hrms.map_tiles.url_template' => 'https://tiles.example.test/{z}/{x}/{y}.png']);
        $this->assertSame('https://tiles.example.test/{z}/{x}/{y}.png', MapTiles::template());
        $this->assertStringContainsString('tiles.example.test', MapTiles::payload()['disclosure']);
        $this->assertStringNotContainsString('OpenStreetMap', MapTiles::payload()['disclosure']);
    }

    private function readyCompany(): CompanyFixture
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        DB::table('locations')->where('id', $company->officeId)->update(['status' => 'active', 'timezone' => 'UTC']);
        DB::table('location_networks')->insert([
            'tenant_id' => $company->id,
            'location_id' => $company->officeId,
            'cidr' => '203.0.113.0/24',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $company;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function clock(CompanyFixture $company, array $payload): void
    {
        $this->actingAs($company->employee)
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/attendance/clock-in', $payload)
            ->assertSessionHasNoErrors();
    }

    private function close(CompanyFixture $company): void
    {
        $this->actingAs($company->employee)
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/attendance/clock-out')
            ->assertSessionHasNoErrors();
    }

    private function owner(CompanyFixture $company, string $role = 'Owner'): User
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

        return $user;
    }
}
