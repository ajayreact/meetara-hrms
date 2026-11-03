<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CompanyFixture;
use Tests\TestCase;

class OfficeNetworkAttendanceTest extends TestCase
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

    public function test_an_approved_public_ip_can_clock_in(): void
    {
        $company = $this->prepare(CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix'));

        $this->from('/my-attendance')->actingAs($company->employee)
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/attendance/clock-in', ['ip' => '198.51.100.20', 'verified' => true, 'tenant_id' => 999])
            ->assertRedirect('/my-attendance')
            ->assertSessionHasNoErrors();

        $log = DB::table('attendance_logs')->where('employee_id', $company->reportEmployeeId)->whereDate('work_date', '2026-10-19')->first();
        $this->assertSame('203.0.113.10', $log->check_in_ip);
        $this->assertSame('public_ip', $log->verified_as);
        $this->assertSame($company->officeId, (int) $log->location_id);
        $this->assertSame($company->id, (int) $log->tenant_id);
        $this->assertSame('pending', $log->payroll_status);
    }

    public function test_exact_public_ipv4_matches_and_a_loopback_peer_stays_blocked(): void
    {
        $company = $this->prepare(CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix'));
        DB::table('location_networks')->where('location_id', $company->officeId)->update(['cidr' => '103.211.39.102']);
        $before = DB::table('attendance_logs')->count();

        $logged = [];
        Log::listen(function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event;
        });

        $this->from('/my-attendance')->actingAs($company->employee)
            ->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->post('/attendance/clock-in')
            ->assertRedirect('/my-attendance')
            ->assertSessionHasErrors([
                'attendance' => 'Clock-in was blocked because this connection is localhost. The server does not see the office public address.',
            ]);

        $this->assertStringNotContainsString('127.0.0.1', (string) session('errors')?->first('attendance'));
        $this->assertSame($before, DB::table('attendance_logs')->count());
        $denial = collect($logged)->first(fn (MessageLogged $event): bool => $event->message === 'Clock-in was denied by the approved office network check.');
        $this->assertNotNull($denial);
        $this->assertSame('warning', $denial->level);
        $this->assertSame('address_not_public', $denial->context['reason'] ?? null);
        $this->assertSame('127.0.0.1', $denial->context['resolved_ip'] ?? null);
        $this->assertSame('127.0.0.1', $denial->context['remote_addr'] ?? null);
        $this->assertFalse($denial->context['trusted_proxy_configured'] ?? true);
        $this->assertSame($company->id, $denial->context['tenant_id'] ?? null);
        $this->assertSame($company->officeId, $denial->context['office_id'] ?? null);

        $this->actingAs($company->employee)
            ->withServerVariables([
                'REMOTE_ADDR' => '127.0.0.1',
                'HTTP_X_FORWARDED_FOR' => '103.211.39.102',
            ])
            ->post('/attendance/clock-in')
            ->assertSessionHasErrors([
                'attendance' => 'Clock-in was blocked because a forwarded address arrived from an untrusted connection.',
            ]);
        $this->assertSame($before, DB::table('attendance_logs')->count());

        $this->actingAs($company->employee)
            ->withServerVariables(['REMOTE_ADDR' => '192.168.1.10'])
            ->post('/attendance/clock-in')
            ->assertSessionHasErrors([
                'attendance' => 'Clock-in was blocked because this connection is a private network address. The server does not see the office public address.',
            ]);

        $this->actingAs($company->employee)
            ->withServerVariables(['REMOTE_ADDR' => '103.211.39.102'])
            ->post('/attendance/clock-in')
            ->assertSessionHasNoErrors();
        $this->assertSame('103.211.39.102', DB::table('attendance_logs')->where('employee_id', $company->reportEmployeeId)->whereDate('work_date', '2026-10-19')->value('check_in_ip'));

        DB::table('attendance_logs')->where('employee_id', $company->reportEmployeeId)->whereDate('work_date', '2026-10-19')->delete();
        $this->actingAs($company->employee)
            ->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->post('/attendance/clock-in')
            ->assertSessionHasErrors([
                'attendance' => 'You are not connected through an approved office network. Clock-in is blocked.',
            ]);

        DB::table('location_networks')->where('location_id', $company->officeId)->update(['cidr' => '103.211.39.0/24']);
        $this->actingAs($company->employee)
            ->withServerVariables(['REMOTE_ADDR' => '103.211.39.102'])
            ->post('/attendance/clock-in')
            ->assertSessionHasNoErrors();
        DB::table('attendance_logs')->where('employee_id', $company->reportEmployeeId)->whereDate('work_date', '2026-10-19')->delete();
        $this->actingAs($company->employee)
            ->withServerVariables(['REMOTE_ADDR' => '103.211.40.1'])
            ->post('/attendance/clock-in')
            ->assertSessionHasErrors('attendance');

        DB::table('location_networks')->where('location_id', $company->officeId)->update(['cidr' => '2001:db8::/32']);
        $this->actingAs($company->employee)
            ->withServerVariables(['REMOTE_ADDR' => '2001:db8::10'])
            ->post('/attendance/clock-in')
            ->assertSessionHasNoErrors();
        $this->assertSame('2001:db8::10', DB::table('attendance_logs')->where('employee_id', $company->reportEmployeeId)->whereDate('work_date', '2026-10-19')->value('check_in_ip'));
    }

    public function test_a_loopback_peer_fails_before_gps_and_a_mapped_ipv4_peer_matches(): void
    {
        $company = $this->prepare(CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix'));
        DB::table('location_networks')->where('location_id', $company->officeId)->update(['cidr' => '103.211.39.102']);
        DB::table('locations')->where('id', $company->officeId)->update([
            'latitude' => 22.572645,
            'longitude' => 88.363895,
            'verification_radius_meters' => 150,
            'gps_verification_enabled' => true,
        ]);
        $inside = ['latitude' => 22.572645, 'longitude' => 88.363895];
        $before = DB::table('attendance_logs')->count();

        $this->actingAs($company->employee)
            ->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->post('/attendance/clock-in', $inside)
            ->assertSessionHasErrors([
                'attendance' => 'Clock-in was blocked because this connection is localhost. The server does not see the office public address.',
            ]);
        $this->assertSame($before, DB::table('attendance_logs')->count());

        $this->actingAs($company->employee)
            ->withServerVariables(['REMOTE_ADDR' => '::ffff:103.211.39.102'])
            ->post('/attendance/clock-in', ['latitude' => 0, 'longitude' => 0])
            ->assertSessionHasErrors([
                'attendance' => 'You are outside the configured office location. Clock-in is blocked.',
            ]);
        $this->assertSame($before, DB::table('attendance_logs')->count());

        $this->actingAs($company->employee)
            ->withServerVariables(['REMOTE_ADDR' => '::ffff:103.211.39.102'])
            ->post('/attendance/clock-in', $inside)
            ->assertSessionHasNoErrors();

        $log = DB::table('attendance_logs')->where('employee_id', $company->reportEmployeeId)->whereDate('work_date', '2026-10-19')->first();
        $this->assertSame('103.211.39.102', $log->check_in_ip);
        $this->assertSame('public_ip_and_gps', $log->verified_as);
        $this->assertSame($company->officeId, (int) $log->location_id);
        $this->assertSame($company->id, (int) $log->tenant_id);
    }

    public function test_local_peers_cannot_spoof_the_saved_public_address_and_gps_still_gates_clock_in(): void
    {
        $company = $this->prepare(CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix'));
        DB::table('location_networks')->where('location_id', $company->officeId)->update(['cidr' => '103.211.39.102']);
        DB::table('locations')->where('id', $company->officeId)->update([
            'latitude' => 22.572645,
            'longitude' => 88.363895,
            'verification_radius_meters' => 150,
            'gps_verification_enabled' => true,
        ]);
        $inside = ['latitude' => 22.572645, 'longitude' => 88.363895];
        $before = DB::table('attendance_logs')->count();

        foreach (['127.0.0.1', '192.168.0.174'] as $peer) {
            $this->actingAs($company->employee)
                ->withServerVariables([
                    'REMOTE_ADDR' => $peer,
                    'HTTP_X_FORWARDED_FOR' => '103.211.39.102',
                    'HTTP_X_REAL_IP' => '103.211.39.102',
                ])
                ->post('/attendance/clock-in', $inside)
                ->assertSessionHasErrors([
                    'attendance' => 'Clock-in was blocked because a forwarded address arrived from an untrusted connection.',
                ]);
            $direct = $peer === '127.0.0.1'
                ? 'Clock-in was blocked because this connection is localhost. The server does not see the office public address.'
                : 'Clock-in was blocked because this connection is a private network address. The server does not see the office public address.';
            $this->actingAs($company->employee)
                ->withServerVariables(['REMOTE_ADDR' => $peer])
                ->post('/attendance/clock-in', $inside)
                ->assertSessionHasErrors([
                    'attendance' => $direct,
                ]);
        }

        config(['attendance.trusted_proxies' => ['127.0.0.1', '192.168.0.174']]);
        $this->actingAs($company->employee)
            ->withServerVariables([
                'REMOTE_ADDR' => '192.168.0.174',
                'HTTP_X_FORWARDED_FOR' => '103.211.39.102',
            ])
            ->post('/attendance/clock-in', $inside)
            ->assertSessionHasErrors([
                'attendance' => 'Clock-in was blocked because a forwarded address arrived from an untrusted connection.',
            ]);
        $this->assertSame($before, DB::table('attendance_logs')->count());

        config(['attendance.trusted_proxies' => ['198.51.100.8']]);
        TrustProxies::at(['198.51.100.8']);
        $forwarded = [
            'REMOTE_ADDR' => '198.51.100.8',
            'HTTP_X_FORWARDED_FOR' => '103.211.39.102',
        ];

        $this->actingAs($company->employee)
            ->withServerVariables($forwarded)
            ->post('/attendance/clock-in', ['latitude' => 0, 'longitude' => 0])
            ->assertSessionHasErrors([
                'attendance' => 'You are outside the configured office location. Clock-in is blocked.',
            ]);
        $this->actingAs($company->employee)
            ->withServerVariables($forwarded)
            ->post('/attendance/clock-in')
            ->assertSessionHasErrors([
                'attendance' => 'A browser location inside the office radius is required. Clock-in is blocked.',
            ]);
        $this->actingAs($company->employee)
            ->withServerVariables($forwarded)
            ->post('/attendance/clock-in', ['capture_result' => 'denied'])
            ->assertSessionHasErrors([
                'attendance' => 'A browser location inside the office radius is required. Clock-in is blocked.',
            ]);
        $this->actingAs($company->employee)
            ->withServerVariables($forwarded)
            ->post('/attendance/clock-in', ['latitude' => 'not-a-coordinate', 'longitude' => 0])
            ->assertSessionHasErrors([
                'attendance' => 'A browser location inside the office radius is required. Clock-in is blocked.',
            ]);
        $this->assertSame($before, DB::table('attendance_logs')->count());

        $this->actingAs($company->employee)
            ->withServerVariables($forwarded)
            ->post('/attendance/clock-in', $inside)
            ->assertSessionHasNoErrors();
        $log = DB::table('attendance_logs')->where('employee_id', $company->reportEmployeeId)->whereDate('work_date', '2026-10-19')->first();
        $this->assertSame('103.211.39.102', $log->check_in_ip);
        $this->assertSame('public_ip_and_gps', $log->verified_as);
        TrustProxies::at([]);
    }

    public function test_a_network_on_another_office_or_tenant_does_not_authorize_clock_in(): void
    {
        $company = $this->prepare(CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix'));
        $other = $this->prepare(CompanyFixture::make('Deep Data Inc', 'deep-data'));
        $otherOffice = DB::table('locations')->insertGetId([
            'tenant_id' => $company->id,
            'name' => 'veltrix-annex',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('location_networks')->where('tenant_id', $company->id)->delete();
        DB::table('location_networks')->insert([
            [
                'tenant_id' => $company->id,
                'location_id' => $otherOffice,
                'cidr' => '103.211.39.102',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'tenant_id' => $other->id,
                'location_id' => $other->officeId,
                'cidr' => '103.211.39.102',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $before = DB::table('attendance_logs')->count();

        $this->actingAs($company->employee)
            ->withServerVariables(['REMOTE_ADDR' => '103.211.39.102'])
            ->post('/attendance/clock-in')
            ->assertSessionHasErrors([
                'attendance' => 'You are not connected through an approved office network. Clock-in is blocked.',
            ]);

        $this->assertSame($before, DB::table('attendance_logs')->count());
    }

    public function test_unapproved_missing_and_invalid_addresses_are_rejected(): void
    {
        $company = $this->prepare(CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix'));
        $before = DB::table('attendance_logs')->count();

        $this->actingAs($company->employee)->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])->post('/attendance/clock-in')->assertSessionHasErrors('attendance');
        $this->actingAs($company->employee)->withServerVariables(['REMOTE_ADDR' => 'not-an-ip'])->post('/attendance/clock-in')->assertSessionHasErrors('attendance');

        $this->assertSame($before, DB::table('attendance_logs')->count());
    }

    public function test_ipv6_and_cidr_ranges_match(): void
    {
        $company = $this->prepare(CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix'));
        DB::table('location_networks')->insert([
            'tenant_id' => $company->id,
            'location_id' => $company->officeId,
            'cidr' => '2001:db8::/32',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($company->employee)->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])->post('/attendance/clock-in')->assertSessionHasNoErrors();
        $this->assertSame('203.0.113.50', DB::table('attendance_logs')->whereDate('work_date', '2026-10-19')->value('check_in_ip'));

        DB::table('attendance_logs')->whereDate('work_date', '2026-10-19')->delete();
        $this->actingAs($company->employee)->withServerVariables(['REMOTE_ADDR' => '2001:db8::10'])->post('/attendance/clock-in')->assertSessionHasNoErrors();
        $this->assertSame('2001:db8::10', DB::table('attendance_logs')->whereDate('work_date', '2026-10-19')->value('check_in_ip'));
    }

    public function test_spoofed_forwarded_headers_are_rejected_and_trusted_proxies_are_honored(): void
    {
        $company = $this->prepare(CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix'));
        $before = DB::table('attendance_logs')->count();

        $this->actingAs($company->employee)->withServerVariables([
            'REMOTE_ADDR' => '198.51.100.8',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.10',
        ])->post('/attendance/clock-in')->assertSessionHasErrors([
            'attendance' => 'Clock-in was blocked because a forwarded address arrived from an untrusted connection.',
        ]);
        $this->assertSame($before, DB::table('attendance_logs')->count());

        config(['attendance.trusted_proxies' => ['198.51.100.8']]);
        TrustProxies::at(['198.51.100.8']);

        $this->actingAs($company->employee)->withServerVariables([
            'REMOTE_ADDR' => '198.51.100.8',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.10',
        ])->post('/attendance/clock-in')->assertSessionHasNoErrors();

        $this->assertSame('203.0.113.10', DB::table('attendance_logs')->whereDate('work_date', '2026-10-19')->value('check_in_ip'));
    }

    public function test_cross_company_inactive_and_duplicate_clock_in_are_rejected(): void
    {
        $company = $this->prepare(CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix'));
        $other = $this->prepare(CompanyFixture::make('Deep Data Inc', 'deep-data'));
        $closed = DB::table('attendance_logs')->insertGetId([
            'tenant_id' => $company->id,
            'employee_id' => $company->reportEmployeeId,
            'work_date' => '2026-10-16',
            'check_in' => '2026-10-16 09:00:00',
            'check_out' => '2026-10-16 18:00:00',
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($company->employee)->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/attendance/clock-in', ['office_id' => $other->officeId])
            ->assertSessionHasErrors(['attendance' => 'You are not authorized to attend that office.']);

        DB::table('locations')->where('id', $company->officeId)->update(['status' => 'inactive']);
        $this->actingAs($company->employee)->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/attendance/clock-in')
            ->assertSessionHasErrors(['attendance' => 'Your assigned office is inactive. Contact your administrator.']);
        DB::table('locations')->where('id', $company->officeId)->update(['status' => 'active']);

        DB::table('employees')->where('id', $company->reportEmployeeId)->update(['status' => 'inactive']);
        $this->actingAs($company->employee)->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/attendance/clock-in')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => 'This account is not active.']);
        DB::table('employees')->where('id', $company->reportEmployeeId)->update(['status' => 'active']);

        $this->actingAs($company->employee)->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])->post('/attendance/clock-in')->assertSessionHasNoErrors();
        $this->actingAs($company->employee)->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/attendance/clock-in')
            ->assertSessionHasErrors(['attendance' => 'You already have an active attendance session.']);
        $this->assertSame(1, DB::table('attendance_logs')->where('employee_id', $company->reportEmployeeId)->whereDate('work_date', '2026-10-19')->count());

        $preserved = DB::table('attendance_logs')->where('id', $closed)->first();
        $this->assertSame('2026-10-16 09:00:00', $preserved->check_in);
        $this->assertSame('2026-10-16 18:00:00', $preserved->check_out);
    }

    public function test_break_and_clock_out_follow_valid_transitions_without_overwriting_clock_in(): void
    {
        $company = $this->prepare(CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix'));
        $this->actingAs($company->employee)->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])->post('/attendance/clock-in')->assertSessionHasNoErrors();
        $checkIn = DB::table('attendance_logs')->whereDate('work_date', '2026-10-19')->value('check_in');

        Carbon::setTestNow('2026-10-19 10:00:00');
        $this->actingAs($company->employee)->post('/attendance/break-start')->assertSessionHasNoErrors();
        $this->actingAs($company->employee)->post('/attendance/break-start')->assertSessionHasErrors(['attendance' => 'You are already on a break.']);
        $this->actingAs($company->employee)->post('/attendance/clock-out')->assertSessionHasErrors(['attendance' => 'End your break before clocking out.']);

        Carbon::setTestNow('2026-10-19 10:10:00');
        $this->actingAs($company->employee)->post('/attendance/break-end')->assertSessionHasNoErrors();
        $this->actingAs($company->employee)->post('/attendance/break-end')->assertSessionHasErrors(['attendance' => 'You do not have an active break.']);

        Carbon::setTestNow('2026-10-19 11:00:00');
        $this->actingAs($company->employee)->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])->post('/attendance/clock-out')->assertSessionHasErrors(['attendance' => 'You are not connected through an approved office network. Clock-out is blocked.']);
        $this->actingAs($company->employee)->withServerVariables(['REMOTE_ADDR' => '192.168.0.174'])->post('/attendance/clock-out')->assertSessionHasErrors(['attendance' => 'Clock-out was blocked because this connection is a private network address. The server does not see the office public address.']);
        $this->assertNull(DB::table('attendance_logs')->whereDate('work_date', '2026-10-19')->value('check_out'));
        $this->actingAs($company->employee)->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])->post('/attendance/clock-out', ['ip' => '198.51.100.20', 'verified' => true])->assertSessionHasNoErrors();

        $log = DB::table('attendance_logs')->whereDate('work_date', '2026-10-19')->first();
        $this->assertSame($checkIn, $log->check_in);
        $this->assertSame('203.0.113.10', $log->check_out_ip);
        $this->assertSame('2026-10-19 11:00:00', $log->check_out);
        $this->assertSame(110, (int) $log->minutes);
        $this->assertSame(1, DB::table('attendance_breaks')->where('attendance_log_id', $log->id)->count());

        $this->actingAs($company->employee)->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/attendance/clock-in')
            ->assertSessionHasErrors(['attendance' => 'You already have an attendance record for today.']);
        $this->assertSame($checkIn, DB::table('attendance_logs')->where('id', $log->id)->value('check_in'));
    }

    public function test_an_interrupted_session_is_closed_explicitly_and_keeps_the_original_clock_in(): void
    {
        $company = $this->prepare(CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix'));
        $open = DB::table('attendance_logs')->insertGetId([
            'tenant_id' => $company->id,
            'employee_id' => $company->reportEmployeeId,
            'location_id' => $company->officeId,
            'work_date' => '2026-10-18',
            'check_in' => '2026-10-18 09:05:00',
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($company->employee)->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/attendance/clock-in')
            ->assertSessionHasErrors(['attendance' => 'An earlier attendance session is still open. Contact HR.']);

        $this->actingAs($company->employee)->post('/attendance/'.$open.'/recover', ['reason' => 'Closed the interrupted session'])->assertForbidden();
        $this->actingAs($company->hr)->post('/attendance/'.$open.'/recover', ['reason' => 'Closed the interrupted session'])->assertSessionHasNoErrors();

        $log = DB::table('attendance_logs')->where('id', $open)->first();
        $this->assertSame('2026-10-18 09:05:00', $log->check_in);
        $this->assertNotNull($log->check_out);
        $this->assertSame('recovered', $log->status);

        $this->actingAs($company->employee)->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])->post('/attendance/clock-in')->assertSessionHasNoErrors();
    }

    public function test_attendance_visibility_stays_inside_the_company_and_team(): void
    {
        $company = $this->prepare(CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix'));
        $other = $this->prepare(CompanyFixture::make('Deep Data Inc', 'deep-data'));
        $platform = User::factory()->create();
        $platform->forceFill(['is_platform_admin' => true, 'tenant_id' => null])->save();

        $this->actingAs($company->employee)->get('/attendance')->assertInertia(fn (Assert $page) => $page->component('hrms/forbidden'));
        $this->actingAs($company->employee)->get('/my-attendance')->assertOk()->assertDontSee($company->outsideEmail)->assertDontSee($other->reportEmail);
        $this->actingAs($company->lead)->get('/attendance')->assertOk()->assertSee('veltrix-REP')->assertDontSee('veltrix-OUT')->assertDontSee('deep-data-REP');
        $this->actingAs($company->hr)->get('/attendance/export')->assertOk()->assertDontSee('deep-data-REP');
        $this->actingAs($company->employee)->get('/attendance/export')->assertForbidden();
        $this->actingAs($platform)->get('/attendance')->assertInertia(fn (Assert $page) => $page->component('hrms/forbidden'));
        $this->actingAs($platform)->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])->post('/attendance/clock-in')->assertSessionHasErrors('attendance');
    }

    public function test_a_company_admin_can_save_a_public_network_and_an_office_update_keeps_it(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $other = CompanyFixture::make('Deep Data Inc', 'deep-data');
        $admin = $this->owner($company);
        $host = '49.205.10.25/32';
        $range = '198.51.100.0/24';

        $this->actingAs($admin)->post('/offices/'.$company->officeId.'/networks', ['cidr' => '  '.$host.'  '])->assertSessionHasNoErrors()->assertRedirect();
        $this->actingAs($admin)->post('/offices/'.$company->officeId.'/networks', ['cidr' => $range])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/offices/'.$company->officeId.'/networks', ['cidr' => $host])->assertSessionHasErrors('cidr');
        foreach (['not-an-address', '49.205.10.25/33', '49.205.10.25/8', '10.0.0.5', '192.168.0.0/16', '203.0.113.0/24/24'] as $rejected) {
            $this->actingAs($admin)->post('/offices/'.$company->officeId.'/networks', ['cidr' => $rejected])->assertSessionHasErrors('cidr');
        }

        $this->assertSame([$range, $host], DB::table('location_networks')->where('location_id', $company->officeId)->orderBy('cidr')->pluck('cidr')->all());
        $this->actingAs($admin)->get('/offices')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('offices.0.verification.networkConfigured', true)
            ->where('offices.0.networks', fn ($networks) => collect($networks)->pluck('cidr')->sort()->values()->all() === [$range, $host]));

        $this->actingAs($admin)->put('/offices/'.$company->officeId, [
            'name' => 'veltrix-office',
            'address' => '100 Example Street',
            'timezone' => 'Asia/Kolkata',
            'status' => 'active',
            'latitude' => 22.572645,
            'longitude' => 88.363895,
            'verification_radius_meters' => 150,
            'gps_verification_enabled' => false,
        ])->assertSessionHasNoErrors();
        $this->assertSame([$range, $host], DB::table('location_networks')->where('location_id', $company->officeId)->orderBy('cidr')->pluck('cidr')->all());
        $this->actingAs($admin)->get('/offices')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('offices.0.networks', fn ($networks) => collect($networks)->pluck('cidr')->sort()->values()->all() === [$range, $host]));

        $this->actingAs($company->employee)->post('/offices/'.$company->officeId.'/networks', ['cidr' => '198.51.100.8'])->assertNotFound();
        $this->actingAs($this->owner($other))->post('/offices/'.$company->officeId.'/networks', ['cidr' => '198.51.100.9'])->assertNotFound();
        $this->assertSame(0, DB::table('location_networks')->where('tenant_id', $other->id)->count());
        $this->assertSame(2, DB::table('location_networks')->where('location_id', $company->officeId)->count());
    }

    public function test_office_networks_are_limited_to_the_signed_in_company(): void
    {
        $company = $this->prepare(CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix'));
        $other = $this->prepare(CompanyFixture::make('Deep Data Inc', 'deep-data'));

        $this->actingAs($company->hr)->post('/offices/'.$company->officeId.'/networks', ['cidr' => '192.168.0.174'])->assertSessionHasErrors('cidr');
        $this->actingAs($company->hr)->post('/offices/'.$company->officeId.'/networks', ['cidr' => '127.0.0.1'])->assertSessionHasErrors('cidr');
        $this->actingAs($company->hr)->post('/offices/'.$company->officeId.'/networks', ['cidr' => '192.168.1.10'])->assertSessionHasErrors('cidr');
        $this->actingAs($company->hr)->post('/offices/'.$company->officeId.'/networks', ['cidr' => '103.211.39.102'])->assertSessionHasNoErrors();
        $this->assertSame('103.211.39.102/32', DB::table('location_networks')->where('location_id', $company->officeId)->where('tenant_id', $company->id)->orderByDesc('id')->value('cidr'));
        $this->actingAs($company->hr)->post('/offices/'.$company->officeId.'/networks', ['cidr' => '103.211.39.102/32'])->assertSessionHasErrors('cidr');
        $this->actingAs($company->hr)->post('/offices/'.$company->officeId.'/networks', ['cidr' => '198.51.100.0/24'])->assertSessionHasNoErrors();
        $this->actingAs($company->hr)->put('/offices/'.$other->officeId, [
            'name' => 'Taken',
            'address' => 'Elsewhere',
            'timezone' => 'UTC',
            'status' => 'inactive',
        ])->assertNotFound();
        $this->assertSame('active', DB::table('locations')->where('id', $other->officeId)->value('status'));
        $this->actingAs($other->hr)->get('/offices')->assertDontSee('198.51.100.0/24');
    }

    public function test_an_administrator_approves_only_the_public_address_the_server_observes(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $other = CompanyFixture::make('Deep Data Inc', 'deep-data');
        $admin = $this->owner($company);
        $before = DB::table('location_networks')->where('location_id', $company->officeId)->count();

        $this->actingAs($admin)->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->get('/offices')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('detectedAddress.ip', null)
                ->where('detectedAddress.approvable', false)
                ->where('detectedAddress.message', 'This connection is localhost. A loopback address is not saved for this company.'));
        $this->actingAs($admin)->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->post('/offices/'.$company->officeId.'/networks/detected', ['confirm_detected' => true, 'cidr' => '103.211.39.102'])
            ->assertSessionHasErrors('confirm_detected');
        $this->actingAs($admin)->withServerVariables(['REMOTE_ADDR' => '192.168.0.174'])
            ->get('/offices')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('detectedAddress.ip', null)
                ->where('detectedAddress.approvable', false)
                ->where('detectedAddress.message', 'This connection is a private address such as 192.168, 10.x, or 172.16. Private addresses are not saved for this company. Approve only the public address of this office network.'));
        $this->actingAs($admin)->withServerVariables(['REMOTE_ADDR' => '192.168.0.174'])
            ->post('/offices/'.$company->officeId.'/networks/detected', ['confirm_detected' => true])
            ->assertSessionHasErrors('confirm_detected');
        foreach ([
            ['HTTP_X_FORWARDED_FOR' => '103.211.39.102'],
            ['HTTP_FORWARDED' => 'for=103.211.39.102'],
            ['HTTP_X_REAL_IP' => '103.211.39.102'],
        ] as $header) {
            $this->actingAs($admin)->withServerVariables([
                'REMOTE_ADDR' => '203.0.113.44',
                ...$header,
            ])->post('/offices/'.$company->officeId.'/networks/detected', ['confirm_detected' => true, 'cidr' => '103.211.39.102'])
                ->assertSessionHasErrors('confirm_detected');
        }
        $this->actingAs($admin)->withServerVariables(['REMOTE_ADDR' => '203.0.113.44'])
            ->post('/offices/'.$company->officeId.'/networks/detected', ['confirm_detected' => false, 'cidr' => '198.51.100.9'])
            ->assertSessionHasErrors('confirm_detected');
        $this->assertSame($before, DB::table('location_networks')->where('location_id', $company->officeId)->count());

        $this->actingAs($admin)->withServerVariables(['REMOTE_ADDR' => '203.0.113.44'])
            ->post('/offices/'.$company->officeId.'/networks/detected', ['confirm_detected' => true, 'cidr' => '198.51.100.9'])
            ->assertSessionHasNoErrors();
        $this->assertSame('203.0.113.44/32', DB::table('location_networks')->where('location_id', $company->officeId)->where('tenant_id', $company->id)->orderByDesc('id')->value('cidr'));
        $this->assertSame(0, DB::table('location_networks')->where('tenant_id', $other->id)->where('cidr', '203.0.113.44/32')->count());

        $this->actingAs($company->hr)->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->post('/offices/'.$company->officeId.'/networks/detected', ['confirm_detected' => true, 'cidr' => '203.0.113.99'])
            ->assertSessionHasNoErrors();
        $this->assertSame('198.51.100.20/32', DB::table('location_networks')->where('location_id', $company->officeId)->where('tenant_id', $company->id)->where('cidr', '198.51.100.20/32')->value('cidr'));

        $this->actingAs($company->employee)->withServerVariables(['REMOTE_ADDR' => '203.0.113.44'])
            ->post('/offices/'.$company->officeId.'/networks/detected', ['confirm_detected' => true])
            ->assertNotFound();
        $this->actingAs($this->owner($other))->withServerVariables(['REMOTE_ADDR' => '203.0.113.55'])
            ->post('/offices/'.$company->officeId.'/networks/detected', ['confirm_detected' => true])
            ->assertNotFound();
        $this->assertSame(0, DB::table('location_networks')->where('tenant_id', $other->id)->count());
    }

    public function test_opening_attendance_does_not_create_records(): void
    {
        $company = $this->prepare(CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix'));
        $before = DB::table('attendance_logs')->count();

        $this->actingAs($company->hr)->get('/attendance')->assertOk();
        $this->actingAs($company->employee)->get('/my-attendance')->assertOk();

        $this->assertSame($before, DB::table('attendance_logs')->count());
    }

    private function owner(CompanyFixture $company): User
    {
        $roleId = DB::table('roles')->where('tenant_id', $company->id)->where('name', 'Owner')->value('id');
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
        DB::table('tenants')->where('id', $company->id)->update([
            'timezone' => 'UTC',
            'week_off' => json_encode(['saturday', 'sunday']),
        ]);
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
        DB::table('employees')->where('tenant_id', $company->id)->update(['shift_id' => $shiftId]);

        return $company;
    }
}
