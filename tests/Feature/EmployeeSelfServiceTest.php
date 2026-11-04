<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CompanyFixture;
use Tests\TestCase;

class EmployeeSelfServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_dashboard_and_profile_stay_inside_the_company(): void
    {
        $company = CompanyFixture::make('Acme', 'ess');
        $other = CompanyFixture::make('Other', 'ess-other');

        $this->actingAs($company->employee)->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('dashboard/employee')
            ->where('ess.welcome.code', 'ess-REP')
            ->where('ess.welcome.department', 'ess-department')
            ->where('ess.welcome.office', 'ess-office')
            ->where('ess.pendingLeave', 1)
            ->where('ess.payslip', null)
            ->where('ess.actions.2.label', 'Apply for Leave')
        );

        $this->actingAs($company->hr)->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('ess', null)
        );

        $this->actingAs($company->employee)->get('/my-profile')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('profile/mine')
            ->where('profileCard.employment.code', 'ess-REP')
            ->where('profileCard.personal.address', null)
            ->where('profileCard.payroll.bank', null)
            ->where('profileCard.payroll.note', 'Bank account details are not stored on the employee record.')
        );

        $this->actingAs($company->employee)->get('/employees/'.$other->reportEmployeeId)->assertNotFound();
        $this->actingAs($company->employee)->get('/employees/'.$company->outsideEmployeeId)->assertNotFound();
    }

    public function test_announcements_publish_once_and_hide_drafts_and_expired_rows(): void
    {
        Storage::fake('local');
        $company = CompanyFixture::make('Acme', 'announce');
        $other = CompanyFixture::make('Other', 'announce-other');
        $owner = $this->owner($company);
        $accounts = $this->accounts($company);

        $this->actingAs($company->lead)->post('/announcements', $this->payload())->assertForbidden();
        $this->actingAs($accounts)->post('/announcements', $this->payload())->assertForbidden();
        $this->actingAs($company->employee)->post('/announcements', $this->payload())->assertForbidden();

        $this->actingAs($company->hr)->post('/announcements', [
            ...$this->payload('Draft notice'),
            'attachment' => UploadedFile::fake()->image('notice.png'),
        ])->assertRedirect();

        $draftId = (int) DB::table('hr_announcements')->where('title', 'Draft notice')->value('id');
        $this->actingAs($company->employee)->get('/announcements')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('announcements', [])
        );
        $this->actingAs($company->employee)->get('/announcements/'.$draftId)->assertNotFound();

        $this->actingAs($company->hr)->post('/announcements/'.$draftId.'/publish')->assertRedirect();
        $this->actingAs($company->hr)->post('/announcements/'.$draftId.'/publish')->assertRedirect();

        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $company->employee->id)->where('data->key', 'announcement-'.$draftId)->count());
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $company->lead->id)->where('data->key', 'announcement-'.$draftId)->count());

        $this->actingAs($company->employee)->get('/announcements')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('canManage', false)
            ->where('announcements.0.title', 'Draft notice')
            ->where('announcements.0.status', 'Published')
            ->where('unread', 1)
        );
        $this->actingAs($company->employee)->get('/announcements/'.$draftId)->assertOk();
        $this->actingAs($company->employee)->get('/announcements/'.$draftId.'/attachment')->assertOk();
        $this->actingAs($other->employee)->get('/announcements/'.$draftId)->assertNotFound();

        $notificationId = (string) DB::table('notifications')->where('notifiable_id', $company->employee->id)->where('data->key', 'announcement-'.$draftId)->value('id');
        $this->actingAs($company->hr)->post('/inbox/'.$notificationId.'/read')->assertNotFound();
        $this->actingAs($company->employee)->get('/inbox')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('notifications.0.topic', 'announcement')
        );
        $this->actingAs($company->employee)->post('/inbox/'.$notificationId.'/read')->assertRedirect();
        $this->assertNotNull(DB::table('notifications')->where('id', $notificationId)->value('read_at'));
        $this->actingAs($company->employee)->get('/announcements')->assertInertia(fn (Assert $page) => $page->where('unread', 0));

        $this->actingAs($owner)->post('/announcements', $this->payload('Future notice', now()->addDay()->toDateTimeString()))->assertRedirect();
        $futureId = (int) DB::table('hr_announcements')->where('title', 'Future notice')->value('id');
        $this->actingAs($owner)->post('/announcements/'.$futureId.'/publish')->assertRedirect();
        $this->assertSame('scheduled', DB::table('hr_announcements')->where('id', $futureId)->value('status'));
        $this->actingAs($company->employee)->get('/announcements/'.$futureId)->assertNotFound();
        $this->assertSame(0, DB::table('notifications')->where('data->key', 'announcement-'.$futureId)->count());

        DB::table('hr_announcements')->where('id', $draftId)->update(['expires_on' => now()->subDay()->toDateString()]);
        $this->actingAs($company->employee)->get('/announcements')->assertInertia(fn (Assert $page) => $page->where('announcements', []));
        $this->actingAs($company->hr)->get('/announcements')->assertInertia(fn (Assert $page) => $page
            ->where('canManage', true)
            ->where('announcements.0.status', 'Scheduled')
            ->where('announcements.1.status', 'Expired')
        );

        $this->actingAs($company->hr)->post('/announcements', $this->payload('HR only', null, 'hr'))->assertRedirect();
        $hrId = (int) DB::table('hr_announcements')->where('title', 'HR only')->value('id');
        $this->actingAs($company->hr)->post('/announcements/'.$hrId.'/publish')->assertRedirect();
        $this->actingAs($company->employee)->get('/announcements/'.$hrId)->assertNotFound();
        $this->actingAs($company->lead)->get('/announcements/'.$hrId)->assertNotFound();
        $this->actingAs($owner)->get('/announcements/'.$hrId)->assertOk();

        $this->actingAs($company->hr)->post('/announcements/'.$hrId.'/archive')->assertRedirect();
        $this->actingAs($company->employee)->get('/announcements/'.$hrId)->assertNotFound();
        $this->actingAs($owner)->get('/announcements/'.$hrId)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('announcement.status', 'Archived')
        );
    }

    public function test_employee_leave_and_payslip_access_remains_personal(): void
    {
        $company = CompanyFixture::make('Acme', 'ess-pay');
        $other = CompanyFixture::make('Other', 'ess-pay-other');
        DB::table('payroll_runs')->where('id', $company->payrollRunId)->update(['status' => 'processed']);
        DB::table('payroll_runs')->where('id', $other->payrollRunId)->update(['status' => 'processed']);

        $this->actingAs($company->employee)->get('/leave')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('leave/index')
            ->where('canApply', true)
            ->where('canManagePolicies', false)
            ->where('requests.0.id', $company->leaveId)
        );
        $this->actingAs($company->employee)->post('/leave/'.$company->leaveId.'/approve')->assertForbidden();

        $this->actingAs($company->employee)->get('/payslips')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('payslips.0.id', $company->payrollItemId)
            ->where('payslips', fn ($rows) => count($rows) === 1)
        );
        $this->actingAs($company->employee)->get('/payslips/'.$other->payrollItemId)->assertNotFound();
        $this->actingAs($company->lead)->get('/payslips')->assertOk();
        $this->actingAs($company->hr)->get('/payslips/'.$company->payrollItemId)->assertOk();
    }

    /**
     * @return array<string, string|null>
     */
    private function payload(string $title = 'Office notice', ?string $publishAt = null, string $visibility = 'company'): array
    {
        return [
            'title' => $title,
            'description' => 'Please read this notice.',
            'category' => 'general',
            'visibility' => $visibility,
            'publish_at' => $publishAt,
            'expires_on' => now()->addMonth()->toDateString(),
        ];
    }

    private function owner(CompanyFixture $company): User
    {
        return $this->member($company, 'Owner');
    }

    private function accounts(CompanyFixture $company): User
    {
        $now = now();
        DB::table('roles')->insert([
            'tenant_id' => $company->id,
            'name' => 'Accounts',
            'guard_name' => 'web',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->member($company, 'Accounts');
    }

    private function member(CompanyFixture $company, string $role): User
    {
        $user = User::factory()->create();
        $user->forceFill([
            'tenant_id' => $company->id,
            'is_platform_admin' => false,
            'invite_accepted_at' => now(),
        ])->save();
        DB::table('model_has_roles')->insert([
            'role_id' => DB::table('roles')->where('tenant_id', $company->id)->where('name', $role)->value('id'),
            'model_type' => User::class,
            'model_id' => $user->id,
            'tenant_id' => $company->id,
        ]);

        return $user->refresh();
    }
}
