<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PDO;
use Tests\Support\TestDatabaseGuard;
use Tests\TestCase;

class Stage28MysqlWorkflowTest extends TestCase
{
    private const DATABASE = 'meetara_hrms_stage28_20261020';

    private bool $ready = false;

    private int $northId;

    private int $southId;

    private int $reportEmployeeId;

    private int $outsideEmployeeId;

    private int $leadEmployeeId;

    private int $southEmployeeId;

    private int $officeId;

    private User $platform;

    private User $owner;

    private User $hr;

    private User $lead;

    private User $report;

    private User $outside;

    private User $accounts;

    private User $southHr;

    protected function setUp(): void
    {
        parent::setUp();

        if (TestDatabaseGuard::integrationDecision(self::DATABASE) === 'skip') {
            $this->markTestSkipped('Stage 28 MySQL checks run only on '.self::DATABASE.'.');
        }

        $this->seedCompany();
        $this->ready = true;
    }

    protected function tearDown(): void
    {
        if ($this->ready) {
            $this->wipe();
        }

        TrustProxies::at([]);
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_login_logout_and_role_boundaries(): void
    {
        $route = app('router')->getRoutes()->match(\Illuminate\Http\Request::create('/login', 'POST'));
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains(ValidateCsrfToken::class, app(\Illuminate\Contracts\Http\Kernel::class)->getMiddlewareGroups()['web']);

        config(['session.driver' => 'database']);
        $this->post('/login', [
            'email' => 'report@stage28-north.test',
            'password' => 'stage28-synthetic-password',
        ]);
        $this->assertAuthenticated();
        $this->assertGreaterThan(0, DB::table('sessions')->count());
        $this->post('/logout');
        $this->assertGuest();

        $this->actingAs($this->hr)->get('/employees')->assertOk()->assertSee('report@stage28-north.test');
        $this->actingAs($this->owner)->get('/employees/'.$this->reportEmployeeId)->assertOk()->assertSee('report@stage28-north.test');
        $this->actingAs($this->owner)->get('/employees/'.$this->southEmployeeId)->assertNotFound();

        $this->actingAs($this->report)->get('/my-profile')->assertOk()
            ->assertSee('report@stage28-north.test')
            ->assertDontSee('outside@stage28-north.test');
        $this->actingAs($this->report)->get('/employees')->assertOk();
        $this->actingAs($this->report)->get('/employees/'.$this->outsideEmployeeId)->assertNotFound();
        $this->actingAs($this->report)->get('/employees/'.$this->southEmployeeId)->assertNotFound();
        $this->actingAs($this->report)->get('/payroll')->assertForbidden();

        $this->actingAs($this->lead)->get('/employees')->assertOk()
            ->assertSee('report@stage28-north.test')
            ->assertDontSee('outside@stage28-north.test')
            ->assertDontSee('report@stage28-south.test');
        $this->actingAs($this->lead)->get('/employees/'.$this->outsideEmployeeId)->assertNotFound();

        $this->actingAs($this->platform)->get('/payroll')->assertForbidden();
        $this->actingAs($this->platform)->get('/salary')->assertForbidden();
        $this->actingAs($this->platform)->get('/payments')->assertForbidden();
        $this->actingAs($this->platform)->get('/employees/'.$this->reportEmployeeId)->assertNotFound();
        $this->actingAs($this->southHr)->get('/employees/'.$this->reportEmployeeId)->assertNotFound();
    }

    public function test_attendance_accepts_the_office_network_and_rejects_spoofed_headers(): void
    {
        Carbon::setTestNow('2026-10-19 09:00:00');
        $before = DB::table('attendance_logs')->count();

        $this->actingAs($this->report)->withServerVariables([
            'REMOTE_ADDR' => '198.51.100.8',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.10',
        ])->post('/attendance/clock-in')->assertSessionHasErrors([
            'attendance' => 'Clock-in was blocked because a forwarded address arrived from an untrusted connection.',
        ]);
        $this->assertSame($before, DB::table('attendance_logs')->count());

        $this->actingAs($this->report)->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])
            ->post('/attendance/clock-in')
            ->assertSessionHasErrors(['attendance' => 'You are not connected through an approved office network. Clock-in is blocked.']);

