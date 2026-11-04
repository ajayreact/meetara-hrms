<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CompanyFixture;
use Tests\Support\I983StudentSignature;
use Tests\TestCase;

class I983DraftPreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->isolatePrivateDisk();
    }

    protected function tearDown(): void
    {
        foreach (['i983_documents' => 'file_path', 'i983_student_certifications' => 'signature_path', 'i983_signatories' => 'image_path'] as $table => $column) {
            if (DB::getSchemaBuilder()->hasTable($table) && DB::getSchemaBuilder()->hasColumn($table, $column)) {
                foreach (DB::table($table)->pluck($column) as $path) {
                    if (is_string($path) && $path !== '') {
                        Storage::disk('local')->delete($path);
                    }
                }
            }
        }

        parent::tearDown();
    }

    public function test_an_unsubmitted_case_has_no_official_pdf_and_the_draft_is_not_stored(): void
    {
        Mail::fake();
        $official = storage_path('app/private/i983/official-i983.pdf');
        $officialHash = hash_file('sha256', $official);
        $company = CompanyFixture::make('Veltrix Digital Solutions', 'i983-draft');
        $owner = $this->owner($company);
        $created = $this->actingAs($owner)->post('/i983', [
            'candidate_name' => 'Riley Quinn',
            'candidate_email' => 'riley.draft@example.test',
        ]);
        $caseId = (int) DB::table('i983_cases')->where('tenant_id', $company->id)->value('id');

        $this->actingAs($owner)->get('/i983/'.$caseId)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('case.submittedAt', '')
            ->where('documents', [])
            ->where('studentCertification.latest', null)
            ->where('canPreviewDraft', true)
        );
        $this->actingAs($company->hr)->get('/i983/'.$caseId)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('canPreviewDraft', true)
            ->where('canSign', true)
        );
        $this->actingAs($owner)->post('/i983/'.$caseId.'/documents/student')->assertSessionHasErrors('form');
        $this->actingAs($owner)->get('/i983/'.$caseId.'/documents/1/preview')->assertNotFound();
        $this->actingAs($owner)->post('/i983/'.$caseId.'/employer', [
            'legal_name' => 'Veltrix Draft LLC',
            'street' => '1 Main Street',
            'city' => 'Farmington',
            'state' => 'MI',
            'zip' => '48335',
        ])->assertRedirect();
        $this->actingAs($owner)->post('/i983/signature', [
            'signatory_name' => 'Morgan Hale',
            'signatory_title' => 'Chief Signer',
            'signature' => UploadedFile::fake()->image('employer-signature.png', 240, 60),
        ])->assertRedirect();

        $draft = $this->actingAs($owner)->get('/i983/'.$caseId.'/draft');
        $draft->assertOk();
        $bytes = $draft->getContent();
        $this->assertStringStartsWith('%PDF', $bytes);
        $this->assertSame(5, preg_match_all('/\/Type\s*\/Page(?!s)/', $bytes));
        $this->assertStringContainsString('(DRAFT - NOT FOR SUBMISSION)', $bytes);
        $this->assertStringContainsString('(Veltrix Draft LLC)', $bytes);
        $this->assertStringNotContainsString('320.00 97.80 Td (Morgan Hale, Chief Signer)', $bytes);
        $this->assertStringNotContainsString('144.00 173.90 Td', $bytes);
        $this->assertStringContainsString('inline; filename="I983_Riley_Quinn_Draft.pdf"', (string) $draft->headers->get('content-disposition'));
        $this->assertSame(0, DB::table('i983_documents')->where('case_id', $caseId)->count());
        $this->assertSame(0, DB::table('i983_student_certifications')->where('case_id', $caseId)->count());
        $this->assertNull(DB::table('i983_cases')->where('id', $caseId)->value('student_submitted_at'));
        $hrDraft = $this->actingAs($company->hr)->get('/i983/'.$caseId.'/draft');
        $hrDraft->assertOk();
        $this->assertStringContainsString('(DRAFT - NOT FOR SUBMISSION)', $hrDraft->getContent());
        $this->assertSame(0, DB::table('i983_documents')->where('case_id', $caseId)->count());
        $this->actingAs($company->lead)->get('/i983/'.$caseId.'/draft')->assertForbidden();
        $this->actingAs($company->employee)->get('/i983/'.$caseId.'/draft')->assertForbidden();
        auth()->logout();
        $this->get('/i983/'.$caseId.'/draft')->assertRedirect('/login');
        $this->assertSame($officialHash, hash_file('sha256', $official));
        $this->assertNotNull($created);
        Mail::assertNothingSent();
    }

    public function test_the_stored_student_pdf_can_be_previewed_without_replacing_earlier_versions(): void
    {
        Mail::fake();
        $company = CompanyFixture::make('Veltrix Digital Solutions', 'i983-official');
        $other = CompanyFixture::make('Other Company', 'i983-official-other');
        $owner = $this->owner($company);
        $this->actingAs($owner)->post('/i983', [
            'candidate_name' => 'Riley Quinn',
            'candidate_email' => 'riley.official@example.test',
        ])->assertRedirect();
        $link = (string) session('i983_link');
        $caseId = (int) DB::table('i983_cases')->where('tenant_id', $company->id)->value('id');

        $this->post($link, $this->student([], true))->assertRedirect();
        $first = DB::table('i983_documents')->where('case_id', $caseId)->where('kind', 'student_snapshot')->first();
        $this->assertNotNull($first);
        $this->assertSame(1, (int) $first->version);
        $stored = Storage::disk('local')->get($first->file_path);
        $this->assertStringStartsWith('%PDF', $stored);
        $this->assertStringNotContainsString('(DRAFT - NOT FOR SUBMISSION)', $stored);
        $this->assertStringContainsString('144.00 173.90 Td (Quinn, Riley)', $stored);
        $this->assertFalse(is_file(public_path((string) $first->file_path)));

        $preview = $this->actingAs($owner)->get('/i983/'.$caseId.'/documents/'.$first->id.'/preview');
        $hrPreview = $this->actingAs($company->hr)->get('/i983/'.$caseId.'/documents/'.$first->id.'/preview');
        $download = $this->actingAs($company->hr)->get('/i983/'.$caseId.'/documents/'.$first->id.'/download');
        $hrDraft = $this->actingAs($company->hr)->get('/i983/'.$caseId.'/draft');
        $hrDraft->assertOk();
        $this->assertStringContainsString('(DRAFT - NOT FOR SUBMISSION)', $hrDraft->getContent());
        $this->assertStringNotContainsString('(DRAFT - NOT FOR SUBMISSION)', $stored);
        $this->assertSame(1, DB::table('i983_documents')->where('case_id', $caseId)->count());
        $preview->assertOk();
        $hrPreview->assertOk();
        $download->assertOk();
        $this->assertSame($stored, $preview->getContent());
        $this->assertSame($stored, $hrPreview->getContent());
        $this->assertSame($stored, $download->getContent());
        $this->assertStringContainsString('inline; filename="I983_Riley_Quinn_Student_Submitted_v1.pdf"', (string) $preview->headers->get('content-disposition'));
        $this->assertStringContainsString('attachment; filename="I983_Riley_Quinn_Student_Submitted_v1.pdf"', (string) $download->headers->get('content-disposition'));

        $this->actingAs($owner)->post('/i983/'.$caseId.'/documents/student')->assertRedirect();
        $this->assertSame($stored, Storage::disk('local')->get($first->file_path));
        $this->assertSame(2, DB::table('i983_documents')->where('case_id', $caseId)->where('kind', 'student_snapshot')->count());
        $second = DB::table('i983_documents')->where('case_id', $caseId)->where('kind', 'student_snapshot')->where('version', 2)->first();
        $this->assertNotSame($first->file_path, $second->file_path);
        $this->assertNotSame($first->id, $second->id);

        $this->actingAs($company->lead)->get('/i983/'.$caseId.'/documents/'.$first->id.'/preview')->assertForbidden();
        $this->actingAs($company->employee)->get('/i983/'.$caseId.'/documents/'.$first->id.'/download')->assertForbidden();
        $this->actingAs($other->hr)->get('/i983/'.$caseId.'/documents/'.$first->id.'/preview')->assertNotFound();
        $this->actingAs($other->hr)->get('/i983/'.$caseId.'/draft')->assertNotFound();
        $this->actingAs($this->owner($other))->get('/i983/'.$caseId.'/draft')->assertNotFound();
        $this->get($link)->assertOk();
        Mail::assertNothingSent();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function student(array $overrides, bool $submit): array
    {
        return [
            'surname' => 'Quinn',
            'given_name' => 'Riley',
            'middle_name' => '',
            'email' => 'riley.official@example.test',
            'school_recommending' => 'Example State University',
            'school_degree' => 'Example State University',
            'sevis_school_code' => 'DET214F00001000',
            'dso_name' => 'Avery Cole',
            'dso_email' => 'dso@ex.edu',
            'dso_phone' => '+1 (248) 555-0199',
            'sevis_id' => 'N0000000001',
            'opt_start' => '2026-04-06',
            'opt_end' => '2028-04-05',
            'major_cip' => 'Computer Science 11.0701',
            'degree_level' => 'Master',
            'degree_awarded_on' => '2026-05-01',
            'prior_degree' => 'no',
            'ead' => 'IOE0000000001',
            'goals' => 'Complete one reviewed feature.',
            'submit' => $submit ? 1 : 0,
            ...($submit ? I983StudentSignature::fields() : []),
            ...$overrides,
        ];
    }

    private function owner(CompanyFixture $company): User
    {
        $roleId = (int) DB::table('roles')->where('tenant_id', $company->id)->where('name', 'Owner')->value('id');
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
