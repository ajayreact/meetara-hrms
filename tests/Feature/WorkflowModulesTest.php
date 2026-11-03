<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Hrms\CompanyNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CompanyFixture;
use Tests\TestCase;

class WorkflowModulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_help_desk_is_company_scoped_and_audited(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $other = CompanyFixture::make('Deep Data Inc', 'deep');

        $this->from('/help-desk')->actingAs($company->employee)->post('/help-desk', [
            'category' => 'Attendance',
            'title' => 'Badge access',
            'description' => 'The office door badge is not opening.',
            'priority' => 'medium',
            'tenant_id' => $other->id,
        ])->assertSessionHasErrors('tenant_id');

        $this->actingAs($company->employee)->post('/help-desk', [
            'category' => 'Attendance',
            'title' => 'Badge access',
            'description' => 'The office door badge is not opening.',
            'priority' => 'medium',
        ])->assertRedirect();

        $ticketId = (int) DB::table('tickets')->where('title', 'Badge access')->value('id');
        $this->assertSame($company->reportEmployeeId, (int) DB::table('tickets')->where('id', $ticketId)->value('employee_id'));
        $this->assertSame(1, DB::table('ticket_events')->where('ticket_id', $ticketId)->where('action', 'opened')->count());

        $this->actingAs($company->lead)->get('/help-desk?ticket='.$ticketId)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('selected', null));
        $this->actingAs($company->lead)->post('/help-desk/'.$ticketId.'/comments', ['body' => 'I can see this'])->assertNotFound();
        $this->actingAs($other->hr)->post('/help-desk/'.$ticketId.'/comments', ['body' => 'Cross company'])->assertNotFound();

        $this->actingAs($company->hr)->post('/help-desk/'.$ticketId.'/assign', ['assignee_id' => $company->hr->id])->assertRedirect();
        $this->actingAs($company->hr)->post('/help-desk/'.$ticketId.'/assign', ['assignee_id' => $company->hr->id])->assertRedirect();
        $this->assertSame(1, DB::table('ticket_events')->where('ticket_id', $ticketId)->where('action', 'assigned')->count());
        $this->actingAs($company->employee)->post('/help-desk/'.$ticketId.'/assign', ['assignee_id' => $company->hr->id])->assertForbidden();
        $this->actingAs($company->hr)->post('/help-desk/'.$ticketId.'/status', ['status' => 'resolved'])->assertStatus(422);
        $this->actingAs($company->hr)->post('/help-desk/'.$ticketId.'/status', ['status' => 'in_progress'])->assertRedirect();
        $this->assertSame('in_progress', DB::table('tickets')->where('id', $ticketId)->value('status'));
    }

    public function test_notifications_belong_to_the_recipient_and_are_not_duplicated(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $notifier = app(CompanyNotifier::class);
        $notifier->once($company->employee->id, 'sample-key', 'Notice', 'A saved notice.', '/inbox');
        $notifier->once($company->employee->id, 'sample-key', 'Notice', 'A saved notice.', '/inbox');
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $company->employee->id)->count());

        $id = (string) DB::table('notifications')->where('notifiable_id', $company->employee->id)->value('id');
        $this->actingAs($company->lead)->post('/inbox/'.$id.'/read')->assertNotFound();
        $this->actingAs($company->employee)->post('/inbox/'.$id.'/read')->assertRedirect();
        $this->actingAs($company->employee)->post('/inbox/'.$id.'/read')->assertRedirect();
        $this->assertNotNull(DB::table('notifications')->where('id', $id)->value('read_at'));
        $this->actingAs($company->employee)->get('/inbox')->assertInertia(fn (Assert $page) => $page->where('notifications.0.read', true));
    }

    public function test_internal_mail_stays_inside_the_company_and_is_not_sent_by_smtp(): void
    {
        Mail::fake();
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $other = CompanyFixture::make('Deep Data Inc', 'deep');

        $this->actingAs($company->hr)->get('/inbox')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canCompose', true)->has('recipients', 3));

        $this->from('/inbox')->actingAs($company->hr)->post('/inbox', [
            'recipient_id' => $other->employee->id,
            'subject' => 'Cross company',
            'message' => 'This must stay inside Veltrix.',
        ])->assertSessionHasErrors('recipient_id');

        $this->from('/inbox')->actingAs($company->hr)->post('/inbox', [
            'recipient_id' => $company->employee->id,
            'subject' => 'Welcome note',
            'message' => 'Your desk is ready on the second floor.',
            'tenant_id' => $other->id,
        ])->assertSessionHasErrors('tenant_id');

        $this->from('/inbox')->actingAs($company->hr)->post('/inbox', [
            'recipient_id' => $company->hr->id,
            'subject' => 'To myself',
            'message' => 'Not allowed.',
        ])->assertSessionHasErrors('recipient_id');

        $this->actingAs($company->hr)->post('/inbox', [
            'recipient_id' => $company->employee->id,
            'subject' => 'Welcome note',
            'message' => 'Your desk is ready on the second floor.',
        ])->assertRedirect()->assertSessionHas('status', 'Message delivered inside the app.');

        Mail::assertNothingSent();

        $incoming = DB::table('notifications')->where('notifiable_id', $company->employee->id)->where('data->direction', 'in')->first();
        $outgoing = DB::table('notifications')->where('notifiable_id', $company->hr->id)->where('data->direction', 'out')->first();
        $this->assertNotNull($incoming);
        $this->assertNotNull($outgoing);
        $this->assertNull($incoming->read_at);
        $this->assertNotNull($outgoing->read_at);
        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $other->employee->id)->where('data->title', 'Welcome note')->count());
        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $other->hr->id)->where('data->title', 'Welcome note')->count());

        $this->actingAs($company->employee)->get('/inbox')->assertInertia(fn (Assert $page) => $page
            ->where('notifications.0.title', 'Welcome note')
            ->where('notifications.0.message', 'Your desk is ready on the second floor.')
            ->where('notifications.0.from', $company->hr->name)
            ->where('notifications.0.direction', 'in')
            ->where('notifications.0.read', false));

        $this->actingAs($company->hr)->get('/inbox')->assertInertia(fn (Assert $page) => $page
            ->where('notifications.0.title', 'Welcome note')
            ->where('notifications.0.direction', 'out')
            ->where('notifications.0.to', $company->employee->name)
            ->where('notifications.0.read', true));
    }

    public function test_overtime_review_does_not_calculate_pay(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $items = DB::table('payroll_items')->count();

        $this->from('/overtime')->actingAs($company->employee)->post('/overtime', [
            'work_date' => '2026-11-02',
            'hours' => '1',
            'reason' => 'Covered the late deployment.',
            'employee_id' => $company->outsideEmployeeId,
        ])->assertSessionHasErrors('employee_id');
        $this->actingAs($company->employee)->post('/overtime', [
            'work_date' => '2026-11-03',
            'hours' => '1.5',
            'reason' => 'Covered the late deployment.',
        ])->assertRedirect();
        $this->actingAs($company->employee)->post('/overtime', [
            'work_date' => '2026-11-03',
            'hours' => '2',
            'reason' => 'Trying the same day again.',
        ])->assertStatus(422);
        $this->from('/overtime')->actingAs($company->employee)->post('/overtime', [
            'work_date' => '2026-11-04',
            'hours' => '0.25',
            'reason' => 'A quarter hour is not valid.',
        ])->assertSessionHasErrors('hours');

        $entryId = (int) DB::table('overtime_entries')->where('employee_id', $company->reportEmployeeId)->value('id');
        $this->assertSame(90, (int) DB::table('overtime_entries')->where('id', $entryId)->value('minutes'));
        $this->actingAs($company->employee)->post('/overtime/'.$entryId.'/decide', ['decision' => 'approved'])->assertForbidden();
        $outsideId = (int) DB::table('overtime_entries')->insertGetId([
            'tenant_id' => $company->id,
            'employee_id' => $company->outsideEmployeeId,
            'work_date' => '2026-11-05',
            'minutes' => 60,
            'reason' => 'Outside the team.',
            'status' => 'pending',
            'payroll_status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($company->lead)->post('/overtime/'.$outsideId.'/decide', ['decision' => 'approved'])->assertNotFound();
        $this->actingAs($company->lead)->post('/overtime/'.$entryId.'/decide', ['decision' => 'approved'])->assertRedirect();
        $this->assertSame('approved', DB::table('overtime_entries')->where('id', $entryId)->value('status'));
        $this->assertSame('pending', DB::table('overtime_entries')->where('id', $entryId)->value('payroll_status'));
        $this->assertSame(1, DB::table('overtime_events')->where('overtime_entry_id', $entryId)->where('action', 'approved')->count());
        $this->actingAs($company->lead)->post('/overtime/'.$entryId.'/decide', ['decision' => 'approved'])->assertStatus(422);
        $this->assertSame($items, DB::table('payroll_items')->count());
    }

    public function test_platform_screens_use_saved_companies_and_hide_route_maps(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $admin = User::factory()->create();
        $admin->forceFill(['is_platform_admin' => true, 'tenant_id' => null, 'invite_accepted_at' => now()])->save();

        $this->actingAs($company->employee)->get('/companies')->assertInertia(fn (Assert $page) => $page->component('hrms/forbidden'));
        $this->actingAs($company->employee)->get('/route-map')->assertOk()->assertInertia(fn (Assert $page) => $page->component('route-map/index')->where('map.canViewPreciseLocations', true));
        $this->actingAs($admin)->get('/payroll')->assertForbidden();
        $this->actingAs($admin)->get('/companies?search=Veltrix')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('companies/index')
            ->where('companies.0.name', 'Veltrix Digital Solutions Inc')
            ->where('companies.0.headcount', 3));
        $this->actingAs($admin)->get('/companies?search=Missing')->assertInertia(fn (Assert $page) => $page->where('companies', []));
    }
}
