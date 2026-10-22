<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Hrms\GpsCapture;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CompanyFixture;
use Tests\TestCase;

class RouteMapSafetyTest extends TestCase
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

    public function test_office_coordinates_save_edit_clear_and_reload_without_touching_other_settings(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $other = CompanyFixture::make('Deep Data Inc', 'deep');
        $owner = $this->member($company, 'Owner');
        DB::table('locations')->where('id', $company->officeId)->update(['address' => '12 Park Street', 'timezone' => 'Asia/Kolkata']);
        $networkId = DB::table('location_networks')->insertGetId([
            'tenant_id' => $company->id,
            'location_id' => $company->officeId,
            'cidr' => '203.0.113.0/24',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($owner)->get('/offices')->assertInertia(fn (Assert $page) => $page
            ->where('locationFieldsAvailable', true)
            ->where('mapLabelAvailable', true));

        $this->actingAs($owner)->put('/offices/'.$company->officeId, $this->payload(['map_label' => 'Park Street HQ']))->assertSessionHasNoErrors();
        $this->actingAs($owner)->get('/offices')->assertInertia(fn (Assert $page) => $page
            ->where('offices.0.mapLabel', 'Park Street HQ')
            ->where('offices.0.address', '12 Park Street')
            ->where('offices.0.timezone', 'Asia/Kolkata')
            ->where('offices.0.networks.0.id', $networkId)
            ->where('offices.0.latitude', fn ($value) => abs((float) $value - 22.572645) < 0.0000001));
        $this->actingAs($owner)->get('/route-map')->assertInertia(fn (Assert $page) => $page
            ->where('map.offices.0.mapLabel', 'Park Street HQ')
            ->where('map.offices.0.onMap', true)
            ->where('map.offices.0.latitude', fn ($value) => abs((float) $value - 22.572645) < 0.0000001)
            ->where('map.offices.0.longitude', fn ($value) => abs((float) $value - 88.363895) < 0.0000001));

        $this->actingAs($company->hr)->put('/offices/'.$company->officeId, $this->payload(['latitude' => '19.0760', 'longitude' => '72.8777', 'map_label' => '', 'verification_radius_meters' => 300]))->assertSessionHasNoErrors();
        $row = DB::table('locations')->where('id', $company->officeId)->first();
        $this->assertEqualsWithDelta(19.076, (float) $row->latitude, 0.0000001);
        $this->assertSame(300, (int) $row->verification_radius_meters);
        $this->assertNull($row->map_label);

        foreach ([['latitude' => 'abc'], ['longitude' => '72.8.7'], ['latitude' => '', 'longitude' => '72.8777'], ['latitude' => 91], ['longitude' => 181]] as $bad) {
            $this->actingAs($company->hr)->put('/offices/'.$company->officeId, $this->payload($bad))->assertSessionHasErrors();
        }
        $this->assertEqualsWithDelta(19.076, (float) DB::table('locations')->where('id', $company->officeId)->value('latitude'), 0.0000001);

        $this->actingAs($company->hr)->put('/offices/'.$company->officeId, $this->payload(['latitude' => '', 'longitude' => '']))->assertSessionHasNoErrors();
        $cleared = DB::table('locations')->where('id', $company->officeId)->first();
        $this->assertNull($cleared->latitude);
        $this->assertNull($cleared->longitude);
        $this->assertSame('12 Park Street', $cleared->address);
        $this->assertSame(1, DB::table('location_networks')->where('location_id', $company->officeId)->count());
        $this->actingAs($company->hr)->get('/route-map')->assertInertia(fn (Assert $page) => $page
            ->where('map.offices.0.onMap', false)
            ->where('map.offices.0.latitude', null));

        $this->actingAs($company->hr)->put('/offices/'.$other->officeId, $this->payload())->assertNotFound();
        $platform = $this->platform();
        $this->assertContains($this->actingAs($platform)->put('/offices/'.$company->officeId, $this->payload(['latitude' => 1, 'longitude' => 1]))->status(), [403, 404]);
        $this->assertNull(DB::table('locations')->where('id', $company->officeId)->value('latitude'));
        $this->assertNull(DB::table('locations')->where('id', $other->officeId)->value('latitude'));
    }

    public function test_super_admin_receives_office_pins_and_never_employee_coordinates(): void
    {
        $company = $this->readyCompany();
        $owner = $this->member($company, 'Owner');
        DB::table('locations')->where('id', $company->officeId)->update(['latitude' => 22.5, 'longitude' => 88.3]);
        $this->actingAs($owner)->put('/company/settings/gps-capture', ['enabled' => true])->assertRedirect();
        $this->actingAs($company->employee)->post('/route-map/acknowledgement')->assertRedirect();
        $this->clock($company, ['latitude' => 22.572645, 'longitude' => 88.363895, 'accuracy_meters' => 9, 'capture_result' => 'recorded']);
        $this->assertSame(1, DB::table('attendance_location_records')->where('status', 'recorded')->count());

        $platform = $this->platform();
        $response = $this->actingAs($platform)->get('/route-map?employee='.$company->reportEmployeeId.'&from=2026-10-01&to=2026-10-31');
        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('map.canViewPreciseLocations', false)
            ->where('map.points', [])
            ->where('map.acknowledgements', [])
            ->where('map.employees', [])
            ->where('map.filters.employee', ''));
        $this->assertStringNotContainsString('22.572645', $response->getContent());
        $this->assertStringNotContainsString('88.363895', $response->getContent());
        $this->assertSame(0, DB::table('attendance_location_view_events')->where('actor_id', $platform->id)->count());
        $this->actingAs($platform)->put('/company/settings/gps-capture', ['enabled' => false])->assertForbidden();
        $this->actingAs($platform)->post('/route-map/acknowledgement')->assertNotFound();
        $this->actingAs($platform)->get('/dashboard')->assertDontSee('22.572645');

        $accounts = $this->member($company, 'Accounts');
        $denied = $this->actingAs($accounts)->get('/route-map');
        $denied->assertInertia(fn (Assert $page) => $page->component('hrms/forbidden'));
        $this->assertStringNotContainsString('22.572645', $denied->getContent());
    }

    public function test_a_changed_notice_or_retention_period_requires_a_new_acknowledgement(): void
    {
        $company = $this->readyCompany();
        $owner = $this->member($company, 'Owner');
        $this->actingAs($owner)->put('/company/settings/gps-capture', ['enabled' => true])->assertRedirect();
        $this->actingAs($company->employee)->post('/route-map/acknowledgement')->assertRedirect();
        $this->assertSame(GpsCapture::NOTICE_VERSION.'.r30', DB::table('gps_attendance_acknowledgements')->value('notice_version'));

        config(['hrms.gps_location_retention_days' => 14]);
        $this->actingAs($company->employee)->get('/my-attendance')->assertInertia(fn (Assert $page) => $page
            ->where('timing.gpsCapture', false)
            ->where('timing.gpsNoticePending', true));
        $this->clock($company, ['latitude' => 22.5, 'longitude' => 88.3, 'capture_result' => 'recorded']);
        $this->assertSame(0, DB::table('attendance_location_records')->count());
        $this->actingAs($company->employee)->get('/route-map')->assertInertia(fn (Assert $page) => $page
            ->where('map.acknowledged', false)
            ->where('map.canAcknowledge', true)
            ->where('map.retention', '14 days after capture'));
        $this->actingAs($company->employee)->post('/route-map/acknowledgement')->assertRedirect();
        $this->assertSame(2, DB::table('gps_attendance_acknowledgements')->count());
    }

    public function test_invalid_retention_stops_capture_and_cleanup_without_deleting(): void
    {
        $company = $this->readyCompany();
        $owner = $this->member($company, 'Owner');
        $this->actingAs($owner)->put('/company/settings/gps-capture', ['enabled' => true])->assertRedirect();
        $this->actingAs($company->employee)->post('/route-map/acknowledgement')->assertRedirect();
        $this->insertRecord($company, now()->subDays(400));

        foreach ([0, -5, 'abc', '30.5', 5000] as $bad) {
            config(['hrms.gps_location_retention_days' => $bad]);
            $this->assertNull(app(GpsCapture::class)->retentionDays());
            $this->assertSame(1, Artisan::call('hrms:prune-gps-locations'));
            $this->assertSame(1, DB::table('attendance_location_records')->count());
        }

        $this->clock($company, ['latitude' => 22.5, 'longitude' => 88.3, 'capture_result' => 'recorded']);
        $this->assertSame(1, DB::table('attendance_location_records')->count());
        $this->actingAs($company->employee)->get('/my-attendance')->assertInertia(fn (Assert $page) => $page->where('timing.gpsCapture', false));
    }

    public function test_cleanup_deletes_only_expired_records_per_company_and_audits_counts_only(): void
    {
        $company = $this->readyCompany();
        $other = CompanyFixture::make('Deep Data Inc', 'deep');
        $this->assertSame(30, app(GpsCapture::class)->retentionDays());

        DB::table('gps_attendance_acknowledgements')->insert([
            'tenant_id' => $company->id,
            'employee_id' => $company->reportEmployeeId,
            'user_id' => $company->employee->id,
            'notice_version' => '2026-10-22.1.r30',
            'acknowledged_at' => now(),
        ]);
        DB::table('attendance_location_view_events')->insert([
            'tenant_id' => $company->id,
            'actor_id' => $company->employee->id,
            'subject' => 's:e'.$company->reportEmployeeId.':20260101:20260102',
            'created_at' => now(),
        ]);

        $expired = $this->insertRecord($company, now()->subDays(31));
        $boundary = $this->insertRecord($company, now()->subDays(30)->addMinute(), 'clock_out', $expired['log']);
        $fresh = $this->insertRecord($company, now()->subDays(2));
        $otherExpired = $this->insertRecord($other, now()->subDays(45));
        $otherFresh = $this->insertRecord($other, now()->subDay());

        $this->assertSame(0, Artisan::call('hrms:prune-gps-locations', ['--dry-run' => true]));
        $this->assertSame(5, DB::table('attendance_location_records')->count());
        $this->assertSame(0, DB::table('attendance_location_retention_events')->count());

        $this->assertSame(0, Artisan::call('hrms:prune-gps-locations'));
        $output = Artisan::output();
        $this->assertStringContainsString('deleted 2 location records across 2 companies', $output);
        $this->assertStringNotContainsString('22.5', $output);
        $remaining = DB::table('attendance_location_records')->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $this->assertSame([$boundary['id'], $fresh['id'], $otherFresh['id']], $remaining);
        $this->assertFalse(DB::table('attendance_location_records')->whereIn('id', [$expired['id'], $otherExpired['id']])->exists());
        $this->assertSame(2, DB::table('attendance_logs')->whereIn('id', [$expired['log'], $otherExpired['log']])->count());

        $events = DB::table('attendance_location_retention_events')->orderBy('tenant_id')->get();
        $this->assertCount(2, $events);
        $this->assertSame([$company->id, $other->id], $events->pluck('tenant_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame([1, 1], $events->pluck('deleted_count')->map(fn ($n) => (int) $n)->all());
        $this->assertSame(30, (int) $events->first()->retention_days);
        $this->assertStringNotContainsString('22.5', json_encode($events));

        $this->assertSame(0, Artisan::call('hrms:prune-gps-locations'));
        $this->assertSame(2, DB::table('attendance_location_retention_events')->count());
        $this->assertSame(3, DB::table('attendance_location_records')->count());
        $this->assertSame(1, DB::table('gps_attendance_acknowledgements')->count());
        $this->assertSame(1, DB::table('attendance_location_view_events')->count());
        $this->assertSame(4, DB::table('attendance_logs')->whereIn('id', [$expired['log'], $fresh['log'], $otherExpired['log'], $otherFresh['log']])->count());

        $scheduled = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'hrms:prune-gps-locations'));
        $this->assertNotNull($scheduled);
        $this->assertSame('0 0 * * *', $scheduled->expression);
    }

    public function test_gps_capture_migration_is_idempotent_and_reversible_on_sqlite(): void
    {
        CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $migration = require base_path('database/migrations/2026_10_22_120000_add_route_map_gps_capture.php');
        $tables = [
            'company_attendance_settings',
            'company_attendance_setting_events',
            'gps_attendance_acknowledgements',
            'attendance_location_records',
            'attendance_location_view_events',
            'attendance_location_retention_events',
        ];

        $migration->up();
        foreach ($tables as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }

        $migration->down();
        foreach ($tables as $table) {
            $this->assertFalse(Schema::hasTable($table), $table);
        }
        $this->assertFalse(Schema::hasColumn('locations', 'map_label'));
        $this->assertTrue(Schema::hasColumn('locations', 'latitude'));
        $this->assertTrue(Schema::hasTable('attendance_logs'));

        $migration->up();
        $migration->up();
        foreach ($tables as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }
        $this->assertTrue(Schema::hasColumn('locations', 'map_label'));
        $this->assertFalse((bool) DB::table('company_attendance_settings')->exists());
    }

    /**
     * @return array{id: int, log: int}
     */
    private function insertRecord(CompanyFixture $company, Carbon $at, string $event = 'clock_in', ?int $logId = null): array
    {
        $logId ??= DB::table('attendance_logs')->insertGetId([
            'tenant_id' => $company->id,
            'employee_id' => $company->reportEmployeeId,
            'location_id' => $company->officeId,
            'work_date' => $at->toDateString(),
            'check_in' => $at->toDateTimeString(),
            'check_out' => $at->copy()->addHours(8)->toDateTimeString(),
            'status' => 'present',
            'created_at' => $at,
            'updated_at' => $at,
        ]);
        $id = DB::table('attendance_location_records')->insertGetId([
            'tenant_id' => $company->id,
            'employee_id' => $company->reportEmployeeId,
            'attendance_log_id' => $logId,
            'event_type' => $event,
            'latitude' => 22.5,
            'longitude' => 88.3,
            'accuracy_meters' => 10,
            'status' => 'recorded',
            'captured_at' => $at,
        ]);

        return ['id' => (int) $id, 'log' => (int) $logId];
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

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Head Office',
            'address' => '12 Park Street',
            'timezone' => 'Asia/Kolkata',
            'status' => 'active',
            'latitude' => 22.572645,
            'longitude' => 88.363895,
            'verification_radius_meters' => 150,
            'gps_verification_enabled' => false,
        ], $overrides);
    }

    private function platform(): User
    {
        $platform = User::factory()->create();
        $platform->forceFill(['is_platform_admin' => true, 'tenant_id' => null, 'invite_accepted_at' => now()])->save();

        return $platform;
    }

    private function member(CompanyFixture $company, string $role): User
    {
        $user = User::factory()->create();
        $user->forceFill([
            'tenant_id' => $company->id,
            'is_platform_admin' => false,
            'invite_accepted_at' => now(),
        ])->save();
        $roleId = DB::table('roles')->where('tenant_id', $company->id)->where('name', $role)->value('id')
            ?? DB::table('roles')->insertGetId([
                'tenant_id' => $company->id,
                'name' => $role,
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        DB::table('model_has_roles')->insert([
            'role_id' => $roleId,
            'model_type' => User::class,
            'model_id' => $user->id,
            'tenant_id' => $company->id,
        ]);

        return $user;
    }
}
