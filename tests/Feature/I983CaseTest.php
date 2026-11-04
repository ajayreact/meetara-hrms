<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CompanyFixture;
use Tests\Support\I983StudentSignature;
use Tests\TestCase;

class I983CaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->isolatePrivateDisk();
    }

    protected function tearDown(): void
    {
        foreach (['i983_documents' => 'file_path', 'i983_student_certifications' => 'signature_path'] as $table => $column) {
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

    public function test_hr_creates_a_case_and_the_student_can_resume_without_an_employee_account(): void
    {
        $this->withoutVite();
        Mail::fake();
        $company = CompanyFixture::make('Acme People', 'acme-i983');
        $other = CompanyFixture::make('Other Company', 'other-i983');
        $users = DB::table('users')->count();
        $offers = DB::table('usa_offers')->count();
        DB::table('usa_offer_profiles')->insert([
            'tenant_id' => $company->id,
            'company_name' => 'Acme People',
            'address_line' => '1 Main Street',
            'country_line' => 'United States',
            'website' => 'https://acme.test',
            'federal_id' => '12-3456789',
            'template_version' => 'usa-veltrix-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $created = $this->actingAs($company->hr)->post('/i983', [
            'candidate_name' => 'Riley Quinn',
            'candidate_email' => 'riley.quinn@example.test',
        ]);
        $created->assertRedirect();
        $created->assertSessionHas('i983_link');
        $link = (string) session('i983_link');
        $token = substr($link, -64);
        $caseId = (int) DB::table('i983_cases')->where('tenant_id', $company->id)->value('id');

        $this->assertSame(64, strlen($token));
        $this->assertSame(hash('sha256', $token), DB::table('i983_links')->where('case_id', $caseId)->value('token_hash'));
        $this->assertTrue(now()->addDays(13)->lt(DB::table('i983_links')->where('case_id', $caseId)->value('expires_at')));
        $this->assertSame($users, DB::table('users')->count());
        $this->assertSame($offers, DB::table('usa_offers')->count());
        $this->actingAs($company->hr)->get('/i983/'.$caseId)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('i983/show')
            ->where('employer.legalName', 'Acme People')
            ->where('employer.ein', '12-3456789')
            ->where('employer.city', '')
            ->where('case.reference', fn (string $reference) => str_starts_with($reference, 'I983-'))
        );

        $this->get($link)->assertOk()->assertInertia(fn (Assert $page) => $page->component('i983/submit')->where('form.locked', false));
        $this->post($link, $this->student(['surname' => 'Quinn', 'sevis_id' => 'N0000000001'], false))->assertRedirect();
        $this->get($link)->assertInertia(fn (Assert $page) => $page->where('form.student.sevisId', 'N0000000001')->where('form.student.surname', 'Quinn'));
        $this->assertNotSame('N0000000001', DB::table('i983_students')->where('case_id', $caseId)->value('sevis_id_ciphertext'));
        $this->post($link, $this->student(['given_name' => ''], true))->assertSessionHasErrors('given_name');
        $this->assertNull(DB::table('i983_cases')->where('id', $caseId)->value('student_submitted_at'));

        $this->post($link, $this->student([], true))->assertRedirect();
        $this->assertSame('student_submitted', DB::table('i983_cases')->where('id', $caseId)->value('status'));
        $this->post($link, $this->student(['surname' => 'Changed', 'goals' => 'Learn production data pipelines.'], false))->assertSessionHasErrors('surname');
        $this->assertSame('Quinn', DB::table('i983_students')->where('case_id', $caseId)->value('surname'));
        $this->assertNull(DB::table('i983_training_plans')->where('case_id', $caseId)->value('goals'));
        $this->post($link, $this->student(['goals' => 'Learn production data pipelines.'], false))->assertRedirect();
        $this->assertSame('Learn production data pipelines.', DB::table('i983_training_plans')->where('case_id', $caseId)->value('goals'));
        $this->assertSame('student', DB::table('i983_field_revisions')->where('case_id', $caseId)->where('field_name', 'goals')->value('actor_type'));
        $this->assertStringNotContainsString('N0000000001', json_encode(DB::table('i983_events')->get()));
        $this->assertStringNotContainsString($token, json_encode(DB::table('i983_events')->get()));

        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/employer/finalize', [
            'legal_name' => 'Acme People',
            'street' => '1 Main Street',
        ])->assertSessionHasErrors('employer');
        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/employer', [
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
        ])->assertRedirect();
        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/employer/finalize', [
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
        ])->assertRedirect();
        $this->assertNotNull(DB::table('i983_employers')->where('case_id', $caseId)->value('finalized_at'));
        $this->assertSame('Acme People LLC', DB::table('i983_employers')->where('case_id', $caseId)->value('legal_name'));

        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/training', [
            'site_name' => 'Acme Remote',
            'site_address' => '1 Main Street, Farmington, MI 48335',
            'official_name' => 'Priya Shah',
            'official_title' => 'HR Manager',
            'official_email' => 'priya@acme.test',
            'official_phone' => '+1 (248) 555-0100',
            'oversight' => 'The manager reviews work each week.',
        ])->assertRedirect();
        $this->assertSame('hr', DB::table('i983_field_revisions')->where('case_id', $caseId)->where('field_name', 'oversight')->value('actor_type'));

        $this->actingAs($company->lead)->get('/i983')->assertForbidden();
        $this->actingAs($company->employee)->get('/i983/'.$caseId)->assertForbidden();
        $this->actingAs($this->accounts($company))->get('/i983/'.$caseId)->assertForbidden();
        $this->actingAs($other->hr)->get('/i983/'.$caseId)->assertNotFound();
        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/link/revoke')->assertRedirect();
        $this->get($link)->assertNotFound();
        $this->get('/i983/'.$caseId.'/pdf')->assertNotFound();
        Mail::assertNothingSent();
    }

    public function test_section_five_ownership_preserves_student_fields_and_goal_history(): void
    {
        $this->withoutVite();
        Mail::fake();
        $company = CompanyFixture::make('Acme People', 'acme-i983-own');
        $other = CompanyFixture::make('Other Company', 'other-i983-own');
        $official = storage_path('app/private/i983/official-i983.pdf');
        $officialHash = is_file($official) ? hash_file('sha256', $official) : null;

        $created = $this->actingAs($company->hr)->post('/i983', [
            'candidate_name' => 'Riley Quinn',
            'candidate_email' => 'riley.quinn@example.test',
        ]);
        $link = (string) session('i983_link');
        $caseId = (int) DB::table('i983_cases')->where('tenant_id', $company->id)->value('id');

        $this->post($link, $this->student([
            'student_name' => 'Riley Quinn',
            'oversight' => 'Student tried to replace oversight.',
            'goals' => 'Learn the review process.',
        ], false))->assertSessionHasErrors('oversight');
        $this->assertNull(DB::table('i983_students')->where('case_id', $caseId)->value('surname'));
        $this->assertSame('Riley Quinn', DB::table('i983_training_plans')->where('case_id', $caseId)->value('student_name'));

        $this->post($link, $this->student([
            'student_role' => 'Data analyst trainee',
            'goals' => 'Learn the review process.',
        ], true))->assertRedirect();
        $this->assertSame('Quinn, Riley', DB::table('i983_training_plans')->where('case_id', $caseId)->value('student_name'));

        $this->post($link, $this->student([
            'student_name' => 'Someone Else',
            'surname' => 'Changed',
            'student_role' => 'Changed by student',
            'site_name' => 'Student site',
            'goals' => 'Should not replace the submitted goal.',
        ], false))->assertSessionHasErrors(['student_name', 'surname', 'student_role', 'site_name']);
        $this->assertSame('Quinn', DB::table('i983_students')->where('case_id', $caseId)->value('surname'));
        $this->assertSame('Quinn, Riley', DB::table('i983_training_plans')->where('case_id', $caseId)->value('student_name'));
        $this->assertSame('Data analyst trainee', DB::table('i983_training_plans')->where('case_id', $caseId)->value('student_role'));
        $this->assertSame('Learn the review process.', DB::table('i983_training_plans')->where('case_id', $caseId)->value('goals'));
        $this->assertNull(DB::table('i983_training_plans')->where('case_id', $caseId)->value('site_name'));

        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/employer', [
            'legal_name' => 'Acme People LLC',
            'street' => '1 Main Street',
            'city' => 'Farmington',
            'state' => 'MI',
            'zip' => '48335',
            'ein' => '98-7654321',
            'employee_count' => 12,
            'naics' => '541511',
            'opt_hours' => 20,
            'salary' => '72000',
            'salary_frequency' => 'year',
            'start_date' => '2026-04-06',
        ])->assertRedirect();
        $this->assertSame('Acme People LLC', DB::table('i983_training_plans')->where('case_id', $caseId)->value('employer_name'));

        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/training', [
            'student_name' => 'Someone Else',
            'goals' => 'Learn the review process.',
        ])->assertSessionHasErrors(['student_name']);
        $this->assertSame('Quinn, Riley', DB::table('i983_training_plans')->where('case_id', $caseId)->value('student_name'));
        $this->assertSame('Data analyst trainee', DB::table('i983_training_plans')->where('case_id', $caseId)->value('student_role'));
        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/training', [
            'student_role' => 'Changed by HR',
        ])->assertRedirect();
        $this->assertSame('Changed by HR', DB::table('i983_training_plans')->where('case_id', $caseId)->value('student_role'));
        $roleRevision = DB::table('i983_field_revisions')->where('case_id', $caseId)->where('field_name', 'student_role')->orderByDesc('id')->first();
        $this->assertSame('Data analyst trainee', $roleRevision->previous_value);
        $this->assertSame('Changed by HR', $roleRevision->new_value);
        $this->assertSame('hr', $roleRevision->actor_type);
        $this->assertSame($company->hr->id, (int) $roleRevision->actor_id);

        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/training', [
            'site_address' => 'Michigan',
        ])->assertSessionHasErrors('site_address');
        $this->assertNull(DB::table('i983_training_plans')->where('case_id', $caseId)->value('site_address'));

        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/training', [
            'oversight' => 'Weekly review with the manager.',
            'measures' => 'A completed practice task.',
            'site_name' => 'Acme Remote',
            'official_name' => 'Priya Shah',
            'site_address' => '500 Training Plaza, Austin, TX 78701',
            'work_arrangement' => 'remote',
        ])->assertRedirect();

        $this->post($link, $this->student([
            'oversight' => 'Student tried to replace oversight.',
            'measures' => 'Student tried to replace measures.',
            'goals' => 'Learn the review process.',
        ], false))->assertSessionHasErrors(['oversight', 'measures']);
        $this->assertSame('Weekly review with the manager.', DB::table('i983_training_plans')->where('case_id', $caseId)->value('oversight'));
        $this->assertSame('A completed practice task.', DB::table('i983_training_plans')->where('case_id', $caseId)->value('measures'));

        $this->post($link, $this->student(['goals' => 'Document one reviewed feature.'], false))->assertRedirect();
        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/training', [
            'goals' => 'Document one reviewed feature and the STEM skill used.',
        ])->assertRedirect();
        $goalRevisions = DB::table('i983_field_revisions')->where('case_id', $caseId)->where('field_name', 'goals')->orderBy('id')->get();
        $this->assertTrue($goalRevisions->contains(fn (object $row): bool => $row->previous_value === 'Learn the review process.' && $row->new_value === 'Document one reviewed feature.' && $row->actor_type === 'student'));
        $this->assertSame('Document one reviewed feature.', $goalRevisions->last()->previous_value);
        $this->assertSame('Document one reviewed feature and the STEM skill used.', $goalRevisions->last()->new_value);
        $this->assertSame('hr', $goalRevisions->last()->actor_type);

        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/training', [
            'site_address' => '500 Training Plaza',
        ])->assertSessionHasErrors('site_address');
        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/training', [
            'site_address' => str_repeat('500 Very Long Training Plaza, ', 12).'Austin, TX 78701',
        ])->assertSessionHasErrors('site_address');
        $this->assertSame('500 Training Plaza, Austin, TX 78701', DB::table('i983_training_plans')->where('case_id', $caseId)->value('site_address'));

        $this->actingAs($company->hr)->post('/i983/'.$caseId.'/correction', [
            'reason' => 'The given name on Section 1 should be checked by the student.',
        ])->assertRedirect();
        $this->assertSame('correction_requested', DB::table('i983_cases')->where('id', $caseId)->value('status'));
        $this->post($link, $this->student(['given_name' => 'Rileigh'], true))->assertRedirect();
        $this->assertSame('Quinn, Rileigh', DB::table('i983_training_plans')->where('case_id', $caseId)->value('student_name'));
        $this->assertSame('Quinn, Riley', DB::table('i983_field_revisions')->where('case_id', $caseId)->where('field_name', 'student_name')->orderBy('id')->value('new_value'));
        $this->assertSame('resubmitted', DB::table('i983_cases')->where('id', $caseId)->value('status'));
        $this->assertSame(2, DB::table('i983_documents')->where('case_id', $caseId)->where('kind', 'student_snapshot')->count());
        $this->assertSame(0, DB::table('i983_documents')->where('case_id', $caseId)->where('kind', 'hr_working')->count());

        $this->actingAs($company->lead)->post('/i983/'.$caseId.'/training', ['goals' => 'No access'])->assertForbidden();
        $this->actingAs($company->employee)->get('/i983/'.$caseId)->assertForbidden();
        $this->actingAs($other->hr)->get('/i983/'.$caseId)->assertNotFound();
        $this->get('/i983/s/'.str_repeat('ab', 32))->assertNotFound();
        $this->actingAs($company->hr)->get('/i983/'.$caseId)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('training.studentName', 'Quinn, Rileigh')
            ->where('training.employerName', 'Acme People LLC')
            ->where('revisions', fn (mixed $revisions): bool => collect($revisions)->contains(
                fn (array $revision): bool => $revision['field'] === 'goals' && $revision['previous'] !== '' && $revision['next'] !== ''
            ))
        );

        if ($officialHash !== null) {
            $this->assertSame($officialHash, hash_file('sha256', $official));
            $this->assertSame('c1fffede432651cf1d6b5c9a0647d0aad96754b28ae9b40d5fd2d6d8ca0d7202', $officialHash);
        }

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
            'email' => 'riley.quinn@example.test',
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
            'goals' => '',
            'submit' => $submit ? 1 : 0,
            ...($submit ? I983StudentSignature::fields() : []),
            ...$overrides,
        ];
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
        $user = User::factory()->create();
        $user->forceFill([
            'tenant_id' => $company->id,
            'is_platform_admin' => false,
            'invite_accepted_at' => now(),
        ])->save();
        DB::table('model_has_roles')->insert([
            'role_id' => DB::table('roles')->where('tenant_id', $company->id)->where('name', 'Accounts')->value('id'),
            'model_type' => User::class,
            'model_id' => $user->id,
            'tenant_id' => $company->id,
        ]);

        return $user->refresh();
    }
}
