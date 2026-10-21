<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TestDatabaseGuard;
use Tests\TestCase;

class HistoricalDecisionMysqlTest extends TestCase
{
    private const DATABASE = 'meetara_hrms_stage37_20261020';

    private bool $ready = false;

    private int $northId;

    private int $southId;

    private User $owner;

    private User $hr;

    private User $lead;

    private User $report;

    private User $accounts;

    private User $platform;

    private User $southHr;

    private Employee $employee;

    private Employee $southEmployee;

    protected function setUp(): void
    {
        parent::setUp();

        if (TestDatabaseGuard::integrationDecision(self::DATABASE) === 'skip') {
            $this->markTestSkipped('Historical-decision MySQL checks run only on '.self::DATABASE.'.');
        }

        Mail::fake();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->seedCompany();
        $this->ready = true;
    }

    protected function tearDown(): void
    {
        if ($this->ready && DB::getDriverName() === 'mysql' && DB::getDatabaseName() === self::DATABASE) {
            DB::unprepared('DROP TRIGGER IF EXISTS stage36_force_rollback');
            DB::unprepared('DROP TRIGGER IF EXISTS stage37_force_rollback');
            $this->wipe();
        }

        parent::tearDown();
    }

    public function test_synthetic_decisions_preserve_history_checklist_and_authorization(): void
    {
        $this->assertTrue(Schema::hasColumn('employee_record_events', 'note'));
        $this->assertSame(0, DB::table('migrations')->where('migration', '2026_10_20_120000_add_company_lifecycle_events')->count());
        $this->assertSame(0, DB::table('migrations')->where('migration', '2026_10_20_140000_add_company_admin_invitations')->count());
        $this->assertFalse(Schema::hasTable('company_lifecycle_events'));
        $this->assertFalse(Schema::hasTable('company_admin_invitations'));

        $onboardingId = $this->startChecklist($this->employee);
        $descriptions = [
            'Synthetic description for personal documents.',
            'Synthetic description for the team introduction.',
            'Synthetic description for laptop access.',
            'Synthetic description for company policies.',
        ];
        $titles = [
            'Share personal documents',
            'Introduce the team',
            'Issue laptop and access',
            'Walk through company policies',
        ];
        $taskIds = [];

        foreach ($titles as $index => $title) {
            $taskIds[] = (int) DB::table('onboarding_tasks')->insertGetId([
                'tenant_id' => $this->northId,
                'onboarding_id' => $onboardingId,
                'title' => $title,
                'description' => $descriptions[$index],
                'assignee_role' => 'hr',
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $priorId = (int) DB::table('employee_record_events')->insertGetId([
            'tenant_id' => $this->northId,
            'employee_id' => $this->employee->id,
            'actor_id' => $this->hr->id,
            'action' => 'welcome',
            'subject' => 'joined',
            'note' => null,
            'created_at' => now(),
        ]);
        $required = $this->requiredStatuses();
        $onboardingStatus = DB::table('onboardings')->where('id', $onboardingId)->value('status');
        $notices = DB::table('notifications')->count();
        $tasks = DB::table('onboarding_tasks')->count();
        $events = DB::table('employee_record_events')->count();

        $this->actingAs($this->hr)->post($this->path($taskIds[0]), [
            'outcome' => 'completed',
            'note' => '',
            'confirm' => 'RECORD',
        ])->assertSessionHasErrors('note');
        $this->actingAs($this->hr)->post($this->path($taskIds[1]), [
            'outcome' => 'not_applicable',
            'confirm' => 'RECORD',
        ])->assertSessionHasErrors('note');
        $this->assertSame($events, DB::table('employee_record_events')->count());
        $this->assertSame('pending', DB::table('onboarding_tasks')->where('id', $taskIds[0])->value('status'));
        $this->assertNull(DB::table('onboarding_tasks')->where('id', $taskIds[0])->value('completed_by'));

        $this->actingAs($this->hr)->post($this->path($taskIds[0]), [
            'outcome' => 'completed',
            'note' => 'Synthetic completed note.',
            'confirm' => 'RECORD',
        ])->assertRedirect();
        $this->assertSame($events + 1, DB::table('employee_record_events')->count());

        $this->actingAs($this->hr)->post($this->path($taskIds[1]), [
            'outcome' => 'not_applicable',
            'note' => 'Synthetic not applicable note.',
            'confirm' => 'RECORD',
        ])->assertRedirect();
        $this->actingAs($this->hr)->post($this->path($taskIds[2]), [
            'outcome' => 'pending',
            'note' => '',
            'confirm' => 'RECORD',
        ])->assertRedirect();
        $this->actingAs($this->owner)->post($this->path($taskIds[3]), [
            'outcome' => 'pending',
            'note' => 'Synthetic still-open note.',
            'confirm' => 'RECORD',
        ])->assertRedirect();

        $this->assertSame('completed', DB::table('onboarding_tasks')->where('id', $taskIds[0])->value('status'));
        $this->assertSame($this->hr->id, (int) DB::table('onboarding_tasks')->where('id', $taskIds[0])->value('completed_by'));
        $this->assertNotNull(DB::table('onboarding_tasks')->where('id', $taskIds[0])->value('completed_at'));
        $this->assertSame('not_applicable', DB::table('onboarding_tasks')->where('id', $taskIds[1])->value('status'));
        $this->assertNull(DB::table('onboarding_tasks')->where('id', $taskIds[1])->value('completed_by'));
        $this->assertNull(DB::table('onboarding_tasks')->where('id', $taskIds[1])->value('completed_at'));
        $this->assertSame('pending', DB::table('onboarding_tasks')->where('id', $taskIds[2])->value('status'));
        $this->assertNull(DB::table('onboarding_tasks')->where('id', $taskIds[2])->value('completed_by'));
        $this->assertNull(DB::table('onboarding_tasks')->where('id', $taskIds[2])->value('completed_at'));
        $this->assertSame('pending', DB::table('onboarding_tasks')->where('id', $taskIds[3])->value('status'));
        $this->assertNull(DB::table('onboarding_tasks')->where('id', $taskIds[3])->value('completed_by'));
        $this->assertNull(DB::table('onboarding_tasks')->where('id', $taskIds[3])->value('completed_at'));

        foreach ($taskIds as $index => $taskId) {
            $task = DB::table('onboarding_tasks')->where('id', $taskId)->first();
            $this->assertSame($titles[$index], $task->title);
            $this->assertSame($descriptions[$index], $task->description);
            $this->assertNull($task->code);
        }

        $decisionEvents = DB::table('employee_record_events')->where('action', 'historical_decision')->orderBy('id')->get();
        $this->assertCount(4, $decisionEvents);
        $this->assertSame('t'.$taskIds[0].':completed', $decisionEvents[0]->subject);
        $this->assertLessThanOrEqual(40, strlen((string) $decisionEvents[0]->subject));
        $this->assertSame('Synthetic completed note.', $decisionEvents[0]->note);
        $this->assertSame($this->hr->id, (int) $decisionEvents[0]->actor_id);
        $this->assertNotNull($decisionEvents[0]->created_at);
        $this->assertSame($this->hr->id, (int) $decisionEvents[1]->actor_id);
        $this->assertNotNull($decisionEvents[2]->created_at);
        $this->assertSame('t'.$taskIds[1].':not_applicable', $decisionEvents[1]->subject);
        $this->assertLessThanOrEqual(40, strlen((string) $decisionEvents[1]->subject));
        $this->assertSame('Synthetic not applicable note.', $decisionEvents[1]->note);
        $this->assertNull($decisionEvents[2]->note);
        $this->assertSame('t'.$taskIds[2].':pending', $decisionEvents[2]->subject);
        $this->assertSame('Synthetic still-open note.', $decisionEvents[3]->note);
        $this->assertSame($this->owner->id, (int) $decisionEvents[3]->actor_id);
        $prior = DB::table('employee_record_events')->where('id', $priorId)->first();
        $this->assertSame('welcome', $prior->action);
        $this->assertSame('joined', $prior->subject);
        $this->assertNull($prior->note);

        $this->actingAs($this->hr)->post($this->path($taskIds[0]), [
            'outcome' => 'completed',
            'note' => 'A second explicit decision.',
            'confirm' => 'RECORD',
        ])->assertRedirect();
        $this->assertSame(5, DB::table('employee_record_events')->where('action', 'historical_decision')->count());
        $this->assertSame('Synthetic completed note.', DB::table('employee_record_events')->where('subject', 't'.$taskIds[0].':completed')->orderBy('id')->value('note'));
        $this->assertSame('A second explicit decision.', DB::table('employee_record_events')->where('subject', 't'.$taskIds[0].':completed')->orderByDesc('id')->value('note'));

        $this->assertSame($required, $this->requiredStatuses());
        $this->assertSame($onboardingStatus, DB::table('onboardings')->where('id', $onboardingId)->value('status'));
        $this->assertSame($notices, DB::table('notifications')->count());
        $this->assertSame($tasks, DB::table('onboarding_tasks')->count());
        $this->assertSame(0, DB::table('offer_letters')->count());
        $this->assertSame(0, DB::table('employee_documents')->count());
        $this->assertSame(7, DB::table('onboarding_tasks')->where('onboarding_id', $onboardingId)->whereNotNull('code')->count());

        $codedId = (int) DB::table('onboarding_tasks')->where('onboarding_id', $onboardingId)->where('code', 'pan')->value('id');
        $codedStatus = DB::table('onboarding_tasks')->where('id', $codedId)->value('status');
        $this->actingAs($this->hr)->post($this->path($codedId), $this->payload('completed'))->assertSessionHasErrors('task');
        $this->assertSame($codedStatus, DB::table('onboarding_tasks')->where('id', $codedId)->value('status'));
        $this->actingAs($this->hr)->post('/employees/'.$this->employee->id.'/onboarding', [
            'outcome' => 'completed',
            'note' => 'This is not a task update.',
            'confirm' => 'RECORD',
        ])->assertRedirect();
        $this->assertSame($tasks, DB::table('onboarding_tasks')->count());
        $this->assertSame($notices, DB::table('notifications')->count());

        $this->actingAs($this->lead)->post($this->path($taskIds[2]), $this->payload('completed'))->assertForbidden();
        $this->actingAs($this->report)->post($this->path($taskIds[2]), $this->payload('completed'))->assertForbidden();
        $this->actingAs($this->accounts)->post($this->path($taskIds[2]), $this->payload('completed'))->assertNotFound();
        $this->actingAs($this->platform)->post($this->path($taskIds[2]), $this->payload('completed'))->assertNotFound();
        $this->actingAs($this->southHr)->post($this->path($taskIds[2]), $this->payload('completed'))->assertNotFound();
        $this->actingAs($this->hr)->post('/employees/'.$this->southEmployee->id.'/onboarding-tasks/'.$taskIds[2].'/decision', $this->payload('completed'))->assertNotFound();
        $this->assertSame('pending', DB::table('onboarding_tasks')->where('id', $taskIds[2])->value('status'));
        $this->assertSame(5, DB::table('employee_record_events')->where('action', 'historical_decision')->count());

        $this->actingAs($this->lead)->get('/employees/'.$this->employee->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('onboarding.canDecide', false)
                ->where('onboarding.tasks', fn ($rows) => collect($rows)->contains(
                    fn ($row) => $row['title'] === 'Issue laptop and access'
                        && $row['required'] === false
                        && $row['status'] === 'Pending'
                        && $row['completedBy'] === null
                        && $row['decisionBy'] === 'Stage36 HR'
                ) && collect($rows)->contains(
                    fn ($row) => $row['code'] === 'pan' && $row['required'] === true
                )));
    }

    public function test_later_outcomes_clear_completion_metadata_and_keep_earlier_events(): void
    {
        $onboardingId = $this->startChecklist($this->employee);
        $taskId = (int) DB::table('onboarding_tasks')->insertGetId([
            'tenant_id' => $this->northId,
            'onboarding_id' => $onboardingId,
            'title' => 'Walk through company policies',
            'description' => 'Synthetic description that later outcomes must not replace.',
            'assignee_role' => 'hr',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->hr)->post($this->path($taskId), [
            'outcome' => 'completed',
            'note' => 'Synthetic completed note.',
            'confirm' => 'RECORD',
        ])->assertRedirect();
        $this->actingAs($this->owner)->post($this->path($taskId), [
            'outcome' => 'pending',
            'note' => 'Synthetic still-open note.',
            'confirm' => 'RECORD',
        ])->assertRedirect();
        $reopened = DB::table('onboarding_tasks')->where('id', $taskId)->first();
        $this->assertSame('pending', $reopened->status);
        $this->assertNull($reopened->completed_by);
        $this->assertNull($reopened->completed_at);
        $this->assertSame('Synthetic description that later outcomes must not replace.', $reopened->description);
        $this->assertSame('t'.$taskId.':completed', DB::table('employee_record_events')->where('action', 'historical_decision')->orderBy('id')->value('subject'));

        $this->actingAs($this->hr)->post($this->path($taskId), [
            'outcome' => 'completed',
            'note' => 'Completed after it was reopened.',
            'confirm' => 'RECORD',
        ])->assertRedirect();
        $this->actingAs($this->hr)->post($this->path($taskId), [
            'outcome' => 'not_applicable',
            'note' => 'Synthetic not applicable note.',
            'confirm' => 'RECORD',
        ])->assertRedirect();
        $cleared = DB::table('onboarding_tasks')->where('id', $taskId)->first();
        $this->assertSame('not_applicable', $cleared->status);
        $this->assertNull($cleared->completed_by);
        $this->assertNull($cleared->completed_at);
        $this->assertSame(4, DB::table('employee_record_events')->where('employee_id', $this->employee->id)->where('action', 'historical_decision')->count());
        $this->assertSame('Synthetic completed note.', DB::table('employee_record_events')->where('subject', 't'.$taskId.':completed')->orderBy('id')->value('note'));
    }

    public function test_a_failed_event_insert_does_not_keep_the_task_update(): void
    {
        $onboardingId = $this->startChecklist($this->employee);
        $taskId = (int) DB::table('onboarding_tasks')->insertGetId([
            'tenant_id' => $this->northId,
            'onboarding_id' => $onboardingId,
            'title' => 'Issue laptop and access',
            'description' => 'Synthetic description that must survive a failed save.',
            'assignee_role' => 'hr',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $events = DB::table('employee_record_events')->count();

        DB::unprepared("CREATE TRIGGER stage36_force_rollback BEFORE INSERT ON employee_record_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced rollback'");

        try {
            $this->actingAs($this->hr)->post($this->path($taskId), [
                'outcome' => 'completed',
                'note' => 'This save must roll back.',
                'confirm' => 'RECORD',
            ])->assertStatus(500);
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS stage36_force_rollback');
        }

        $task = DB::table('onboarding_tasks')->where('id', $taskId)->first();
        $this->assertSame('pending', $task->status);
        $this->assertNull($task->completed_by);
        $this->assertNull($task->completed_at);
        $this->assertSame('Synthetic description that must survive a failed save.', $task->description);
        $this->assertSame($events, DB::table('employee_record_events')->count());

        $this->actingAs($this->hr)->post($this->path($taskId), [
            'outcome' => 'completed',
            'note' => 'This completion must survive the failed reopen.',
            'confirm' => 'RECORD',
        ])->assertRedirect();
        $completedAt = DB::table('onboarding_tasks')->where('id', $taskId)->value('completed_at');
        $events = DB::table('employee_record_events')->count();

        DB::unprepared("CREATE TRIGGER stage37_force_rollback BEFORE INSERT ON employee_record_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced rollback'");

        try {
            $this->actingAs($this->hr)->post($this->path($taskId), [
                'outcome' => 'pending',
                'note' => '',
                'confirm' => 'RECORD',
            ])->assertStatus(500);
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS stage37_force_rollback');
        }

        $kept = DB::table('onboarding_tasks')->where('id', $taskId)->first();
        $this->assertSame('completed', $kept->status);
        $this->assertSame($this->hr->id, (int) $kept->completed_by);
        $this->assertSame($completedAt, $kept->completed_at);
        $this->assertSame($events, DB::table('employee_record_events')->count());
    }

    private function startChecklist(Employee $employee): int
    {
        $this->actingAs($this->hr)->post('/employees/'.$employee->id.'/onboarding')->assertRedirect();

        return (int) DB::table('onboardings')->where('employee_id', $employee->id)->value('id');
    }

    /**
     * @return array<string, string>
     */
    private function requiredStatuses(): array
    {
        return DB::table('onboarding_tasks')
            ->where('onboarding_id', DB::table('onboardings')->where('employee_id', $this->employee->id)->value('id'))
            ->whereNotNull('code')
            ->orderBy('code')
            ->pluck('status', 'code')
            ->all();
    }

    private function path(int $taskId): string
    {
        return '/employees/'.$this->employee->id.'/onboarding-tasks/'.$taskId.'/decision';
    }

    /**
     * @return array<string, string>
     */
    private function payload(string $outcome): array
    {
        return [
            'outcome' => $outcome,
            'note' => 'Synthetic authorization note.',
            'confirm' => 'RECORD',
        ];
    }

    private function seedCompany(): void
    {
        $this->wipe();
        $now = now();
        $weekOff = json_encode(['saturday', 'sunday']);
        $this->northId = $this->company('Stage36 North', 'stage36-north', $weekOff, $now);
        $this->southId = $this->company('Stage36 South', 'stage36-south', $weekOff, $now);
        $this->platform = $this->user('Stage36 Platform', 'platform@stage36.test', null, true, $now);
        $this->owner = $this->user('Stage36 Owner', 'owner@stage36-north.test', $this->northId, false, $now);
        $this->hr = $this->user('Stage36 HR', 'hr@stage36-north.test', $this->northId, false, $now);
        $this->lead = $this->user('Stage36 Lead', 'lead@stage36-north.test', $this->northId, false, $now);
        $this->report = $this->user('Stage36 Report', 'report@stage36-north.test', $this->northId, false, $now);
        $this->accounts = $this->user('Stage36 Accounts', 'accounts@stage36-north.test', $this->northId, false, $now);
        $this->southHr = $this->user('Stage36 South HR', 'hr@stage36-south.test', $this->southId, false, $now);
        $southReport = $this->user('Stage36 South Report', 'report@stage36-south.test', $this->southId, false, $now);

        $this->role($this->northId, $this->owner->id, 'Owner', $now);
        $this->role($this->northId, $this->hr->id, 'HR', $now);
        $this->role($this->northId, $this->lead->id, 'Manager', $now);
        $this->role($this->northId, $this->report->id, 'Employee', $now);
        $this->role($this->northId, $this->accounts->id, 'Accounts', $now);
        $this->role($this->southId, $this->southHr->id, 'HR', $now);
        $this->role($this->southId, $southReport->id, 'Employee', $now);

        $leadEmployeeId = $this->employee($this->northId, $this->lead->id, 'S36-LEAD', 'lead@stage36-north.test', null, $now);
        $reportEmployeeId = $this->employee($this->northId, $this->report->id, 'S36-REP', 'report@stage36-north.test', $leadEmployeeId, $now);
        $southEmployeeId = $this->employee($this->southId, $southReport->id, 'S36-SOU', 'report@stage36-south.test', null, $now);
        $this->employee = Employee::query()->findOrFail($reportEmployeeId);
        $this->southEmployee = Employee::query()->findOrFail($southEmployeeId);
    }

    private function company(string $name, string $slug, string $weekOff, mixed $now): int
    {
        return (int) DB::table('tenants')->insertGetId([
            'name' => $name,
            'slug' => $slug,
            'timezone' => 'Asia/Kolkata',
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
            'password' => Hash::make('secret'),
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
        $roleId = DB::table('roles')->where('tenant_id', $companyId)->where('name', $name)->value('id');

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

        return (int) DB::table('employees')->insertGetId([
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
}
