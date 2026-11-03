<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\CompanyFixture;
use Tests\TestCase;

class WorkplaceBoardTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_follow_ups_stay_inside_the_company_and_block_duplicates(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-10-30 09:00:00', 'Asia/Kolkata'));
        $company = CompanyFixture::make('Acme People', 'acme-follow');
        $other = CompanyFixture::make('Other People', 'other-follow');

        $this->actingAs($company->hr)->post('/employees/'.$company->reportEmployeeId.'/follow-ups', $this->candidate('avery@example.test'))->assertRedirect();
        $this->actingAs($company->hr)->post('/employees/'.$company->reportEmployeeId.'/follow-ups', $this->candidate('avery@example.test'))
            ->assertSessionHasErrors('email');

        $id = (int) DB::table('followup_candidates')->where('tenant_id', $company->id)->value('id');
        $this->assertSame(1, DB::table('followup_events')->where('candidate_id', $id)->where('action', 'created')->count());
        $this->actingAs($other->hr)->put('/follow-ups/'.$id, $this->candidate('avery@example.test'))->assertNotFound();
        $this->actingAs($company->employee)->get('/dashboard')->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('workplace.reminders.upcoming.0.name', 'Avery Cole')
                ->where('workplace.reminders.upcoming.0.phone', '9876543210'));

        $reminder = (int) DB::table('followup_reminders')->where('candidate_id', $id)->value('id');
        $this->actingAs($company->employee)->post('/follow-up-reminders/'.$reminder.'/complete')->assertRedirect();
        $this->assertSame('completed', DB::table('followup_reminders')->where('id', $reminder)->value('status'));
        Mail::assertNothingSent();
    }

    public function test_tasks_follow_the_team_and_employees_cannot_cancel_or_reassign(): void
    {
        $company = CompanyFixture::make('Acme People', 'acme-tasks');
        $other = CompanyFixture::make('Other People', 'other-tasks');

        $this->actingAs($company->lead)->post('/work-tasks', [
            'title' => 'Prepare the joining file',
            'assignee_employee_id' => $company->reportEmployeeId,
            'priority' => 'high',
            'start_on' => '2026-11-01',
            'due_on' => '2026-11-03',
        ])->assertRedirect();
        $this->actingAs($company->lead)->post('/work-tasks', [
            'title' => 'Outside the team',
            'assignee_employee_id' => $company->outsideEmployeeId,
            'priority' => 'low',
        ])->assertNotFound();
        $this->actingAs($company->employee)->post('/work-tasks', [
            'title' => 'Self assigned',
            'assignee_employee_id' => $company->reportEmployeeId,
            'priority' => 'low',
        ])->assertNotFound();

        $task = (int) DB::table('work_tasks')->where('tenant_id', $company->id)->value('id');
        $this->actingAs($company->employee)->post('/work-tasks/'.$task.'/progress', [
            'status' => 'in_progress',
            'note' => 'Started the file.',
            'assignee_employee_id' => $company->outsideEmployeeId,
        ])->assertSessionHasErrors('assignee_employee_id');
        $this->actingAs($company->employee)->post('/work-tasks/'.$task.'/progress', [
            'status' => 'cancelled',
            'note' => 'Drop it.',
        ])->assertSessionHasErrors('status');
        $this->actingAs($company->employee)->post('/work-tasks/'.$task.'/progress', [
            'status' => 'completed',
            'note' => 'File is ready.',
        ])->assertRedirect();
        $this->assertSame('completed', DB::table('work_tasks')->where('id', $task)->value('status'));
        $this->assertSame($company->employee->id, (int) DB::table('work_tasks')->where('id', $task)->value('completed_by'));
        $this->actingAs($other->hr)->post('/work-tasks/'.$task.'/progress', ['status' => 'assigned'])->assertNotFound();
    }

    public function test_birthdays_hide_the_year_and_cross_february_and_the_new_year(): void
    {
        $company = CompanyFixture::make('Acme People', 'acme-birthdays');
        Carbon::setTestNow(Carbon::parse('2026-02-26 09:00:00', 'Asia/Kolkata'));
        DB::table('employees')->where('id', $company->reportEmployeeId)->update(['date_of_birth' => '1992-02-29']);
        DB::table('employees')->where('id', $company->outsideEmployeeId)->update(['date_of_birth' => '1990-12-25']);

        $this->actingAs($company->employee)->get('/dashboard')->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('workplace.birthdays.upcoming.0.label', 'February 28')
                ->where('workplace.birthdays.today', [])
                ->missing('workplace.birthdays.upcoming.0.year'));
        $body = $this->actingAs($company->employee)->get('/dashboard')->getContent();
        $this->assertStringNotContainsString('1992', (string) $body);

        Carbon::setTestNow(Carbon::parse('2026-12-28 09:00:00', 'Asia/Kolkata'));
        DB::table('employees')->where('id', $company->leadEmployeeId)->update(['date_of_birth' => '1991-01-02']);
        $this->actingAs($company->hr)->get('/dashboard')->assertOk()
            ->assertInertia(fn ($page) => $page->where('workplace.birthdays.upcoming.0.label', 'January 2'));
        $this->assertStringNotContainsString('1991', (string) $this->actingAs($company->hr)->get('/dashboard')->getContent());
    }

    /**
     * @return array<string, string>
     */
    private function candidate(string $email): array
    {
        return [
            'name' => 'Avery Cole',
            'email' => $email,
            'phone' => '9876543210',
            'university' => 'State University',
            'state' => 'Telangana',
            'remarks' => 'Asked for a second call.',
            'follow_up_on' => '2026-11-02',
            'remind_at' => '2026-11-02 10:30:00',
            'reminder_notes' => 'Call about the offer.',
            'status' => 'follow_up',
        ];
    }
}
