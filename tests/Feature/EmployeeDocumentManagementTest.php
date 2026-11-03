<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CompanyFixture;
use Tests\TestCase;

class EmployeeDocumentManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_uploads_reject_unsafe_files_and_other_employees(): void
    {
        Storage::fake('local');
        $company = CompanyFixture::make('Acme', 'docs');
        $employee = Employee::query()->findOrFail($company->reportEmployeeId);

        $this->actingAs($company->employee)->post('/my-documents', [
            'category' => 'pan',
            'reference' => 'ABCDE9999F',
            'description' => 'Identity card',
            'file' => UploadedFile::fake()->create('pan-card.pdf', 20, 'application/pdf'),
        ])->assertRedirect();

        $document = DB::table('employee_documents')->where('employee_id', $employee->id)->where('category', 'pan')->first();
        $this->assertNotNull($document);
        $this->assertSame('pending', $document->review_status);
        $this->assertStringStartsWith('employee-documents/'.$company->id.'/'.$employee->id.'/', (string) $document->file_path);
        $this->assertStringNotContainsString('9999', (string) $document->file_path);
        Storage::disk('local')->assertExists((string) $document->file_path);
        Storage::disk('public')->assertMissing((string) $document->file_path);
        $this->assertStringNotContainsString('9999', DB::table('notifications')->pluck('data')->implode(' '));

        $this->actingAs($company->employee)->post('/my-documents', [
            'category' => 'photograph',
            'file' => UploadedFile::fake()->image('photo.jpg'),
        ])->assertRedirect();
        $this->assertSame('pending', DB::table('employee_documents')->where('employee_id', $employee->id)->where('category', 'photograph')->value('review_status'));

        $this->actingAs($company->employee)->post('/my-documents', [
            'category' => 'resume',
            'file' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
        ])->assertSessionHasErrors('file');
        $this->actingAs($company->employee)->post('/my-documents', [
            'category' => 'resume',
            'file' => UploadedFile::fake()->create('run.php', 10, 'application/x-httpd-php'),
        ])->assertSessionHasErrors('file');
        $this->actingAs($company->employee)->post('/my-documents', [
            'category' => 'resume',
            'file' => UploadedFile::fake()->create('large.pdf', 5121, 'application/pdf'),
        ])->assertSessionHasErrors('file');

        $this->actingAs($company->employee)->post('/employees/'.$company->outsideEmployeeId.'/documents', [
            'category' => 'pan',
            'file' => UploadedFile::fake()->create('other.pdf', 20, 'application/pdf'),
        ])->assertNotFound();
        $this->actingAs($company->employee)->get('/dashboard')->assertOk();
    }

    public function test_verification_history_versions_and_checklist_percentages(): void
    {
        Storage::fake('local');
        $company = CompanyFixture::make('Acme', 'docs-review');
        $other = CompanyFixture::make('Other', 'docs-other');
        $owner = $this->owner($company);
        $accounts = $this->accounts($company);
        $employee = Employee::query()->findOrFail($company->reportEmployeeId);

        $this->actingAs($company->hr)->post('/documents/categories', [
            'categories' => [
                ['key' => 'pan', 'name' => 'PAN Card', 'required' => 1, 'active' => 1],
                ['key' => 'aadhaar', 'name' => 'Aadhaar Card', 'required' => 1, 'active' => 1],
                ['key' => 'photograph', 'name' => 'Photograph', 'required' => 0, 'active' => 1],
                ['key' => 'bank_proof', 'name' => 'Bank Proof', 'required' => 0, 'active' => 1],
            ],
        ])->assertRedirect();

        $this->actingAs($company->hr)->get('/documents')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('documents/index')
            ->where('summary.missing', 6)
            ->where('summary.pending', 0)
            ->where('checklists', fn ($rows) => collect($rows)->firstWhere('employeeId', $employee->id)['percentage'] === 0)
        );

        $this->actingAs($company->employee)->post('/my-documents', [
            'category' => 'pan',
            'file' => UploadedFile::fake()->create('pan.pdf', 20, 'application/pdf'),
        ])->assertRedirect();
        $first = DB::table('employee_documents')->where('employee_id', $employee->id)->where('category', 'pan')->first();
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $company->hr->id)->where('data->key', 'document-'.$first->id.'-uploaded')->count());

        $this->actingAs($company->employee)->post('/employees/'.$employee->id.'/documents/'.$first->id.'/review', [
            'status' => 'verified',
            'confirm' => 'VERIFY',
        ])->assertForbidden();
        $this->actingAs($company->lead)->post('/employees/'.$employee->id.'/documents/'.$first->id.'/review', [
            'status' => 'verified',
            'confirm' => 'VERIFY',
        ])->assertForbidden();
        $this->actingAs($other->hr)->post('/employees/'.$employee->id.'/documents/'.$first->id.'/review', [
            'status' => 'rejected',
            'note' => 'Outside company',
            'confirm' => 'REJECT',
        ])->assertNotFound();

        $this->actingAs($company->hr)->post('/employees/'.$employee->id.'/documents/'.$first->id.'/review', [
            'status' => 'rejected',
            'confirm' => 'REJECT',
        ])->assertSessionHasErrors('note');
        $this->actingAs($company->hr)->post('/documents/employees/'.$employee->id.'/items/'.$first->id.'/replacement', [
            'note' => 'Upload a clearer copy.',
        ])->assertRedirect();
        $this->actingAs($company->hr)->post('/documents/employees/'.$employee->id.'/items/'.$first->id.'/replacement', [
            'note' => 'Upload a clearer copy.',
        ])->assertRedirect();
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $company->employee->id)->where('data->key', 'document-'.$first->id.'-replacement')->count());
        $this->assertSame('rejected', DB::table('employee_documents')->where('id', $first->id)->value('review_status'));

        $this->actingAs($company->employee)->post('/my-documents', [
            'category' => 'pan',
            'file' => UploadedFile::fake()->create('pan-replacement.pdf', 20, 'application/pdf'),
        ])->assertRedirect();
        $second = DB::table('employee_documents')->where('employee_id', $employee->id)->where('category', 'pan')->orderByDesc('id')->first();
        $this->assertSame((int) $first->id, (int) $second->replaces_document_id);
        $this->assertNotSame($first->file_path, $second->file_path);
        Storage::disk('local')->assertExists((string) $first->file_path);
        Storage::disk('local')->assertExists((string) $second->file_path);
        $this->assertSame($company->hr->id, (int) DB::table('employee_documents')->where('id', $first->id)->value('reviewed_by'));

        $this->actingAs($company->hr)->post('/employees/'.$employee->id.'/documents/'.$second->id.'/review', [
            'status' => 'verified',
            'confirm' => 'VERIFY',
        ])->assertRedirect();
        $this->actingAs($company->hr)->post('/employees/'.$employee->id.'/documents/'.$second->id.'/review', [
            'status' => 'verified',
            'confirm' => 'VERIFY',
        ])->assertRedirect();
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $company->employee->id)->where('data->key', 'document-'.$second->id.'-verified')->count());

        $this->actingAs($company->hr)->get('/documents/employees/'.$employee->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('checklist.percentage', 50)
            ->where('checklist.verified', 1)
            ->where('checklist.missing', 1)
            ->where('checklist.pending', 0)
            ->where('checklist.rejected', 0)
        );
        $this->actingAs($owner)->get('/documents/employees/'.$employee->id.'/items/'.$second->id.'/preview')->assertOk();
        $this->actingAs($company->employee)->get('/employees/'.$employee->id.'/documents/'.$second->id)->assertOk();
        $this->actingAs($company->employee)->get('/employees/'.$company->outsideEmployeeId.'/documents/'.$second->id)->assertNotFound();
        $this->actingAs($company->hr)->get('/employees/'.$employee->id.'/documents/'.$first->id)->assertOk();
        $this->actingAs($company->lead)->get('/documents')->assertForbidden();
        $this->actingAs($company->lead)->get('/employees/'.$employee->id.'/documents/'.$second->id)->assertForbidden();
        $this->actingAs($accounts)->get('/documents')->assertForbidden();
        $this->actingAs($accounts)->get('/employees/'.$employee->id.'/documents/'.$second->id)->assertNotFound();
        $this->actingAs($other->hr)->get('/documents/employees/'.$employee->id)->assertNotFound();
    }

    private function owner(CompanyFixture $company): User
    {
        return $this->member($company, 'Owner');
    }

    private function accounts(CompanyFixture $company): User
    {
        DB::table('roles')->insert([
            'tenant_id' => $company->id,
            'name' => 'Accounts',
            'guard_name' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
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
