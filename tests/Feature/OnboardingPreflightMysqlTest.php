<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use App\Services\Hrms\EmployeeOnboarding;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\TestDatabaseGuard;
use Tests\TestCase;

class OnboardingPreflightMysqlTest extends TestCase
{
    private const DATABASE = 'meetara_hrms_preflight_20261020180530';

    protected function setUp(): void
    {
        parent::setUp();

        if (TestDatabaseGuard::integrationDecision(self::DATABASE) === 'skip') {
            $this->markTestSkipped('Onboarding preflight runs only on '.self::DATABASE.'.');
        }

        Storage::fake('local');
        Mail::fake();
    }

    public function test_restored_onboarding_offer_and_document_workflows(): void
    {
        $hr = $this->userWithRole('HR');
        $employeeId = (int) DB::table('onboardings')->value('employee_id');
        $employee = Employee::query()->findOrFail($employeeId);
        $companyId = (int) $employee->tenant_id;

        $this->actingAs($hr)->post('/employees/'.$employee->id.'/onboarding')->assertRedirect();
        $this->actingAs($hr)->post('/employees/'.$employee->id.'/onboarding')->assertRedirect();

        $tasks = DB::table('onboarding_tasks')->where('tenant_id', $companyId)->where('onboarding_id', DB::table('onboardings')->where('employee_id', $employee->id)->value('id'))->get();
        $this->assertCount(11, $tasks);
        foreach (['Share personal documents', 'Introduce the team', 'Issue laptop and access', 'Walk through company policies'] as $title) {
            $row = $tasks->firstWhere('title', $title);
            $this->assertNotNull($row);
            $this->assertNull($row->code);
            $this->assertSame('pending', $row->status);
        }
        foreach (array_keys(EmployeeOnboarding::TASKS) as $code) {
            $this->assertSame(1, $tasks->where('code', $code)->count());
        }

        $email = 'preflight.hire.'.uniqid().'@example.test';
        $this->actingAs($hr)->post('/employees', [
            'first_name' => 'Preflight',
            'last_name' => 'Hire',
            'email' => $email,
            'phone' => '5550101',
            'employee_code' => 'PRE-'.strtoupper(substr(uniqid(), -6)),
            'date_of_joining' => '2026-04-01',
            'holiday_calendar' => 'india',
            'employment_type' => 'full_time',
            'work_mode' => 'remote',
            'create_login' => false,
        ])->assertRedirect();
        $created = Employee::query()->where('email', $email)->firstOrFail();
        $this->assertSame($companyId, (int) $created->tenant_id);
        $this->assertSame(7, DB::table('onboarding_tasks')->where('onboarding_id', DB::table('onboardings')->where('employee_id', $created->id)->value('id'))->count());

        $this->actingAs($hr)->post('/employees/'.$created->id.'/documents', [
            'category' => 'resume',
            'file' => UploadedFile::fake()->create('resume.pdf', 20, 'application/pdf'),
        ])->assertRedirect();
        $documentId = (int) DB::table('employee_documents')->where('employee_id', $created->id)->where('category', 'resume')->value('id');
        $this->actingAs($employee->user)->post('/employees/'.$created->id.'/documents/'.$documentId.'/review', [
            'status' => 'verified',
            'confirm' => 'VERIFY',
        ])->assertNotFound();
        $this->actingAs($hr)->post('/employees/'.$created->id.'/documents/'.$documentId.'/review', [
            'status' => 'verified',
            'confirm' => 'VERIFY',
        ])->assertRedirect();
        $this->assertSame('verified', DB::table('employee_documents')->where('id', $documentId)->value('review_status'));

        $this->actingAs($hr)->post('/offer-letters', [
            'position_code' => 'opt_recruiter',
            'employee_id' => $created->id,
            'monthly_salary' => '45000',
            'joining_date' => '2026-04-01',
            'hr_name' => 'Preflight HR',
            'hr_signature' => 'Preflight HR',
        ])->assertRedirect();
        $letterId = (int) DB::table('offer_letters')->where('employee_id', $created->id)->value('id');
        $this->actingAs($hr)->post('/offer-letters/'.$letterId.'/preview')->assertRedirect();
        $preview = (string) DB::table('offer_letters')->where('id', $letterId)->value('preview_body');
        $this->assertStringContainsString('DRAFT — NOT APPROVED FOR ISSUANCE', $preview);
        $this->actingAs($hr)->post('/offer-letters/'.$letterId.'/issue', ['confirm' => 'ISSUE'])->assertSessionHasErrors('letter');
        $this->assertSame('generated', DB::table('offer_letters')->where('id', $letterId)->value('status'));
        $this->assertNull(DB::table('offer_letters')->where('id', $letterId)->value('issued_body'));
        $this->actingAs($hr)->get('/offer-letters/'.$letterId.'/pdf')->assertNotFound();
        $this->actingAs($employee->user)->get('/offer-letters/'.$letterId.'/pdf')->assertNotFound();

        $otherId = DB::table('tenants')->insertGetId([
            'name' => 'Preflight Other',
            'slug' => 'preflight-other-'.uniqid(),
            'timezone' => 'Asia/Kolkata',
            'week_off' => json_encode(['sunday']),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherRole = DB::table('roles')->insertGetId([
            'tenant_id' => $otherId,
            'name' => 'HR',
            'guard_name' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $other = User::factory()->create(['tenant_id' => $otherId, 'invite_accepted_at' => now()]);
        DB::table('model_has_roles')->insert([
            'role_id' => $otherRole,
            'model_type' => User::class,
            'model_id' => $other->id,
            'tenant_id' => $otherId,
        ]);
        $this->actingAs($other)->get('/employees/'.$created->id)->assertNotFound();
        $this->actingAs($other)->get('/offer-letters/'.$letterId.'/pdf')->assertNotFound();
        Mail::assertNothingSent();
    }

    private function userWithRole(string $role): User
    {
        $userId = (int) DB::table('model_has_roles as memberships')
            ->join('roles', function ($join): void {
                $join->on('roles.id', '=', 'memberships.role_id')->on('roles.tenant_id', '=', 'memberships.tenant_id');
            })
            ->where('roles.name', $role)
            ->value('memberships.model_id');

        return User::query()->findOrFail($userId);
    }
}