        $this->actingAs($this->report)->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/attendance/clock-in')
            ->assertSessionHasNoErrors();
        $this->assertSame('203.0.113.10', DB::table('attendance_logs')->whereDate('work_date', '2026-10-19')->value('check_in_ip'));
        $this->actingAs($this->report)->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/attendance/clock-in')
            ->assertSessionHasErrors(['attendance' => 'You already have an active attendance session.']);
        $this->assertSame(1, DB::table('attendance_logs')->where('employee_id', $this->reportEmployeeId)->count());

        DB::table('attendance_logs')->delete();
        config(['attendance.trusted_proxies' => ['198.51.100.8']]);
        TrustProxies::at(['198.51.100.8']);
        $this->actingAs($this->report)->withServerVariables([
            'REMOTE_ADDR' => '198.51.100.8',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.10',
        ])->post('/attendance/clock-in')->assertSessionHasNoErrors();
        $this->assertSame('203.0.113.10', DB::table('attendance_logs')->whereDate('work_date', '2026-10-19')->value('check_in_ip'));
    }

    public function test_leave_request_approval_rejection_and_cancellation_keep_balances(): void
    {
        $type = $this->leaveType($this->northId, $this->reportEmployeeId, 'S28-CL');
        $foreign = $this->leaveType($this->southId, $this->southEmployeeId, 'S28-SO');

        $this->from('/leave')->actingAs($this->report)->post('/leave', [
            'leave_type_id' => $type,
            'employee_id' => $this->outsideEmployeeId,
            'tenant_id' => $this->southId,
            'start_date' => '2026-10-21',
            'end_date' => '2026-10-21',
            'reason' => 'Stage28 family event',
            'days' => 9,
        ])->assertRedirect('/leave')->assertSessionHas('status');

        $requestId = (int) DB::table('leave_requests')->where('employee_id', $this->reportEmployeeId)->whereDate('start_date', '2026-10-21')->value('id');
        $this->assertSame($this->northId, (int) DB::table('leave_requests')->where('id', $requestId)->value('tenant_id'));
        $this->assertSame('pending', DB::table('leave_requests')->where('id', $requestId)->value('status'));
        $this->assertEquals(1, (float) DB::table('leave_balances')->where('employee_id', $this->reportEmployeeId)->where('leave_type_id', $type)->value('pending'));

        $this->from('/leave')->actingAs($this->report)->post('/leave', [
            'leave_type_id' => $foreign,
            'start_date' => '2026-11-02',
            'end_date' => '2026-11-02',
            'reason' => 'Stage28 family event',
        ])->assertSessionHas('error');
        $this->actingAs($this->southHr)->post("/leave/{$requestId}/approve", ['note' => 'Approved'])->assertNotFound();
        $this->actingAs($this->report)->post("/leave/{$requestId}/approve")->assertForbidden();
        $this->actingAs($this->lead)->post("/leave/{$requestId}/approve", ['note' => 'Approved for the team'])->assertRedirect();
        $this->assertSame('approved', DB::table('leave_requests')->where('id', $requestId)->value('status'));
        $this->assertEquals(0, (float) DB::table('leave_balances')->where('leave_type_id', $type)->value('pending'));
        $this->assertEquals(1, (float) DB::table('leave_balances')->where('leave_type_id', $type)->value('used'));
        $this->assertSame(1, DB::table('leave_request_events')->where('leave_request_id', $requestId)->where('action', 'approved')->count());

        $this->from('/leave')->actingAs($this->report)->post('/leave', [
            'leave_type_id' => $type,
            'start_date' => '2026-10-22',
            'end_date' => '2026-10-22',
            'reason' => 'Stage28 family event',
        ])->assertSessionHas('status');
        $rejectId = (int) DB::table('leave_requests')->whereDate('start_date', '2026-10-22')->value('id');
        $this->from('/leave')->actingAs($this->hr)->post("/leave/{$rejectId}/reject", ['note' => 'Dates clash'])->assertRedirect();
        $this->assertSame('rejected', DB::table('leave_requests')->where('id', $rejectId)->value('status'));
        $this->assertEquals(0, (float) DB::table('leave_balances')->where('leave_type_id', $type)->value('pending'));
        $this->assertSame(1, DB::table('leave_request_events')->where('leave_request_id', $rejectId)->where('action', 'rejected')->count());

        $this->from('/leave')->actingAs($this->report)->post('/leave', [
            'leave_type_id' => $type,
            'start_date' => '2026-10-23',
            'end_date' => '2026-10-23',
            'reason' => 'Stage28 family event',
        ])->assertSessionHas('status');
        $cancelId = (int) DB::table('leave_requests')->whereDate('start_date', '2026-10-23')->value('id');
        $this->actingAs($this->report)->post("/leave/{$cancelId}/cancel")->assertRedirect();
        $this->assertSame('cancelled', DB::table('leave_requests')->where('id', $cancelId)->value('status'));
        $this->assertEquals(0, (float) DB::table('leave_balances')->where('leave_type_id', $type)->value('pending'));
        $this->assertEquals(1, (float) DB::table('leave_balances')->where('leave_type_id', $type)->value('used'));
    }

    public function test_help_desk_overtime_and_inbox_stay_inside_the_company(): void
    {
        $items = DB::table('payroll_items')->count();
        $this->actingAs($this->report)->post('/help-desk', [
            'category' => 'Facilities',
            'title' => 'Bad',
            'description' => 'Too short to save.',
            'priority' => 'low',
        ])->assertSessionHasErrors('title');

        $this->actingAs($this->report)->post('/help-desk', [
            'category' => 'Facilities',
            'title' => 'Stage28 door badge',
            'description' => 'Unique badge phrase for the office door.',
            'priority' => 'high',
        ])->assertRedirect();
        $ticketId = (int) DB::table('tickets')->where('title', 'Stage28 door badge')->value('id');
        $this->actingAs($this->report)->post('/help-desk/'.$ticketId.'/comments', ['body' => 'The badge still fails at the side door.'])->assertRedirect();
        $this->actingAs($this->report)->post('/help-desk/'.$ticketId.'/status', ['status' => 'in_progress'])->assertForbidden();
        $this->actingAs($this->southHr)->post('/help-desk/'.$ticketId.'/assign', ['assignee_id' => $this->southHr->id])->assertNotFound();
        $this->actingAs($this->hr)->post('/help-desk/'.$ticketId.'/assign', ['assignee_id' => $this->southHr->id])->assertStatus(422);
        $this->actingAs($this->hr)->post('/help-desk/'.$ticketId.'/assign', ['assignee_id' => $this->hr->id])->assertRedirect();
        $this->actingAs($this->hr)->post('/help-desk/'.$ticketId.'/status', ['status' => 'in_progress'])->assertRedirect();
        $this->actingAs($this->hr)->post('/help-desk/'.$ticketId.'/status', ['status' => 'resolved'])->assertRedirect();

        $this->assertSame('resolved', DB::table('tickets')->where('id', $ticketId)->value('status'));
        $this->assertSame($this->hr->id, (int) DB::table('tickets')->where('id', $ticketId)->value('assignee_id'));
        $this->assertSame($this->northId, (int) DB::table('tickets')->where('id', $ticketId)->value('tenant_id'));
        $this->assertSame(1, DB::table('ticket_events')->where('ticket_id', $ticketId)->where('action', 'opened')->count());
        $this->assertSame(1, DB::table('ticket_events')->where('ticket_id', $ticketId)->where('action', 'commented')->count());
        $this->assertSame(1, DB::table('ticket_events')->where('ticket_id', $ticketId)->where('action', 'assigned')->count());
        $this->assertSame(1, DB::table('ticket_events')->where('ticket_id', $ticketId)->where('action', 'status_resolved')->count());
        $this->actingAs($this->southHr)->get('/help-desk?ticket='.$ticketId)->assertOk();
        $this->assertSame(0, DB::table('tickets')->where('tenant_id', $this->southId)->count());

        $noticeId = (string) DB::table('notifications')->where('notifiable_id', $this->hr->id)->where('data->key', 'ticket-'.$ticketId.'-opened')->value('id');
        $this->assertNotSame('', $noticeId);
        $this->actingAs($this->report)->post('/inbox/'.$noticeId.'/read')->assertNotFound();
        $this->actingAs($this->hr)->post('/inbox/'.$noticeId.'/read')->assertRedirect();
        $this->assertNotNull(DB::table('notifications')->where('id', $noticeId)->value('read_at'));

        $this->actingAs($this->report)->post('/overtime', [
            'work_date' => '2026-11-04',
            'hours' => '2',
            'reason' => 'Covered the late release window.',
        ])->assertRedirect();
        $entryId = (int) DB::table('overtime_entries')->where('employee_id', $this->reportEmployeeId)->value('id');
        $this->actingAs($this->southHr)->post('/overtime/'.$entryId.'/decide', ['decision' => 'approved'])->assertNotFound();
        $this->actingAs($this->hr)->post('/overtime/'.$entryId.'/decide', ['decision' => 'approved'])->assertRedirect();
        $this->assertSame('approved', DB::table('overtime_entries')->where('id', $entryId)->value('status'));
        $this->assertSame('pending', DB::table('overtime_entries')->where('id', $entryId)->value('payroll_status'));
        $this->assertSame(120, (int) DB::table('overtime_entries')->where('id', $entryId)->value('minutes'));
        $this->assertSame(1, DB::table('overtime_events')->where('overtime_entry_id', $entryId)->where('action', 'approved')->count());
        $this->assertSame($items, DB::table('payroll_items')->count());

        $this->actingAs($this->outside)->post('/overtime', [
            'work_date' => '2026-11-06',
            'hours' => '1',
            'reason' => 'Stayed for the handoff window.',
        ])->assertRedirect();
        $outsideId = (int) DB::table('overtime_entries')->where('employee_id', $this->outsideEmployeeId)->value('id');
        $this->actingAs($this->lead)->post('/overtime/'.$outsideId.'/decide', ['decision' => 'approved'])->assertNotFound();
    }

    public function test_payroll_permissions_payslips_and_duplicate_payment_confirmation(): void
    {
        foreach ([$this->leadEmployeeId, $this->reportEmployeeId, $this->outsideEmployeeId] as $employeeId) {
            DB::table('salary_structures')->insert([
                'tenant_id' => $this->northId,
                'employee_id' => $employeeId,
                'basic' => '10.10',
                'hra' => '0.20',
                'special_allowance' => '0.00',
                'effective_from' => '2026-01-01',
                'currency' => 'INR',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->actingAs($this->hr)->get('/payroll')->assertForbidden();
        $this->actingAs($this->platform)->post('/payroll/runs', ['year' => 2026, 'month' => 11])->assertForbidden();
        $this->actingAs($this->accounts)->post('/payroll/runs', ['year' => 2026, 'month' => 11])->assertRedirect();
        $runId = (int) DB::table('payroll_runs')->where('tenant_id', $this->northId)->where('month', 11)->value('id');
        $this->actingAs($this->southHr)->get("/payroll/runs/{$runId}")->assertNotFound();
        $this->actingAs($this->accounts)->post("/payroll/runs/{$runId}/calculate")->assertRedirect();
        $this->assertSame(3, DB::table('payroll_items')->where('payroll_run_id', $runId)->count());
        $this->assertSame('10.30', \App\Support\Money::format(\App\Support\Money::cents((string) DB::table('payroll_items')->where('payroll_run_id', $runId)->where('employee_id', $this->reportEmployeeId)->value('net'))));

        $draftItem = (int) DB::table('payroll_items')->where('payroll_run_id', $runId)->where('employee_id', $this->reportEmployeeId)->value('id');
        $this->actingAs($this->report)->get("/payslips/{$draftItem}/pdf")->assertNotFound();
        $this->actingAs($this->accounts)->post("/payroll/runs/{$runId}/review", ['note' => 'Ready for approval'])->assertRedirect();
        $this->actingAs($this->accounts)->post("/payroll/runs/{$runId}/approve")->assertForbidden();
        $this->actingAs($this->owner)->post("/payroll/runs/{$runId}/approve")->assertSessionHas('error');
        $this->assertSame('under_review', DB::table('payroll_runs')->where('id', $runId)->value('status'));
        $this->actingAs($this->owner)->post("/payroll/runs/{$runId}/process", ['note' => 'Process now', 'confirm' => 'PROCESS'])->assertForbidden();
        $this->actingAs($this->accounts)->post("/payroll/runs/{$runId}/process", ['note' => 'Process now', 'confirm' => 'PROCESS'])->assertSessionHas('error');
        $this->assertSame(0, DB::table('payroll_payments')->whereIn('payroll_item_id', DB::table('payroll_items')->where('payroll_run_id', $runId)->pluck('id'))->count());

        DB::table('payroll_runs')->where('id', $runId)->update([
            'status' => 'processed',
            'processed_by' => $this->accounts->id,
            'processed_at' => now(),
        ]);
        DB::table('payroll_items')->where('payroll_run_id', $runId)->update(['payroll_status' => 'processed']);
        foreach (DB::table('payroll_items')->where('payroll_run_id', $runId)->pluck('id') as $processedItemId) {
            DB::table('payroll_payments')->insert([
                'tenant_id' => $this->northId,
                'payroll_item_id' => $processedItemId,
                'amount' => '10.30',
                'status' => 'pending',
                'recorded_by' => $this->accounts->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $itemId = (int) DB::table('payroll_items')->where('payroll_run_id', $runId)->where('employee_id', $this->reportEmployeeId)->value('id');
        $otherItem = (int) DB::table('payroll_items')->where('payroll_run_id', $runId)->where('employee_id', $this->outsideEmployeeId)->value('id');
        $this->actingAs($this->report)->get("/payslips/{$itemId}/pdf")->assertOk();
        $this->actingAs($this->outside)->get("/payslips/{$itemId}")->assertNotFound();
        $this->actingAs($this->report)->get("/payslips/{$otherItem}/pdf")->assertNotFound();
        $this->actingAs($this->southHr)->get("/payslips/{$itemId}")->assertNotFound();

        $paymentId = (int) DB::table('payroll_payments')->where('payroll_item_id', $itemId)->value('id');
        $this->actingAs($this->hr)->post("/payments/{$paymentId}", [
            'paid_on' => '2026-11-30',
            'method' => 'bank_transfer',
            'reference' => 'STAGE28-HR',
        ])->assertNotFound();
        $this->actingAs($this->accounts)->post("/payments/{$paymentId}", [
            'paid_on' => '2026-11-30',
            'method' => 'bank_transfer',
            'reference' => 'STAGE28-REF',
        ])->assertRedirect();
        $this->from('/payments')->actingAs($this->accounts)->post("/payments/{$paymentId}", [
            'paid_on' => '2026-11-30',
            'method' => 'cash',
            'reference' => 'STAGE28-DUP',
        ])->assertSessionHas('error');
        $this->assertSame('paid', DB::table('payroll_payments')->where('id', $paymentId)->value('status'));
        $this->assertSame('STAGE28-REF', DB::table('payroll_payments')->where('id', $paymentId)->value('reference'));
        $this->assertSame(1, DB::table('payroll_payments')->where('payroll_item_id', $itemId)->count());
    }

    public function test_overlapping_payment_confirmations_record_one_update(): void
    {
        $this->assertSame(self::DATABASE, DB::select('select database() as db')[0]->db);
        $engine = DB::selectOne(
            'select engine from information_schema.tables where table_schema = database() and table_name = ?',
            ['payroll_payments']
        );
        $this->assertSame('InnoDB', (string) ($engine->engine ?? $engine->ENGINE ?? ''));

        $runId = DB::table('payroll_runs')->insertGetId([
            'tenant_id' => $this->northId,
            'year' => 1999,
            'month' => 1,
            'status' => 'processed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $itemId = DB::table('payroll_items')->insertGetId([
            'tenant_id' => $this->northId,
            'payroll_run_id' => $runId,
            'employee_id' => $this->reportEmployeeId,
            'net' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $paymentId = DB::table('payroll_payments')->insertGetId([
            'tenant_id' => $this->northId,
            'payroll_item_id' => $itemId,
            'amount' => 1,
            'status' => 'pending',
            'recorded_by' => $this->accounts->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $holder = $this->pdo();
        $holder->beginTransaction();
        $holder->query('SELECT id FROM payroll_payments WHERE id='.(int) $paymentId.' FOR UPDATE');
        $environment = getenv();
        $environment['DB_CONNECTION'] = 'mysql';
        $environment['DB_DATABASE'] = self::DATABASE;
        $child = proc_open(
            [PHP_BINARY, base_path('tests/Support/confirm-payment-stage28.php'), (string) $paymentId],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            base_path(),
            $environment
        );
        $this->assertIsResource($child);
        stream_set_timeout($pipes[1], 20);

        try {
            $this->assertSame("ready\n", fgets($pipes[1]));
            sleep(1);
            $holder->prepare('UPDATE payroll_payments SET status="paid", reference="LOCKED" WHERE id=? AND status="pending"')->execute([$paymentId]);
            $holder->commit();
            $updated = trim((string) fgets($pipes[1]));
            $waited = (float) trim((string) fgets($pipes[1]));
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($child);
            $this->assertSame(0, $exit, $error);
            $this->assertSame('0', $updated);
            $this->assertSame('LOCKED', DB::table('payroll_payments')->where('id', $paymentId)->value('reference'));
            $this->assertGreaterThan(0.8, $waited);
        } finally {
            if ($holder->inTransaction()) {
                $holder->rollBack();
            }
        }
    }

    private function seedCompany(): void
    {
        $this->wipe();
        $now = now();
        $weekOff = json_encode(['saturday', 'sunday']);
        $this->northId = $this->company('Stage28 North', 'stage28-north', $weekOff, $now);
        $this->southId = $this->company('Stage28 South', 'stage28-south', $weekOff, $now);
        $this->platform = $this->user('Stage28 Platform', 'platform@stage28.test', null, true, $now);
        $this->owner = $this->user('Stage28 Owner', 'owner@stage28-north.test', $this->northId, false, $now);
        $this->hr = $this->user('Stage28 HR', 'hr@stage28-north.test', $this->northId, false, $now);
        $this->lead = $this->user('Stage28 Lead', 'lead@stage28-north.test', $this->northId, false, $now);
        $this->report = $this->user('Stage28 Report', 'report@stage28-north.test', $this->northId, false, $now);
        $this->outside = $this->user('Stage28 Outside', 'outside@stage28-north.test', $this->northId, false, $now);
        $this->accounts = $this->user('Stage28 Accounts', 'accounts@stage28-north.test', $this->northId, false, $now);
        $this->southHr = $this->user('Stage28 South HR', 'hr@stage28-south.test', $this->southId, false, $now);
        $southReport = $this->user('Stage28 South Report', 'report@stage28-south.test', $this->southId, false, $now);

        $this->role($this->northId, $this->owner->id, 'Owner', $now);
        $this->role($this->northId, $this->hr->id, 'HR', $now);
        $this->role($this->northId, $this->lead->id, 'Manager', $now);
        $this->role($this->northId, $this->report->id, 'Employee', $now);
        $this->role($this->northId, $this->outside->id, 'Employee', $now);
        $this->role($this->northId, $this->accounts->id, 'Accounts', $now);
        $this->role($this->southId, $this->southHr->id, 'HR', $now);
        $this->role($this->southId, $southReport->id, 'Employee', $now);

        $this->leadEmployeeId = $this->employee($this->northId, $this->lead->id, 'S28-LEAD', 'lead@stage28-north.test', null, $now);
        $this->reportEmployeeId = $this->employee($this->northId, $this->report->id, 'S28-REP', 'report@stage28-north.test', $this->leadEmployeeId, $now);
        $this->outsideEmployeeId = $this->employee($this->northId, $this->outside->id, 'S28-OUT', 'outside@stage28-north.test', null, $now);
        $this->southEmployeeId = $this->employee($this->southId, $southReport->id, 'S28-SOU', 'report@stage28-south.test', null, $now);
        $this->officeId = (int) DB::table('locations')->where('tenant_id', $this->northId)->value('id');

        DB::table('location_networks')->insert([
            'tenant_id' => $this->northId,
            'location_id' => $this->officeId,
            'cidr' => '203.0.113.0/24',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $shiftId = DB::table('shifts')->insertGetId([
            'tenant_id' => $this->northId,
            'name' => 'General',
            'type' => 'fixed',
            'start_time' => '09:30:00',
            'end_time' => '18:30:00',
            'expected_minutes' => 540,
            'grace_minutes' => 10,
            'is_default' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('employees')->where('tenant_id', $this->northId)->update(['shift_id' => $shiftId]);
    }

    private function leaveType(int $companyId, int $employeeId, string $code): int
    {
        $now = now();
        $typeId = DB::table('leave_types')->insertGetId([
            'tenant_id' => $companyId,
            'name' => $code,
            'code' => $code,
            'days_per_year' => 8,
            'is_paid' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('leave_policies')->insert([
            'tenant_id' => $companyId,
            'leave_type_id' => $typeId,
            'name' => $code.' yearly',
            'accrual' => 'yearly',
            'applicable_after_days' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('leave_balances')->insert([
            'tenant_id' => $companyId,
            'employee_id' => $employeeId,
            'leave_type_id' => $typeId,
            'year' => 2026,
            'allocated' => 8,
            'used' => 0,
            'pending' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $typeId;
    }

    private function company(string $name, string $slug, string $weekOff, mixed $now): int
    {
        return DB::table('tenants')->insertGetId([
            'name' => $name,
            'slug' => $slug,
            'timezone' => 'UTC',
            'week_off' => $weekOff,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function user(string $name, string $email, ?int $companyId, bool $platform, mixed $now): User
    {
        $id = DB::table('users')->insertGetId([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('stage28-synthetic-password'),
            'tenant_id' => $companyId,
            'is_platform_admin' => $platform,
            'invite_accepted_at' => $now,
            'email_verified_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return User::query()->findOrFail($id);
    }

    private function role(int $companyId, int $userId, string $name, mixed $now): void
    {
        $roleId = DB::table('roles')->where('tenant_id', $companyId)->where('name', $name)->where('guard_name', 'web')->value('id');

        if ($roleId === null) {
            $roleId = DB::table('roles')->insertGetId([
                'tenant_id' => $companyId,
                'name' => $name,
                'guard_name' => 'web',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        DB::table('model_has_roles')->insert([
            'role_id' => $roleId,
            'model_type' => User::class,
            'model_id' => $userId,
            'tenant_id' => $companyId,
        ]);
    }

    private function employee(int $companyId, int $userId, string $code, string $email, ?int $managerId, mixed $now): int
    {
        $departmentId = DB::table('departments')->where('tenant_id', $companyId)->value('id')
            ?? DB::table('departments')->insertGetId([
                'tenant_id' => $companyId,
                'name' => 'Operations',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        $designationId = DB::table('designations')->where('tenant_id', $companyId)->value('id')
            ?? DB::table('designations')->insertGetId([
                'tenant_id' => $companyId,
                'name' => 'Associate',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        $officeId = DB::table('locations')->where('tenant_id', $companyId)->value('id')
            ?? DB::table('locations')->insertGetId([
                'tenant_id' => $companyId,
                'name' => 'Main office',
                'status' => 'active',
                'timezone' => 'UTC',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        return DB::table('employees')->insertGetId([
            'tenant_id' => $companyId,
            'user_id' => $userId,
            'employee_code' => $code,
            'first_name' => $code,
            'last_name' => 'Person',
            'email' => $email,
            'department_id' => $departmentId,
            'designation_id' => $designationId,
            'location_id' => $officeId,
            'manager_id' => $managerId,
            'employment_type' => 'full_time',
            'work_mode' => 'office',
            'holiday_calendar' => 'india',
            'date_of_joining' => '2026-01-15',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function wipe(): void
    {
        if (DB::getDatabaseName() !== self::DATABASE) {
            throw new \RuntimeException('Refusing to wipe a database other than '.self::DATABASE.'.');
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (DB::select('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"') as $table) {
            $name = array_values((array) $table)[0];
            if ($name !== 'migrations') {
                DB::table($name)->delete();
            }
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    private function pdo(): PDO
    {
        $connection = config('database.connections.mysql');
        if (($connection['database'] ?? null) !== self::DATABASE) {
            throw new \RuntimeException('Refusing to open a payment lock outside '.self::DATABASE.'.');
        }

        $pdo = new PDO(
            'mysql:host='.$connection['host'].';port='.$connection['port'].';dbname='.$connection['database'].';charset=utf8mb4',
            (string) $connection['username'],
            (string) $connection['password']
        );
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('SET innodb_lock_wait_timeout=5');

        return $pdo;
    }
}
