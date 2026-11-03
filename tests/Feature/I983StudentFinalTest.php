<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CompanyFixture;
use Tests\Support\I983StudentSignature;
use Tests\TestCase;

class I983StudentFinalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->isolatePrivateDisk();
    }

    protected function tearDown(): void
    {
        foreach (['i983_documents' => 'file_path', 'i983_signatories' => 'image_path', 'i983_certifications' => 'signature_path', 'i983_student_certifications' => 'signature_path'] as $table => $column) {
            if (DB::getSchemaBuilder()->hasTable($table)) {
                foreach (DB::table($table)->pluck($column) as $path) {
                    if (is_string($path) && $path !== '') {
                        Storage::disk('local')->delete($path);
                    }
                }
            }
        }

        parent::tearDown();
    }

    public function test_a_student_previews_and_downloads_only_the_stored_final_pdf(): void
    {
        Mail::fake();
        $this->withoutVite();
        $official = storage_path('app/private/i983/official-i983.pdf');
        $officialHash = hash_file('sha256', $official);
        $company = CompanyFixture::make('Acme People', 'acme-i983-student-final');
        $other = CompanyFixture::make('Other Company', 'other-i983-student-final');
        $owner = $this->owner($company);
        $otherOwner = $this->owner($other);
        $users = DB::table('users')->count();

        $first = $this->finalizeCase($company, $owner, 'Riley', 'Quinn', 'riley.final@example.test');
        $second = $this->finalizeCase($other, $otherOwner, 'Casey', 'Nguyen', 'casey.final@example.test');
        Auth::logout();

        $stored = Storage::disk('local')->get($first['path']);
        $otherStored = Storage::disk('local')->get($second['path']);
        $documents = DB::table('i983_documents')->count();

        $this->assertGuest();
        $this->get($first['link'])->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('i983/submit')
            ->where('form.status', 'finalized')
            ->where('form.reference', $first['reference'])
            ->where('form.training.studentName', 'Quinn, Riley')
            ->where('form.training.employerName', 'Acme People LLC')
            ->where('form.finalizedAt', now()->format('F j, Y'))
            ->where('form.locked', true)
            ->missing('form.sha256')
            ->missing('form.file_path')
        );

        $preview = $this->get($first['link'].'/final');
        $preview->assertOk();
        $preview->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('inline; filename="I983_Riley_Quinn_Final_Signed.pdf"', (string) $preview->headers->get('content-disposition'));
        $this->assertStringContainsString('private', (string) $preview->headers->get('cache-control'));
        $this->assertStringContainsString('no-store', (string) $preview->headers->get('cache-control'));
        $this->assertSame($stored, $preview->getContent());
        $this->assertSame($first['sha256'], hash('sha256', $preview->getContent()));
        $this->assertStringNotContainsString($first['path'], $preview->headers->get('content-disposition'));

        $download = $this->get($first['link'].'/final/download');
        $again = $this->get($first['link'].'/final/download');
        $download->assertOk();
        $again->assertOk();
        $this->assertStringContainsString('attachment; filename="I983_Riley_Quinn_Final_Signed.pdf"', (string) $download->headers->get('content-disposition'));
        $this->assertSame($stored, $download->getContent());
        $this->assertSame($stored, $again->getContent());
        $this->assertSame($first['sha256'], hash('sha256', $again->getContent()));
        $this->assertSame($documents, DB::table('i983_documents')->count());
        $this->assertSame($stored, Storage::disk('local')->get($first['path']));

        $this->post($first['link'], ['goals' => 'Changed by the student', 'surname' => 'Changed'])->assertSessionHasErrors('form');
        $this->assertSame('Complete one reviewed feature and discuss the result with the hiring manager.', DB::table('i983_training_plans')->where('case_id', $first['caseId'])->value('goals'));
        $this->assertSame('Quinn', DB::table('i983_students')->where('case_id', $first['caseId'])->value('surname'));
        $this->assertSame($first['sha256'], DB::table('i983_documents')->where('id', $first['documentId'])->value('sha256'));
        $this->post($first['link'].'/final', ['goals' => 'Changed'])->assertStatus(405);
        $this->assertSame($stored, Storage::disk('local')->get($first['path']));

        $this->get('/i983/s/'.str_repeat('ab', 32))->assertNotFound();
        $this->get('/i983/s/'.str_repeat('ab', 32).'/final')->assertNotFound();
        $this->get('/i983/s/'.str_repeat('cd', 32).'/final/download')->assertNotFound();
        $this->get($first['link'].'/final/'.$first['documentId'])->assertNotFound();
        $this->get('/i983/'.$first['caseId'].'/documents/'.$first['workingId'].'/download')->assertRedirect('/login');
        $this->get('/i983/'.$second['caseId'].'/documents/'.$second['documentId'].'/preview')->assertRedirect('/login');

        $cross = $this->get($second['link'].'/final/download');
        $cross->assertOk();
        $this->assertSame($otherStored, $cross->getContent());
        $this->assertNotSame($stored, $cross->getContent());
        $this->assertStringContainsString('attachment; filename="I983_Casey_Nguyen_Final_Signed.pdf"', (string) $cross->headers->get('content-disposition'));

        DB::table('i983_links')->where('case_id', $first['caseId'])->update(['expires_at' => now()->subMinute()]);
        $this->get($first['link'])->assertNotFound();
        $this->get($first['link'].'/final')->assertNotFound();
        $this->get($first['link'].'/final/download')->assertNotFound();
        DB::table('i983_links')->where('case_id', $first['caseId'])->update(['expires_at' => now()->addDays(14), 'revoked_at' => now()]);
        $this->get($first['link'].'/final/download')->assertNotFound();
        $this->assertSame($stored, Storage::disk('local')->get($first['path']));
        $this->assertSame($otherStored, Storage::disk('local')->get($second['path']));
        $this->assertSame($users, DB::table('users')->count());
        $this->assertSame($officialHash, hash_file('sha256', $official));
        Mail::assertNothingSent();
    }

    public function test_a_student_link_has_no_final_pdf_before_the_case_is_finalized(): void
    {
        Mail::fake();
        $company = CompanyFixture::make('Acme People', 'acme-i983-student-open');
        $created = $this->actingAs($company->hr)->post('/i983', [
            'candidate_name' => 'Riley Quinn',
            'candidate_email' => 'riley.open@example.test',
        ]);
        $link = (string) session('i983_link');
        $caseId = (int) DB::table('i983_cases')->where('tenant_id', $company->id)->value('id');
        $this->post($link, $this->student('Riley', 'Quinn', 'riley.open@example.test', true))->assertRedirect();
        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/employer', $this->employer())->assertRedirect();
        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/training', $this->training('Quinn, Riley'))->assertRedirect();
        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/documents/working')->assertRedirect();
        $working = DB::table('i983_documents')->where('case_id', $caseId)->where('kind', 'hr_working')->first();
        Auth::logout();

        $this->get($link)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('form.status', 'student_submitted')
            ->where('form.finalizedAt', '')
        );
        $this->get($link.'/final')->assertNotFound();
        $this->get($link.'/final/download')->assertNotFound();
        $this->get('/i983/'.$caseId.'/documents/'.$working->id.'/download')->assertRedirect('/login');
        $this->assertSame(0, DB::table('i983_documents')->where('case_id', $caseId)->where('kind', 'final_signed')->count());
        Mail::assertNothingSent();
    }

    /**
     * @return array{link: string, caseId: int, reference: string, path: string, sha256: string, documentId: int, workingId: int}
     */
    private function finalizeCase(CompanyFixture $company, User $owner, string $given, string $surname, string $email): array
    {
        $this->actingAs($company->hr)->post('/i983', [
            'candidate_name' => $given.' '.$surname,
            'candidate_email' => $email,
        ])->assertRedirect();
        $link = (string) session('i983_link');
        $caseId = (int) DB::table('i983_cases')->where('tenant_id', $company->id)->where('candidate_email', $email)->value('id');
        $this->post($link, $this->student($given, $surname, $email, true))->assertRedirect();
        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/employer', $this->employer())->assertRedirect();
        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/training', $this->training($surname.', '.$given))->assertRedirect();
        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/documents/working')->assertRedirect();
        $this->actingAs($company->hr)->post('/i983/signature', [
            'signatory_name' => 'Priya Shah',
            'signatory_title' => 'HR Manager',
            'signature' => UploadedFile::fake()->image('signature.jpg', 240, 60),
        ])->assertRedirect();
        $this->actingAs($owner)->post('/i983/'.$caseId.'/signature', ['confirm' => '1'])->assertRedirect();
        $this->actingAs($owner)->post('/i983/'.$caseId.'/finalize', ['confirm' => '1'])->assertRedirect();
        $final = DB::table('i983_documents')->where('case_id', $caseId)->where('kind', 'final_signed')->first();
        $working = DB::table('i983_documents')->where('case_id', $caseId)->where('kind', 'hr_working')->first();

        return [
            'link' => $link,
            'caseId' => $caseId,
            'reference' => (string) DB::table('i983_cases')->where('id', $caseId)->value('reference'),
            'path' => (string) $final->file_path,
            'sha256' => (string) $final->sha256,
            'documentId' => (int) $final->id,
            'workingId' => (int) $working->id,
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

    /**
     * @return array<string, mixed>
     */
    private function student(string $given, string $surname, string $email, bool $submit): array
    {
        return [
            'surname' => $surname,
            'given_name' => $given,
            'middle_name' => '',
            'email' => $email,
            'school_recommending' => 'Example State University',
            'school_degree' => 'Example State University',
            'sevis_school_code' => 'DET214F00001000',
            'dso_name' => 'Avery Cole',
            'dso_email' => 'dso@ex.edu',
            'dso_phone' => '248-555-0199',
            'sevis_id' => 'N0000000001',
            'opt_start' => '2026-04-06',
            'opt_end' => '2028-04-05',
            'major_cip' => 'Computer Science 11.0701',
            'degree_level' => 'Master',
            'degree_awarded_on' => '2026-05-01',
            'prior_degree' => 'no',
            'ead' => 'IOE0000000001',
            'student_role' => 'Data analyst trainee',
            'goals' => 'Complete one reviewed feature and discuss the result with the hiring manager.',
            'submit' => $submit ? 1 : 0,
            ...($submit ? I983StudentSignature::fields() : []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function employer(): array
    {
        return [
            'legal_name' => 'Acme People LLC',
            'street' => '1 Main Street',
            'suite' => '200',
            'website' => 'https://acme.test',
            'city' => 'Farmington',
            'state' => 'MI',
            'zip' => '48335',
            'ein' => '98-7654321',
            'employee_count' => 12,
            'naics' => '541511',
            'opt_hours' => 20,
            'salary' => '72000',
            'salary_frequency' => 'year',
            'other_compensation' => 'None',
            'start_date' => '2026-04-06',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function training(string $studentName): array
    {
        return [
            'student_name' => $studentName,
            'employer_name' => 'Acme People LLC',
            'site_name' => 'Acme Remote',
            'site_address' => '100 Sample Street, Farmington, MI 48335',
            'official_name' => 'Priya Shah',
            'official_title' => 'HR Manager',
            'official_email' => 'priya@acme.test',
            'official_phone' => '248-555-0100',
            'student_role' => 'Data analyst trainee',
            'goals' => 'Complete one reviewed feature and discuss the result with the hiring manager.',
            'oversight' => 'Weekly check-in with the hiring manager.',
            'measures' => 'Reviewed project notes each month.',
            'remarks' => 'Sample remarks for the training plan.',
        ];
    }
}
