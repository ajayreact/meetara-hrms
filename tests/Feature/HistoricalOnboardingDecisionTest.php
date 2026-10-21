<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\CompanyFixture;
use Tests\TestCase;

class HistoricalOnboardingDecisionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_hr_and_company_admin_can_record_each_historical_outcome(): void
    {
        [$company, $employee, $taskId, $description] = $this->historicalTask();
        $owner = $this->owner($company);
        $required = $this->requiredStatuses($employee->id);
        $onboardingStatus = DB::table('onboardings')->where('employee_id', $employee->id)->value('status');
        $notices = DB::table('notifications')->count();
        $tasks = DB::table('onboarding_tasks')->count();

        $this->actingAs($company->hr)->post($this->path($employee->id, $taskId), [
            'outcome' => 'completed',
            'note' => '',
            'confirm' => 'RECORD',
        ])->assertSessionHasErrors('note');
        $this->actingAs($company->hr)->post($this->path($employee->id, $taskId), [
            'outcome' => 'not_applicable',
            'confirm' => 'RECORD',
        ])->assertSessionHasErrors('note');

        $this->actingAs($company->hr)->post($this->path($employee->id, $taskId), [
            'outcome' => 'completed',
            'note' => 'Laptop handed to the employee on the first day.',
            'confirm' => 'RECORD',
        ])->assertRedirect();
        $this->actingAs($owner)->post($this->path($employee->id, $taskId), [
            'outcome' => 'not_applicable',
            'note' => 'The team introduction already happened before this checklist existed.',
            'confirm' => 'RECORD',
        ])->assertRedirect();
        $this->actingAs($company->hr)->post($this->path($employee->id, $taskId), [
            'outcome' => 'pending',
            'note' => '',
            'confirm' => 'RECORD',
        ])->assertRedirect();

        $task = DB::table('onboarding_tasks')->where('id', $taskId)->first();
        $this->assertSame('Issue laptop and access', $task->title);
        $this->assertSame($description, $task->description);
        $this->assertNull($task->code);
        $this->assertSame('pending', $task->status);
        $this->assertNull($task->completed_by);
        $this->assertNull($task->completed_at);
        $this->assertSame($required, $this->requiredStatuses($employee->id));
        $this->assertSame($onboardingStatus, DB::table('onboardings')->where('employee_id', $employee->id)->value('status'));
        $this->assertSame($tasks, DB::table('onboarding_tasks')->count());
        $this->assertSame($notices, DB::table('notifications')->count());

        $events = DB::table('employee_record_events')->where('action', 'historical_decision')->orderBy('id')->get();
        $this->assertCount(3, $events);
        $this->assertSame('Laptop handed to the employee on the first day.', $events[0]->note);
        $this->assertSame('t'.$taskId.':completed', $events[0]->subject);
        $this->assertSame('The team introduction already happened before this checklist existed.', $events[1]->note);
        $this->assertSame('t'.$taskId.':not_applicable', $events[1]->subject);
        $this->assertSame($owner->id, (int) $events[1]->actor_id);
        $this->assertNull($events[2]->note);
        $this->assertSame('t'.$taskId.':pending', $events[2]->subject);

        $this->actingAs($company->hr)->post('/employees/'.$employee->id.'/onboarding')->assertRedirect();
        $this->assertSame($tasks, DB::table('onboarding_tasks')->count());
        $this->assertSame($notices, DB::table('notifications')->count());
        $this->assertSame($description, DB::table('onboarding_tasks')->where('id', $taskId)->value('description'));
    }

    public function test_company_admin_can_leave_a_historical_task_open_with_a_note(): void
    {
        [$company, $employee, $taskId] = $this->historicalTask();
        $owner = $this->owner($company);

        $this->actingAs($owner)->post($this->path($employee->id, $taskId), [
            'outcome' => 'pending',
            'note' => 'Waiting for the employee to collect the laptop.',
            'confirm' => 'RECORD',
        ])->assertRedirect();

        $this->assertSame('pending', DB::table('onboarding_tasks')->where('id', $taskId)->value('status'));
        $this->assertNull(DB::table('onboarding_tasks')->where('id', $taskId)->value('completed_by'));
        $this->assertNull(DB::table('onboarding_tasks')->where('id', $taskId)->value('completed_at'));
        $event = DB::table('employee_record_events')->where('action', 'historical_decision')->first();
        $this->assertSame('Waiting for the employee to collect the laptop.', $event->note);
        $this->assertSame($owner->id, (int) $event->actor_id);
        $this->assertNotNull($event->created_at);
        $this->assertSame('t'.$taskId.':pending', $event->subject);
        $this->assertSame(1, DB::table('onboarding_tasks')->where('code', 'offer_issued')->where('status', 'pending')->count());
    }

    public function test_completion_metadata_follows_only_a_completed_outcome(): void
    {
        [$company, $employee, $taskId, $description] = $this->historicalTask();

        $this->actingAs($company->hr)->post($this->path($employee->id, $taskId), $this->payload('completed'))->assertRedirect();
        $completed = DB::table('onboarding_tasks')->where('id', $taskId)->first();
        $this->assertSame('completed', $completed->status);
        $this->assertSame($company->hr->id, (int) $completed->completed_by);
        $this->assertNotNull($completed->completed_at);
        $firstEventId = (int) DB::table('employee_record_events')->where('action', 'historical_decision')->value('id');

        $this->actingAs($company->hr)->post($this->path($employee->id, $taskId), [
            'outcome' => 'pending',
            'note' => '',
            'confirm' => 'RECORD',
        ])->assertRedirect();
        $reopened = DB::table('onboarding_tasks')->where('id', $taskId)->first();
        $this->assertSame('pending', $reopened->status);
        $this->assertNull($reopened->completed_by);
        $this->assertNull($reopened->completed_at);
        $this->assertSame('t'.$taskId.':completed', DB::table('employee_record_events')->where('id', $firstEventId)->value('subject'));

        $this->actingAs($company->hr)->post($this->path($employee->id, $taskId), [
            'outcome' => 'completed',
            'note' => 'Completed again after it was reopened.',
            'confirm' => 'RECORD',
        ])->assertRedirect();
        $this->actingAs($company->hr)->post($this->path($employee->id, $taskId), [
            'outcome' => 'not_applicable',
            'note' => 'The earlier completion no longer applies.',
            'confirm' => 'RECORD',
        ])->assertRedirect();
        $cleared = DB::table('onboarding_tasks')->where('id', $taskId)->first();
        $this->assertSame('not_applicable', $cleared->status);
        $this->assertNull($cleared->completed_by);
        $this->assertNull($cleared->completed_at);
        $this->assertSame($description, $cleared->description);
        $this->assertSame(4, DB::table('employee_record_events')->where('action', 'historical_decision')->count());
        $this->assertSame('Recorded during the authorization check.', DB::table('employee_record_events')->where('id', $firstEventId)->value('note'));

        $eventsBefore = DB::table('employee_record_events')->count();
        $this->actingAs($company->hr)->post($this->path($employee->id, $taskId), [
            'outcome' => 'completed',
            'note' => '',
            'confirm' => 'RECORD',
        ])->assertSessionHasErrors('note');
        $this->assertSame('not_applicable', DB::table('onboarding_tasks')->where('id', $taskId)->value('status'));
        $this->assertNull(DB::table('onboarding_tasks')->where('id', $taskId)->value('completed_by'));
        $this->assertSame($eventsBefore, DB::table('employee_record_events')->count());
    }

    public function test_other_roles_and_companies_cannot_record_a_historical_decision(): void
    {
        [$company, $employee, $taskId, $description] = $this->historicalTask();
        $other = CompanyFixture::make('Other Company', 'other-co');
        $accounts = $this->accountsUser($company);
        $platform = User::factory()->create();
        $platform->forceFill([
            'tenant_id' => null,
            'is_platform_admin' => true,
            'invite_accepted_at' => now(),
        ])->save();
        $codedId = (int) DB::table('onboarding_tasks')->where('code', 'pan')->value('id');

        $this->actingAs($company->lead)->post($this->path($employee->id, $taskId), $this->payload('completed'))->assertForbidden();
        $this->actingAs($company->employee)->post($this->path($employee->id, $taskId), $this->payload('completed'))->assertForbidden();
        $this->actingAs($accounts)->post($this->path($employee->id, $taskId), $this->payload('completed'))->assertNotFound();
        $this->actingAs($platform)->post($this->path($employee->id, $taskId), $this->payload('completed'))->assertNotFound();
        $this->actingAs($other->hr)->post($this->path($employee->id, $taskId), $this->payload('completed'))->assertNotFound();
        $this->actingAs($company->hr)->post($this->path($employee->id, $codedId), $this->payload('completed'))->assertSessionHasErrors('task');

        $this->assertSame('pending', DB::table('onboarding_tasks')->where('id', $taskId)->value('status'));
        $this->assertSame($description, DB::table('onboarding_tasks')->where('id', $taskId)->value('description'));
        $this->assertSame(0, DB::table('employee_record_events')->where('action', 'historical_decision')->count());
        $this->actingAs($company->lead)->get('/employees/'.$employee->id)
            ->assertOk()
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
                ->where('onboarding.canDecide', false)
                ->where('onboarding.tasks', fn ($rows) => collect($rows)->contains(fn ($row) => $row['title'] === 'Issue laptop and access' && $row['required'] === false)));
    }

    /**
     * @return array{0: CompanyFixture, 1: Employee, 2: int, 3: string}
     */
    private function historicalTask(): array
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $employee = Employee::query()->findOrFail($company->reportEmployeeId);
        $this->actingAs($company->hr)->post('/employees/'.$employee->id.'/onboarding')->assertRedirect();
        $onboardingId = (int) DB::table('onboardings')->where('employee_id', $employee->id)->value('id');
        $description = 'Original operational note that must stay on the task.';
        $taskId = (int) DB::table('onboarding_tasks')->insertGetId([
            'tenant_id' => $company->id,
            'onboarding_id' => $onboardingId,
            'title' => 'Issue laptop and access',
            'description' => $description,
            'assignee_role' => 'hr',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$company, $employee, $taskId, $description];
    }

    /**
     * @return array<string, string>
     */
    private function requiredStatuses(int $employeeId): array
    {
        $onboardingId = (int) DB::table('onboardings')->where('employee_id', $employeeId)->value('id');

        return DB::table('onboarding_tasks')
            ->where('onboarding_id', $onboardingId)
            ->whereNotNull('code')
            ->orderBy('code')
            ->pluck('status', 'code')
            ->all();
    }

    private function path(int $employeeId, int $taskId): string
    {
        return '/employees/'.$employeeId.'/onboarding-tasks/'.$taskId.'/decision';
    }

    /**
     * @return array<string, string>
     */
    private function payload(string $outcome): array
    {
        return [
            'outcome' => $outcome,
            'note' => 'Recorded during the authorization check.',
            'confirm' => 'RECORD',
        ];
    }

    private function owner(CompanyFixture $company): User
    {
        $user = User::factory()->create();
        $user->forceFill([
            'tenant_id' => $company->id,
            'is_platform_admin' => false,
            'invite_accepted_at' => now(),
        ])->save();
        DB::table('model_has_roles')->insert([
            'role_id' => DB::table('roles')->where('tenant_id', $company->id)->where('name', 'Owner')->value('id'),
            'model_type' => User::class,
            'model_id' => $user->id,
            'tenant_id' => $company->id,
        ]);

        return $user->refresh();
    }

    private function accountsUser(CompanyFixture $company): User
    {
        $roleId = DB::table('roles')->insertGetId([
            'tenant_id' => $company->id,
            'name' => 'Accounts',
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
}
