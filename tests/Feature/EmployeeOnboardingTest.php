<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use App\Notifications\EmployeeJoinedNotification;
use App\Services\Hrms\EmployeeOnboarding;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Support\CompanyFixture;
use Tests\TestCase;

class EmployeeOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Mail::fake();
    }

    protected function tearDown(): void
    {
        cache()->flush();
        parent::tearDown();
    }

    public function test_creating_an_employee_opens_a_checklist_without_completing_onboarding(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        Mail::assertNothingSent();

        $this->actingAs($company->hr)->post('/employees', [
            'first_name' => 'New',
            'last_name' => 'Person',
            'email' => 'new.person@example.test',
            'phone' => '5550100',
            'employee_code' => 'VEL-NEW',
            'department_id' => $company->departmentId,
            'manager_id' => $company->leadEmployeeId,
            'date_of_joining' => '2026-02-01',
            'holiday_calendar' => 'india',
            'employment_type' => 'full_time',
            'work_mode' => 'remote',
            'create_login' => false,
            'tenant_id' => 999,
        ])->assertRedirect();

        $employee = Employee::query()->where('email', 'new.person@example.test')->firstOrFail();
        $this->assertSame($company->id, (int) $employee->tenant_id);
        $this->assertSame(1, DB::table('onboardings')->where('employee_id', $employee->id)->count());
        $this->assertSame('in_progress', DB::table('onboardings')->where('employee_id', $employee->id)->value('status'));
        $this->assertSame(7, DB::table('onboarding_tasks')->where('tenant_id', $company->id)->whereIn('code', array_keys(EmployeeOnboarding::TASKS))->count());
        $this->assertSame('completed', DB::table('onboarding_tasks')->where('code', 'profile')->value('status'));
        $this->assertNull(DB::table('onboardings')->where('employee_id', $employee->id)->value('completed_at'));

        $this->actingAs($company->hr)->get('/employees/'.$employee->id)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('onboarding.status', 'In Progress')
                ->where('onboarding.tasks.0.title', 'Employee profile completed')
                ->where('onboarding.tasks.1.title', 'Offer letter generated/issued')
                ->where('onboarding.tasks.1.status', 'Pending'));

        $before = DB::table('notifications')->count();
        app(EmployeeOnboarding::class)->open($company->hr, $employee);
        $this->assertSame($before, DB::table('notifications')->count());
        $this->assertSame(1, DB::table('onboardings')->where('employee_id', $employee->id)->count());
        $this->assertSame(7, DB::table('onboarding_tasks')->where('onboarding_id', DB::table('onboardings')->where('employee_id', $employee->id)->value('id'))->count());
    }

    public function test_welcome_notifications_go_only_to_the_same_company_and_are_not_repeated(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $other = CompanyFixture::make('Deep Data Inc', 'deep-data');
        $owner = $this->owner($company);

        $this->actingAs($company->hr)->post('/employees/'.$company->reportEmployeeId.'/onboarding')->assertRedirect();
        app(EmployeeOnboarding::class)->open($company->hr, Employee::query()->findOrFail($company->reportEmployeeId));

        $messages = DB::table('notifications')->where('type', EmployeeJoinedNotification::class)->get();
        $recipients = $messages->pluck('notifiable_id')->map(fn ($id) => (int) $id)->all();

        $outsideUser = (int) DB::table('employees')->where('id', $company->outsideEmployeeId)->value('user_id');
        $this->assertEqualsCanonicalizing([$company->hr->id, $owner->id, $company->lead->id, $outsideUser], $recipients);
        $this->assertNotContains($other->hr->id, $recipients);
        $this->assertNotContains($company->employee->id, $recipients);
        $this->assertStringContainsString('Welcome Our New Team Member!', (string) $messages->first()->data);

        foreach ($messages as $message) {
            $data = json_decode((string) $message->data, true);
            $this->assertStringNotContainsString('salary', strtolower((string) $message->data));
            $this->assertStringNotContainsString($company->reportEmail, (string) $message->data);
            $this->assertStringNotContainsString('pan', strtolower((string) $message->data));
            $this->assertStringContainsString('/employees/'.$company->reportEmployeeId, (string) ($data['url'] ?? ''));
        }

        Mail::assertNothingSent();
    }

    public function test_documents_stay_private_and_follow_review_permissions(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $other = CompanyFixture::make('Deep Data Inc', 'deep-data');
        $employee = Employee::query()->findOrFail($company->reportEmployeeId);
        $this->actingAs($company->hr)->post('/employees/'.$employee->id.'/onboarding')->assertRedirect();

        $pan = UploadedFile::fake()->create('PAN-ABCDE1234F.pdf', 20, 'application/pdf');
        $this->actingAs($company->employee)->post('/employees/'.$employee->id.'/documents', [
            'category' => 'pan',
            'file' => $pan,
        ])->assertRedirect();

        $document = DB::table('employee_documents')->where('employee_id', $employee->id)->where('category', 'pan')->first();
        $this->assertNotNull($document);
        $this->assertStringStartsWith('employee-documents/'.$company->id.'/'.$employee->id.'/', (string) $document->file_path);
        $this->assertStringNotContainsString('PAN', (string) $document->file_path);
        $this->assertStringEndsWith('private', str_replace('\\', '/', (string) config('filesystems.disks.local.root')));
        Storage::disk('local')->assertExists((string) $document->file_path);
        Storage::disk('public')->assertMissing((string) $document->file_path);
        $this->assertSame('pending', $document->review_status);

        $this->actingAs($company->employee)->post('/employees/'.$employee->id.'/documents/'.$document->id.'/review', [
            'status' => 'verified',
            'confirm' => 'VERIFY',
        ])->assertForbidden();

        $this->actingAs($company->lead)->post('/employees/'.$employee->id.'/documents', [
            'category' => 'resume',
            'file' => UploadedFile::fake()->create('resume.pdf', 20, 'application/pdf'),
        ])->assertForbidden();
        $this->actingAs($company->lead)->get('/employees/'.$employee->id.'/documents/'.$document->id)->assertForbidden();

        $this->actingAs($other->hr)->get('/employees/'.$employee->id.'/documents/'.$document->id)->assertNotFound();
        $this->actingAs($company->hr)->get('/employees/'.$company->reportEmployeeId.'/documents/99999')->assertNotFound();

        $this->actingAs($company->employee)->post('/employees/'.$employee->id.'/documents', [
            'category' => 'generated_offer',
            'file' => UploadedFile::fake()->create('offer.pdf', 20, 'application/pdf'),
        ])->assertSessionHasErrors('category');

        $this->actingAs($company->hr)->post('/employees/'.$employee->id.'/documents', [
            'category' => 'resume',
            'file' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
        ])->assertSessionHasErrors('file');

        $this->actingAs($company->hr)->post('/employees/'.$employee->id.'/documents/'.$document->id.'/review', [
            'status' => 'rejected',
            'note' => 'account 9988776655',
            'confirm' => 'REJECT',
        ])->assertRedirect();

        $this->actingAs($company->hr)->get('/employees/'.$employee->id)
            ->assertOk()
            ->assertSee('****')
            ->assertDontSee('1234')
            ->assertDontSee('9988776655')
            ->assertDontSee('employee-documents/');

        $events = DB::table('employee_record_events')->pluck('subject')->implode(' ');
        $this->assertStringNotContainsString('9988776655', $events);
        $this->assertStringNotContainsString('9988776655', DB::table('notifications')->pluck('data')->implode(' '));

        $this->actingAs($company->employee)->post('/employees/'.$employee->id.'/documents', [
            'category' => 'pan',
            'file' => UploadedFile::fake()->create('pan-card.pdf', 20, 'application/pdf'),
        ])->assertRedirect();

        $replacement = DB::table('employee_documents')->where('employee_id', $employee->id)->where('category', 'pan')->orderByDesc('id')->first();
        $this->assertSame((int) $document->id, (int) $replacement->replaces_document_id);
        $this->assertSame('rejected', DB::table('employee_documents')->where('id', $document->id)->value('review_status'));
        $this->assertNotSame('completed', DB::table('onboardings')->where('employee_id', $employee->id)->value('status'));

        $this->actingAs($company->employee)->get('/employees/'.$employee->id.'/documents/'.$replacement->id)->assertOk();
    }

    public function test_onboarding_completes_only_after_every_required_document_is_verified(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $employee = Employee::query()->findOrFail($company->outsideEmployeeId);
        $this->actingAs($company->hr)->post('/employees/'.$employee->id.'/onboarding')->assertRedirect();

        foreach (['generated_offer', 'signed_offer', 'pan', 'aadhaar', 'bank_proof', 'resume'] as $category) {
            $this->actingAs($company->hr)->post('/employees/'.$employee->id.'/documents', [
                'category' => $category,
                'file' => UploadedFile::fake()->create($category.'.pdf', 20, 'application/pdf'),
            ])->assertRedirect();
            $id = (int) DB::table('employee_documents')->where('employee_id', $employee->id)->where('category', $category)->value('id');
            $this->actingAs($company->hr)->post('/employees/'.$employee->id.'/documents/'.$id.'/review', [
                'status' => 'verified',
                'confirm' => 'VERIFY',
            ])->assertRedirect();
            if ($category !== 'resume') {
                $this->assertNotSame('completed', DB::table('onboardings')->where('employee_id', $employee->id)->value('status'));
            }
        }

        $this->assertSame('completed', DB::table('onboardings')->where('employee_id', $employee->id)->value('status'));
        $this->assertNotNull(DB::table('onboardings')->where('employee_id', $employee->id)->value('completed_at'));
    }

    public function test_existing_uncoded_tasks_are_kept_and_required_tasks_are_not_duplicated(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $employee = Employee::query()->findOrFail($company->reportEmployeeId);
        $onboardingId = DB::table('onboardings')->insertGetId([
            'tenant_id' => $company->id,
            'employee_id' => $employee->id,
            'status' => 'active',
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $historical = [
            ['Share personal documents', 'employee', '2026-10-21'],
            ['Introduce the team', 'manager', '2026-10-22'],
            ['Issue laptop and access', 'hr', '2026-10-20'],
            ['Walk through company policies', 'hr', '2026-10-24'],
        ];

        foreach ($historical as [$title, $role, $due]) {
            DB::table('onboarding_tasks')->insert([
                'tenant_id' => $company->id,
                'onboarding_id' => $onboardingId,
                'title' => $title,
                'description' => $title,
                'assignee_role' => $role,
                'due_date' => $due,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('onboarding_tasks')->insert([
            'tenant_id' => $company->id,
            'onboarding_id' => $onboardingId,
            'title' => 'Resume uploaded and reviewed',
            'description' => 'Keep this note',
            'assignee_role' => 'hr',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($company->hr)->post('/employees/'.$employee->id.'/onboarding')->assertRedirect();
        $this->actingAs($company->hr)->post('/employees/'.$employee->id.'/onboarding')->assertRedirect();

        $tasks = DB::table('onboarding_tasks')->where('onboarding_id', $onboardingId)->get();
        $this->assertCount(11, $tasks);

        foreach ($historical as [$title, $role, $due]) {
            $row = $tasks->firstWhere('title', $title);
            $this->assertNull($row->code);
            $this->assertSame('pending', $row->status);
            $this->assertSame($title, $row->description);
            $this->assertSame($role, $row->assignee_role);
            $this->assertSame($due, substr((string) $row->due_date, 0, 10));
        }

        $resume = $tasks->firstWhere('code', 'resume');
        $this->assertSame('Keep this note', $resume->description);
        $this->assertSame('pending', $resume->status);
        $this->assertSame(1, $tasks->where('code', 'resume')->count());

        foreach (array_keys(EmployeeOnboarding::TASKS) as $code) {
            $this->assertSame(1, $tasks->where('code', $code)->count());
        }

        $this->assertSame('completed', $tasks->firstWhere('code', 'profile')->status);
        DB::table('onboarding_tasks')->where('onboarding_id', $onboardingId)->whereNotNull('code')->update(['status' => 'completed']);
        app(EmployeeOnboarding::class)->sync($employee);
        $this->assertSame('completed', DB::table('onboardings')->where('id', $onboardingId)->value('status'));
        $this->assertSame(4, DB::table('onboarding_tasks')->where('onboarding_id', $onboardingId)->whereNull('code')->where('status', 'pending')->count());

        $this->actingAs($company->hr)->get('/employees/'.$employee->id)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('onboarding.tasks', fn ($rows) => collect($rows)->where('note', 'Historical task. It was not mapped to a required checklist item.')->count() === 4));
    }

    public function test_document_review_updates_review_fields_and_appends_an_event(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $other = CompanyFixture::make('Deep Data Inc', 'deep-data');
        $employee = Employee::query()->findOrFail($company->reportEmployeeId);
        $this->actingAs($company->hr)->post('/employees/'.$employee->id.'/onboarding')->assertRedirect();
        $onboardingId = (int) DB::table('onboardings')->where('employee_id', $employee->id)->value('id');
        $description = 'Synthetic historical description that a document review must not replace.';
        DB::table('onboarding_tasks')->insert([
            'tenant_id' => $company->id,
            'onboarding_id' => $onboardingId,
            'title' => 'Issue laptop and access',
            'description' => $description,
            'assignee_role' => 'hr',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($company->employee)->post('/employees/'.$employee->id.'/documents', [
            'category' => 'pan',
            'file' => UploadedFile::fake()->create('pan-card.pdf', 20, 'application/pdf'),
        ])->assertRedirect();

        $document = DB::table('employee_documents')->where('employee_id', $employee->id)->where('category', 'pan')->first();
        $path = '/employees/'.$employee->id.'/documents/'.$document->id.'/review';
        $events = DB::table('employee_record_events')->count();
        $required = DB::table('onboarding_tasks')->where('onboarding_id', $onboardingId)->where('code', 'pan')->value('status');

        $this->actingAs($company->hr)->post($path, [
            'status' => 'rejected',
            'note' => '',
            'confirm' => 'REJECT',
        ])->assertSessionHasErrors('note');
        $this->actingAs($company->hr)->post($path, [
            'status' => 'under_review',
            'note' => 'Checking the document.',
            'confirm' => 'VERIFY',
        ])->assertSessionHasErrors('confirm');
        $this->assertSame('pending', DB::table('employee_documents')->where('id', $document->id)->value('review_status'));
        $this->assertNull(DB::table('employee_documents')->where('id', $document->id)->value('reviewed_by'));
        $this->assertSame($events, DB::table('employee_record_events')->count());
        $this->assertSame($required, DB::table('onboarding_tasks')->where('onboarding_id', $onboardingId)->where('code', 'pan')->value('status'));

        $this->actingAs($company->hr)->post($path, [
            'status' => 'under_review',
            'note' => 'Checking the document.',
            'confirm' => 'REVIEW',
        ])->assertRedirect();

        $reviewed = DB::table('employee_documents')->where('id', $document->id)->first();
        $this->assertSame('under_review', $reviewed->review_status);
        $this->assertSame($company->hr->id, (int) $reviewed->reviewed_by);
        $this->assertNotNull($reviewed->reviewed_at);
        $this->assertSame('Checking the document.', $reviewed->review_note);
        $this->assertSame($document->file_path, $reviewed->file_path);
        $this->assertSame($document->category, $reviewed->category);
        $this->assertSame($document->employee_id, $reviewed->employee_id);
        $this->assertSame($events + 1, DB::table('employee_record_events')->count());
        $this->assertSame('document_reviewed', DB::table('employee_record_events')->orderByDesc('id')->value('action'));
        $this->assertNull(DB::table('employee_record_events')->orderByDesc('id')->value('note'));
        $this->assertSame('pan', DB::table('employee_record_events')->orderByDesc('id')->value('subject'));
        $this->assertSame($company->hr->id, (int) DB::table('employee_record_events')->orderByDesc('id')->value('actor_id'));
        $this->assertSame('in_progress', DB::table('onboarding_tasks')->where('onboarding_id', $onboardingId)->where('code', 'pan')->value('status'));
        $this->assertSame($description, DB::table('onboarding_tasks')->where('title', 'Issue laptop and access')->value('description'));
        $this->assertSame('pending', DB::table('onboarding_tasks')->where('title', 'Issue laptop and access')->value('status'));
        $this->assertSame(0, DB::table('employee_record_events')->where('action', 'historical_decision')->count());

        $this->actingAs($company->hr)->post($path, [
            'status' => 'verified',
            'confirm' => 'VERIFY',
        ])->assertRedirect();
        $this->assertSame(2, DB::table('employee_record_events')->where('action', 'document_reviewed')->where('subject', 'pan')->count());
        $this->assertSame('completed', DB::table('onboarding_tasks')->where('onboarding_id', $onboardingId)->where('code', 'pan')->value('status'));
        $this->assertSame('pending', DB::table('onboarding_tasks')->where('onboarding_id', $onboardingId)->where('code', 'aadhaar')->value('status'));

        $this->actingAs($company->lead)->post($path, [
            'status' => 'rejected',
            'note' => 'A team lead cannot review this.',
            'confirm' => 'REJECT',
        ])->assertForbidden();
        $this->actingAs($other->hr)->post($path, [
            'status' => 'rejected',
            'note' => 'Another company cannot review this.',
            'confirm' => 'REJECT',
        ])->assertNotFound();
        $this->assertSame('verified', DB::table('employee_documents')->where('id', $document->id)->value('review_status'));
        $this->assertSame(2, DB::table('employee_record_events')->where('action', 'document_reviewed')->count());
    }

    public function test_onboarding_reports_not_ready_when_task_codes_are_unavailable(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $employee = Employee::query()->findOrFail($company->reportEmployeeId);
        Schema::table('onboarding_tasks', function (Blueprint $table): void {
            $table->dropUnique(['onboarding_id', 'code']);
        });
        Schema::table('onboarding_tasks', function (Blueprint $table): void {
            $table->dropColumn('code');
        });

        $presented = app(EmployeeOnboarding::class)->present($company->hr, $employee);
        $this->assertFalse($presented['ready']);
        app(EmployeeOnboarding::class)->open($company->hr, $employee);
        $this->assertSame(0, DB::table('onboardings')->where('employee_id', $employee->id)->count());
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
}
