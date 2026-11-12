<?php

namespace Tests\Feature;

use App\Mail\LeadFollowUpReminderMail;
use App\Models\Lead;
use App\Services\Hrms\LeadFollowUpReminder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CompanyFixture;
use Tests\TestCase;

class LeadFollowUpReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-12 09:00:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_company_lead_list_exposes_reminder_flags_and_follow_up_fields(): void
    {
        $company = CompanyFixture::make('Reminder List', 'reminderlist');
        $this->lead($company, null, [
            'name' => 'Due Today',
            'follow_up_on' => '2026-11-12',
            'remarks' => 'Call again',
        ]);
        $this->lead($company, null, [
            'name' => 'Overdue Person',
            'follow_up_on' => '2026-11-10',
        ]);
        $this->lead($company, null, [
            'name' => 'Upcoming Person',
            'follow_up_on' => '2026-11-13',
        ]);

        $this->actingAs($company->hr)->get('/leads')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('statuses.0.value', 'new')
                ->where('leads', fn ($rows): bool => collect($rows)->contains(fn ($row): bool => $row['name'] === 'Due Today' && $row['reminder'] === 'due_today' && $row['remarks'] === 'Call again')
                    && collect($rows)->contains(fn ($row): bool => $row['name'] === 'Overdue Person' && $row['reminder'] === 'overdue')
                    && collect($rows)->contains(fn ($row): bool => $row['name'] === 'Upcoming Person' && $row['reminder'] === 'upcoming')));
    }

    public function test_follow_up_from_the_list_updates_status_and_clears_reminder_marker(): void
    {
        $company = CompanyFixture::make('Reminder Clear', 'reminderclear');
        $leadId = $this->lead($company, $company->reportEmployeeId, [
            'name' => 'Clear Reminder',
            'follow_up_on' => '2026-11-10',
            'follow_up_reminded_on' => '2026-11-10',
            'pipeline_status' => 'new',
        ]);

        $this->actingAs($company->hr)->post("/leads/{$leadId}/follow-ups", [
            'pipeline_status' => 'follow_up',
            'follow_up_on' => '2026-11-13',
            'remarks' => 'Call tomorrow',
            'return_to' => '/leads',
        ])->assertRedirect('/leads')->assertSessionHas('status', 'Follow-up saved.');

        $lead = DB::table('leads')->where('id', $leadId)->first();
        $this->assertSame('follow_up', $lead->pipeline_status);
        $this->assertSame('2026-11-13', substr((string) $lead->follow_up_on, 0, 10));
        $this->assertSame('Call tomorrow', $lead->remarks);
        $this->assertNull($lead->follow_up_reminded_on);
        $this->assertSame($company->reportEmployeeId, (int) $lead->assignee_employee_id);
        $this->assertSame($company->leadEmployeeId, (int) $lead->team_lead_employee_id);
        $this->assertSame(1, DB::table('lead_activities')->where('lead_id', $leadId)->where('type', 'follow_up')->count());
    }

    public function test_reminder_command_emails_the_assignee_once(): void
    {
        Mail::fake();
        $company = CompanyFixture::make('Reminder Mail', 'remindermail');
        $company->employee->forceFill(['email' => 'assignee-reminder@example.com', 'name' => 'Assigned Person'])->save();
        $leadId = $this->lead($company, $company->reportEmployeeId, [
            'name' => 'Mail Candidate',
            'phone' => '555-0100',
            'follow_up_on' => '2026-11-11',
            'pipeline_status' => 'contacted',
        ]);

        $first = app(LeadFollowUpReminder::class)->sendDue();
        $this->assertSame(1, $first['sent']);
        Mail::assertSent(LeadFollowUpReminderMail::class, function (LeadFollowUpReminderMail $mail): bool {
            return $mail->hasTo('assignee-reminder@example.com')
                && $mail->leadName === 'Mail Candidate'
                && $mail->followUpOn === '2026-11-11'
                && str_contains($mail->url, '/leads/mine/');
        });

        $lead = DB::table('leads')->where('id', $leadId)->first();
        $this->assertSame('2026-11-11', substr((string) $lead->follow_up_reminded_on, 0, 10));

        Mail::fake();
        $second = app(LeadFollowUpReminder::class)->sendDue();
        $this->assertSame(0, $second['sent']);
        Mail::assertNothingSent();

        Artisan::call('hrms:send-lead-follow-up-reminders');
        $this->assertStringContainsString('Sent 0', Artisan::output());
    }

    private function lead(CompanyFixture $company, ?int $assigneeId, array $overrides): int
    {
        $lead = new Lead;
        $lead->forceFill(array_merge([
            'tenant_id' => $company->id,
            'name' => 'Candidate',
            'pipeline_status' => 'new',
            'team_lead_employee_id' => $assigneeId === null ? null : $company->leadEmployeeId,
            'assignee_employee_id' => $assigneeId,
            'created_by' => $company->hr->id,
        ], $overrides))->save();

        return (int) $lead->id;
    }
}
