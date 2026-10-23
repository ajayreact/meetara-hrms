<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\CandidateForm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CompanyFixture;
use Tests\TestCase;

class CandidateOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_only_the_company_admin_can_enable_a_stable_public_link(): void
    {
        config(['app.url' => 'https://hr.example.test']);
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $other = CompanyFixture::make('Deep Data Inc', 'deep-data');
        $owner = $this->owner($company);
        $otherOwner = $this->owner($other);

        $this->actingAs($company->hr)->put('/company/settings/candidate-form', ['enabled' => true, 'confirm_disable' => false])->assertForbidden();
        $this->actingAs($company->employee)->put('/company/settings/candidate-form', ['enabled' => true, 'confirm_disable' => false])->assertForbidden();
        $this->actingAs($this->owner($company, 'Accounts'))->put('/company/settings/candidate-form', ['enabled' => true, 'confirm_disable' => false])->assertForbidden();

        $this->actingAs($owner)->put('/company/settings/candidate-form', [
            'enabled' => true,
            'confirm_disable' => false,
        ])->assertRedirect();

        $token = (string) DB::table('candidate_onboarding_forms')->where('tenant_id', $company->id)->value('public_token');
        $this->assertSame(64, strlen($token));

        $this->actingAs($owner)->withServerVariables(['HTTP_HOST' => 'evil.test'])->get('/company/settings')
            ->assertInertia(fn (Assert $page) => $page
                ->where('candidateForm.enabled', true)
                ->where('candidateForm.publicUrl', 'https://hr.example.test/apply/'.$token));

        $this->actingAs($owner)->put('/company/settings/candidate-form', [
            'enabled' => false,
            'confirm_disable' => false,
        ])->assertSessionHasErrors('confirm_disable');
        $this->assertSame(1, (int) DB::table('candidate_onboarding_forms')->where('tenant_id', $company->id)->value('enabled'));

        $this->actingAs($owner)->put('/company/settings/candidate-form', [
            'enabled' => false,
            'confirm_disable' => true,
        ])->assertRedirect();
        $this->actingAs($owner)->put('/company/settings/candidate-form', [
            'enabled' => true,
            'confirm_disable' => false,
        ])->assertRedirect();

        $this->assertSame($token, DB::table('candidate_onboarding_forms')->where('tenant_id', $company->id)->value('public_token'));
        $this->assertSame(0, DB::table('candidate_onboarding_forms')->where('tenant_id', $other->id)->count());
        $this->actingAs($otherOwner)->put('/company/settings/candidate-form', [
            'enabled' => true,
            'confirm_disable' => false,
        ])->assertRedirect();
        $this->assertNotSame($token, DB::table('candidate_onboarding_forms')->where('tenant_id', $other->id)->value('public_token'));
        $this->assertSame($token, DB::table('candidate_onboarding_forms')->where('tenant_id', $company->id)->value('public_token'));
    }

    public function test_a_guest_can_submit_only_while_the_form_is_enabled(): void
    {
        Storage::fake('local');
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $owner = $this->owner($company);
        $this->enable($owner);
        $this->app['auth']->forgetGuards();
        $token = (string) DB::table('candidate_onboarding_forms')->where('tenant_id', $company->id)->value('public_token');
        $logged = [];
        Log::listen(function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message.' '.json_encode($event->context);
        });

        $this->get('/apply/'.str_repeat('a', 64))->assertNotFound();
        $formPage = $this->get('/apply/'.$token)->assertOk()->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', (string) $formPage->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $formPage->headers->get('Cache-Control'));
        $formPage->assertInertia(fn (Assert $page) => $page->component('apply/form')->where('form.open', true)->where('uploadNotice', null)->missing('submission.operations'));
        $this->get('/apply/'.$token.'?notice=not-a-real-notice')->assertOk()->assertInertia(fn (Assert $page) => $page->where('uploadNotice', null));
        $this->get('/apply/'.$token)->assertDontSee('Payment received')->assertDontSee('Training agreement')->assertDontSee('Work day');

        $employees = DB::table('employees')->count();
        $runs = DB::table('payroll_runs')->count();
        $payments = DB::table('payroll_payments')->count();

        $this->post('/apply/'.$token, ['tenant_id' => 999, ...$this->fields(), ...$this->files()])->assertSessionHasErrors('tenant_id');
        $this->post('/apply/'.$token, [...$this->fields('internal@example.test'), ...$this->files(), 'status' => 'approved', 'payment_received' => true, 'amount_paid' => '10', 'work_day' => '2026-11-02'])->assertSessionHasErrors(['status', 'payment_received', 'amount_paid', 'work_day']);
        $this->assertSame(0, DB::table('candidate_submissions')->count());

        $incomplete = $this->fields();
        unset($incomplete['first_name']);
        $this->from('/apply/'.$token)->post('/apply/'.$token, [...$incomplete, 'ssn' => '123-45-6789'])->assertSessionHasErrors('first_name');
        $this->assertArrayNotHasKey('ssn', session()->get('_old_input', []));
        $this->assertArrayNotHasKey('bank_name', session()->get('_old_input', []));
        $this->assertArrayNotHasKey('account_number', session()->get('_old_input', []));
        $this->assertArrayNotHasKey('routing_number', session()->get('_old_input', []));
        $this->assertArrayNotHasKey('phone', session()->get('_old_input', []));
        $this->assertArrayNotHasKey('date_of_birth', session()->get('_old_input', []));
        $this->assertArrayNotHasKey('current_address', session()->get('_old_input', []));
        $this->assertArrayNotHasKey('i94_number', session()->get('_old_input', []));
        $this->assertArrayNotHasKey('ead_number', session()->get('_old_input', []));

        $invalidRouting = $this->fields();
        $invalidRouting['routing_number'] = '000000000';
        $this->post('/apply/'.$token, [...$invalidRouting, ...$this->files()])->assertSessionHasErrors('routing_number');
        $this->assertSame(0, DB::table('candidate_submissions')->count());

        $response = $this->post('/apply/'.$token, [...$this->fields(), ...$this->files()]);
        $response->assertRedirect();
        $reference = (string) session('candidate_reference');
        $this->assertMatchesRegularExpression('/^CN[A-F0-9]{10}$/', $reference);
        $this->get('/apply/'.$token.'/received')->assertOk()->assertSee($reference)->assertDontSee('123-45-6789')->assertDontSee('123456789')->assertDontSee('100 Example Street')->assertDontSee('1990-01-15');

        $row = DB::table('candidate_submissions')->where('reference', $reference)->first();
        $this->assertSame($company->id, (int) $row->tenant_id);
        $this->assertSame('pending_review', $row->status);
        $this->assertSame(CandidateForm::NOTICE_VERSION, $row->notice_version);
        $this->assertNotNull($row->consented_at);
        $this->assertSame('123456789', Crypt::decryptString((string) $row->ssn_ciphertext));
        $this->assertNotSame('123456789', $row->ssn_ciphertext);
        $this->assertSame('Example Bank', Crypt::decryptString((string) $row->bank_name_ciphertext));
        $this->assertSame('123456789012', Crypt::decryptString((string) $row->account_ciphertext));
        $this->assertSame('123456780', Crypt::decryptString((string) $row->routing_ciphertext));
        $this->assertNotSame('Example Bank', $row->bank_name_ciphertext);
        $this->assertNotSame('123456789012', $row->account_ciphertext);
        $this->assertNotSame('123456780', $row->routing_ciphertext);
        $this->assertSame('6789', $row->ssn_last4);
        $this->assertSame('+1 202 555 0100', Crypt::decryptString((string) $row->phone_ciphertext));
        $this->assertSame('1990-01-15', Crypt::decryptString((string) $row->date_of_birth_ciphertext));
        $this->assertSame('100 Example Street', Crypt::decryptString((string) $row->address_ciphertext));
        $this->assertSame('ABC123', Crypt::decryptString((string) $row->i94_ciphertext));
        $this->assertSame('EAD123', Crypt::decryptString((string) $row->ead_ciphertext));
        $this->assertNotSame('+1 202 555 0100', $row->phone_ciphertext);
        $this->assertNotSame('1990-01-15', $row->date_of_birth_ciphertext);
        $this->assertNotSame('100 Example Street', $row->address_ciphertext);
        $this->assertNotSame('ABC123', $row->i94_ciphertext);
        $this->assertNotSame('EAD123', $row->ead_ciphertext);
        $this->assertSame('0100', $row->phone_last4);
        $this->assertSame('C123', $row->i94_last4);
        $this->assertSame('D123', $row->ead_last4);
        $this->assertSame('passport.pdf', DB::table('candidate_submission_documents')->where('submission_id', $row->id)->where('category', 'passport')->value('original_name'));
        $this->assertMatchesRegularExpression('/^candidate-submissions\/\d+\/\d+\/passport-[0-9a-f-]+\.pdf$/', (string) DB::table('candidate_submission_documents')->where('submission_id', $row->id)->where('category', 'passport')->value('file_path'));
        $this->assertSame(2, DB::table('candidate_submission_documents')->where('submission_id', $row->id)->whereIn('category', ['ead_front', 'ead_back'])->count());
        $this->assertNull(DB::table('candidate_submission_documents')->where('submission_id', $row->id)->where('category', 'drivers_license')->first());
        $this->assertSame(0, DB::table('candidate_submission_documents')->where('file_path', 'like', '%..%')->count());
        $this->assertSame($employees, DB::table('employees')->count());
        $this->assertSame($runs, DB::table('payroll_runs')->count());
        $this->assertSame($payments, DB::table('payroll_payments')->count());
        $this->assertStringNotContainsString('123456789', implode("\n", $logged));
        $this->assertStringNotContainsString('123-45-6789', implode("\n", $logged));
        $this->assertStringNotContainsString('123456789012', implode("\n", $logged));
        $this->assertStringNotContainsString('123456780', implode("\n", $logged));
        $this->assertStringNotContainsString('Example Bank', implode("\n", $logged));
        $this->assertStringNotContainsString('100 Example Street', implode("\n", $logged));
        $this->assertStringNotContainsString('1990-01-15', implode("\n", $logged));
        $this->assertStringNotContainsString('+1 202 555 0100', implode("\n", $logged));
        $this->assertStringNotContainsString('ABC123', implode("\n", $logged));
        $this->assertStringNotContainsString('EAD123', implode("\n", $logged));

        DB::table('tenants')->where('id', $company->id)->update(['status' => 'inactive']);
        $this->post('/apply/'.$token, [...$this->fields('inactive-tenant@example.test'), ...$this->files()])
            ->assertSessionHasErrors(['form' => 'This form is not accepting submissions.']);
        $this->assertSame(1, DB::table('candidate_submissions')->count());
        DB::table('tenants')->where('id', $company->id)->update(['status' => 'active']);

        $this->post('/apply/'.$token, [...$this->fields(), ...$this->files()])
            ->assertSessionHasErrors(['form' => 'The form could not be submitted.']);
        $this->post('/apply/'.$token, [...$this->fields('second@example.test'), ...$this->files(), 'website' => 'https://spam.example'])
            ->assertSessionHasErrors(['form' => 'The form could not be submitted.']);
        $this->assertSame(1, DB::table('candidate_submissions')->count());

        $this->actingAs($owner)->put('/company/settings/candidate-form', ['enabled' => false, 'confirm_disable' => true])->assertRedirect();
        $this->get('/apply/'.$token)->assertInertia(fn (Assert $page) => $page->component('apply/unavailable'));
        $this->post('/apply/'.$token, [...$this->fields('other@example.test'), ...$this->files()])
            ->assertSessionHasErrors(['form' => 'This form is not accepting submissions.']);
        $this->assertSame(1, DB::table('candidate_submissions')->count());
        $this->actingAs($company->hr)->get('/candidates')->assertOk()->assertSee($reference);
    }

    public function test_invalid_files_are_rejected_and_not_left_behind(): void
    {
        Storage::fake('local');
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $this->enable($this->owner($company));
        $this->app['auth']->forgetGuards();
        $token = (string) DB::table('candidate_onboarding_forms')->where('tenant_id', $company->id)->value('public_token');
        $plain = tempnam(sys_get_temp_dir(), 'cand');
        file_put_contents((string) $plain, 'not a real document');
        $files = $this->files();
        $files['document_visa'] = new UploadedFile((string) $plain, 'visa.pdf', 'application/pdf', null, true);

        $this->post('/apply/'.$token, [...$this->fields(), ...$files])->assertSessionHasErrors('document_visa');
        $this->assertSame(0, DB::table('candidate_submissions')->count());
        $this->assertSame([], Storage::disk('local')->allFiles());

        $files = $this->files();
        $files['document_resume'] = UploadedFile::fake()->create('resume.pdf', 5121, 'application/pdf');
        $this->post('/apply/'.$token, [...$this->fields(), ...$files])->assertSessionHasErrors('document_resume');
        $this->assertSame([], Storage::disk('local')->allFiles());

        $plainUpload = tempnam(sys_get_temp_dir(), 'cand');
        file_put_contents((string) $plainUpload, 'plain upload bytes');
        $files = $this->files();
        $files['document_passport'] = new UploadedFile((string) $plainUpload, 'passport.pdf', 'application/pdf', UPLOAD_ERR_INI_SIZE, true);
        $this->post('/apply/'.$token, [...$this->fields(), ...$files])->assertSessionHasErrors([
            'document_passport' => 'Passport copy could not be accepted. Each document must be 5 MB or smaller. If this file is already under 5 MB, the server upload limit is lower than this form allows.',
        ]);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertStringNotContainsString('plain upload bytes', json_encode(session()->get('_old_input', [])));

        $files = $this->files();
        $files['document_passport'] = UploadedFile::fake()->create('shell.php', 10, 'application/x-php');
        $this->post('/apply/'.$token, [...$this->fields(), ...$files])->assertSessionHasErrors('document_passport');
        $this->assertSame([], Storage::disk('local')->allFiles());

        $fail = true;
        DB::listen(function ($query) use (&$fail): void {
            $sql = strtolower($query->sql);
            if ($fail && str_contains($sql, 'insert into') && str_contains($sql, 'candidate_submission_documents')) {
                $fail = false;
                throw new \RuntimeException('forced document failure');
            }
        });
        $this->post('/apply/'.$token, [...$this->fields(), ...$this->files()])->assertSessionHasErrors(['form' => 'The form could not be submitted.']);
        $this->assertSame(0, DB::table('candidate_submissions')->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_hr_review_stays_inside_the_tenant_and_does_not_hire_or_pay(): void
    {
        Storage::fake('local');
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $other = CompanyFixture::make('Deep Data Inc', 'deep-data');
        $owner = $this->owner($company);
        $this->enable($owner);
        $token = (string) DB::table('candidate_onboarding_forms')->where('tenant_id', $company->id)->value('public_token');
        $this->app['auth']->forgetGuards();
        $this->post('/apply/'.$token, [...$this->fields(), ...$this->files()])->assertRedirect();
        $submissionId = (int) DB::table('candidate_submissions')->value('id');
        $documentId = (int) DB::table('candidate_submission_documents')->where('category', 'passport')->value('id');
        $path = (string) DB::table('candidate_submission_documents')->where('id', $documentId)->value('file_path');
        $employees = DB::table('employees')->count();
        $payments = DB::table('payroll_payments')->count();
        $platform = User::factory()->create();
        $platform->forceFill(['is_platform_admin' => true, 'tenant_id' => null])->save();
        $denied = [];
        Log::listen(function (MessageLogged $event) use (&$denied): void {
            if ($event->message === 'Candidate sensitive reveal was denied.') {
                $denied[] = json_encode($event->context);
            }
        });

        $this->actingAs($company->employee)->get('/candidates')->assertForbidden();
        $this->actingAs($company->lead)->get('/candidates')->assertForbidden();
        $this->actingAs($this->owner($company, 'Accounts'))->get('/candidates')->assertForbidden();
        $this->actingAs($platform)->get('/candidates')->assertForbidden();
        $publicFile = $this->get('/storage/'.$path);
        $this->assertContains($publicFile->status(), [401, 403, 404]);

        $this->actingAs($company->hr)->get('/candidates/'.$submissionId)
            ->assertOk()
            ->assertSee('***-**-6789')
            ->assertSee('****9012')
            ->assertSee('*****6780')
            ->assertSee('On file')
            ->assertSee('Timestamp')
            ->assertDontSee('123-45-6789')
            ->assertDontSee('123456789')
            ->assertDontSee('123456789012')
            ->assertDontSee('123456780')
            ->assertDontSee('Example Bank')
            ->assertDontSee('100 Example Street')
            ->assertDontSee('1990-01-15')
            ->assertDontSee('+1 202 555 0100')
            ->assertDontSee('ABC123')
            ->assertDontSee('EAD123')
            ->assertSee('Passport copy')
            ->assertInertia(fn (Assert $page) => $page
                ->where('submission.personal.Date of birth', 'On file')
                ->where('submission.personal.Current address', 'On file')
                ->where('submission.personal.Primary contact number', '••••0100')
                ->where('submission.authorization.I-94 number', '••••C123')
                ->where('submission.authorization.EAD card document number', '••••D123')
                ->missing('submission.phone_ciphertext')
                ->missing('submission.i94_ciphertext'));
        $this->actingAs($other->hr)->get('/candidates/'.$submissionId)->assertNotFound();
        $this->actingAs($other->hr)->withSession(['auth.password_confirmed_at' => time()])->get('/candidates/'.$submissionId.'/documents/'.$documentId)->assertNotFound();
        $this->actingAs($other->hr)->withSession(['auth.password_confirmed_at' => time()])->post('/candidates/'.$submissionId.'/ssn')->assertNotFound();
        $this->actingAs($company->employee)->withSession(['auth.password_confirmed_at' => time()])->get('/candidates/'.$submissionId.'/documents/'.$documentId)->assertForbidden();

        $download = $this->actingAs($company->hr)->withSession(['auth.password_confirmed_at' => time()])->get('/candidates/'.$submissionId.'/documents/'.$documentId);
        $download->assertOk();
        $this->assertStringContainsString('nosniff', (string) $download->headers->get('X-Content-Type-Options'));
        $this->assertSame(1, DB::table('candidate_onboarding_events')->where('action', 'document_downloaded')->count());

        $revealed = $this->actingAs($company->hr)->withSession(['auth.password_confirmed_at' => time()])->postJson('/candidates/'.$submissionId.'/ssn');
        $revealed->assertOk()->assertJson(['ssn' => '123-45-6789']);
        $this->assertStringContainsString('no-store', (string) $revealed->headers->get('Cache-Control'));
        $this->assertSame(1, DB::table('candidate_onboarding_events')->where('action', 'ssn_viewed')->count());
        $this->assertSame(0, DB::table('candidate_onboarding_events')->where('subject', 'like', '%123456789%')->count());

        $identity = $this->actingAs($company->hr)->postJson('/candidates/'.$submissionId.'/identity');
        $identity->assertOk()->assertJson([
            'phone' => '+1 202 555 0100',
            'dateOfBirth' => '1990-01-15',
            'address' => '100 Example Street',
            'i94Number' => 'ABC123',
            'eadNumber' => 'EAD123',
        ]);
        $this->assertStringContainsString('no-store', (string) $identity->headers->get('Cache-Control'));
        $this->assertSame(1, DB::table('candidate_onboarding_events')->where('action', 'identity_viewed')->whereNull('subject')->count());
        $this->actingAs($company->employee)->postJson('/candidates/'.$submissionId.'/identity')->assertForbidden();
        $this->assertSame(1, DB::table('candidate_onboarding_events')->where('action', 'identity_reveal_denied')->whereNull('subject')->count());
        $this->actingAs($other->hr)->postJson('/candidates/'.$submissionId.'/identity')->assertNotFound();
        $this->assertSame(1, DB::table('candidate_onboarding_events')->where('action', 'identity_reveal_denied')->count());

        $this->actingAs($company->employee)->withSession(['auth.password_confirmed_at' => time()])->postJson('/candidates/'.$submissionId.'/ssn')->assertForbidden();
        $this->assertSame(1, DB::table('candidate_onboarding_events')->where('action', 'ssn_reveal_denied')->whereNull('subject')->count());
        $this->actingAs($company->hr)->withSession(['auth.password_confirmed_at' => time()])->postJson('/candidates/'.$submissionId.'/banking')->assertForbidden();
        $this->assertSame(1, DB::table('candidate_onboarding_events')->where('action', 'banking_reveal_denied')->whereNull('subject')->count());
        $this->actingAs($other->hr)->withSession(['auth.password_confirmed_at' => time()])->postJson('/candidates/'.$submissionId.'/banking')->assertNotFound();
        $this->assertSame(1, DB::table('candidate_onboarding_events')->where('action', 'banking_reveal_denied')->count());
        $this->assertStringNotContainsString('123456789012', implode("\n", $denied));
        $this->assertStringNotContainsString('123-45-6789', implode("\n", $denied));
        $this->assertStringNotContainsString('100 Example Street', implode("\n", $denied));
        $this->assertStringNotContainsString('1990-01-15', implode("\n", $denied));
        $this->assertNotSame([], $denied);
        $bank = $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])->postJson('/candidates/'.$submissionId.'/banking');
        $bank->assertOk()->assertJson([
            'bankName' => 'Example Bank',
            'accountNumber' => '123456789012',
            'routingNumber' => '123456780',
        ]);
        $this->assertStringContainsString('no-store', (string) $bank->headers->get('Cache-Control'));
        $this->assertSame(1, DB::table('candidate_onboarding_events')->where('action', 'banking_viewed')->count());
        $this->assertSame(0, DB::table('candidate_onboarding_events')->where('subject', 'like', '%123456789012%')->count());

        $this->actingAs($company->hr)->post('/candidates/'.$submissionId.'/status', ['status' => 'changes_requested'])->assertSessionHasErrors('note');
        $this->actingAs($company->hr)->post('/candidates/'.$submissionId.'/status', [
            'status' => 'changes_requested',
            'note' => 'Please send a clearer passport. SSN 123-45-6789.',
        ])->assertSessionHasErrors('note');
        $this->actingAs($company->hr)->post('/candidates/'.$submissionId.'/status', [
            'status' => 'changes_requested',
            'note' => 'Number 123 45 6789 needs to be removed.',
        ])->assertSessionHasErrors('note');
        $this->actingAs($company->hr)->post('/candidates/'.$submissionId.'/status', [
            'status' => 'under_review',
            'note' => 'Review started.',
        ])->assertRedirect();
        $this->actingAs($company->hr)->post('/candidates/'.$submissionId.'/status', [
            'status' => 'approved',
            'note' => 'Reviewed.',
        ])->assertRedirect();
        $this->assertSame('approved', DB::table('candidate_submissions')->where('id', $submissionId)->value('status'));
        $this->assertSame($employees, DB::table('employees')->count());
        $this->assertSame($payments, DB::table('payroll_payments')->count());

        $this->actingAs($company->hr)->post('/candidates/'.$submissionId.'/operations', [
            'payment_received' => true,
            'amount_paid' => '25.00',
            'payment_method' => 'check',
            'training_agreement_sent' => true,
            'work_day' => '2026-11-02',
        ])->assertRedirect();
        $this->assertSame('25.00', number_format((float) DB::table('candidate_submission_operations')->where('submission_id', $submissionId)->value('amount_paid'), 2, '.', ''));
        $this->assertSame($payments, DB::table('payroll_payments')->count());
        $this->assertSame($employees, DB::table('employees')->count());
        $this->actingAs($other->hr)->post('/candidates/'.$submissionId.'/operations', [
            'payment_received' => false,
            'training_agreement_sent' => false,
        ])->assertNotFound();
    }

    public function test_a_request_larger_than_the_server_limit_is_refused_without_a_submission(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $this->enable($this->owner($company));
        $this->app['auth']->forgetGuards();
        $token = (string) DB::table('candidate_onboarding_forms')->where('tenant_id', $company->id)->value('public_token');
        $postMax = $this->iniBytes((string) ini_get('post_max_size'));
        $this->assertGreaterThan(0, $postMax);
        $this->assertLessThan(CandidateForm::RECOMMENDED_POST_MAX_BYTES, $postMax);
        $this->assertGreaterThan(CandidateForm::MAX_KILOBYTES * 1024, CandidateForm::RECOMMENDED_UPLOAD_MAX_BYTES);
        $this->assertGreaterThan(9 * CandidateForm::MAX_KILOBYTES * 1024, CandidateForm::RECOMMENDED_POST_MAX_BYTES);
        $logged = [];
        Log::listen(function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message.' '.json_encode($event->context);
        });

        $response = $this->call('POST', '/apply/'.$token, [], [], [], [
            'CONTENT_LENGTH' => (string) ($postMax + 1),
        ]);

        $response->assertRedirect('/apply/'.$token.'?notice=request-too-large');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame(0, DB::table('candidate_submissions')->count());
        $this->get('/apply/'.$token.'?notice=request-too-large')
            ->assertOk()
            ->assertSee(CandidateForm::REQUEST_TOO_LARGE, false);
        $this->assertStringNotContainsString($token, implode("\n", $logged));
    }

    public function test_repeated_public_posts_are_rate_limited(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $this->enable($this->owner($company));
        $this->app['auth']->forgetGuards();
        $token = (string) DB::table('candidate_onboarding_forms')->where('tenant_id', $company->id)->value('public_token');

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->post('/apply/'.$token, ['email' => 'limit-'.$attempt.'@example.test']);
        }

        $this->post('/apply/'.$token, ['email' => 'limit-final@example.test'])
            ->assertRedirect('/apply/'.$token)
            ->assertSessionHasErrors(['form' => 'Too many submissions were sent from this network. Wait a minute and try again.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function fields(string $email = 'candidate@example.test'): array
    {
        return [
            'first_name' => 'Asha',
            'last_name' => 'Raman',
            'gender' => 'Female',
            'email' => $email,
            'phone' => '+1 202 555 0100',
            'date_of_birth' => '1990-01-15',
            'current_address' => '100 Example Street',
            'university_name' => 'Example University',
            'job_title' => 'Analyst',
            'employment_type' => 'Hourly',
            'work_location' => 'Office',
            'hourly_rate' => '25.50',
            'hours_per_week' => 40,
            'start_date' => '2026-11-01',
            'experience' => '3 years',
            'responsibility_1' => 'Prepare reports',
            'bank_name' => 'Example Bank',
            'account_number' => '123456789012',
            'routing_number' => '123456780',
            'work_authorization' => 'Candidate-entered status',
            'i94_number' => 'ABC123',
            'ead_number' => 'EAD123',
            'work_authorization_expires_on' => '2027-01-01',
            'ssn' => '123-45-6789',
            'student_role' => 'Training participant',
            'goals' => 'Learn the role',
            'oversight' => 'Supervisor review',
            'evaluation_methods' => 'Monthly review',
            'consent' => true,
        ];
    }

    /**
     * @return array<string, UploadedFile>
     */
    private function files(): array
    {
        $pdf = fn (string $name) => UploadedFile::fake()->create($name, 20, 'application/pdf');

        return [
            'document_passport' => $pdf('passport.pdf'),
            'document_visa' => $pdf('visa.pdf'),
            'document_i94' => $pdf('i94.pdf'),
            'document_ead_front' => UploadedFile::fake()->create('ead-front.jpg', 20, 'image/jpeg'),
            'document_ead_back' => UploadedFile::fake()->create('ead-back.jpg', 20, 'image/jpeg'),
            'document_masters_degree' => $pdf('degree.pdf'),
            'document_opt_i20' => $pdf('i20.pdf'),
            'document_resume' => $pdf('resume.pdf'),
        ];
    }

    private function iniBytes(string $value): int
    {
        $value = trim($value);

        if ($value === '' || $value === '0') {
            return 0;
        }

        $metric = strtoupper(substr($value, -1));
        $number = (int) $value;

        return match ($metric) {
            'K' => $number * 1024,
            'M' => $number * 1048576,
            'G' => $number * 1073741824,
            default => (int) $value,
        };
    }

    private function enable(User $owner): void
    {
        $this->actingAs($owner)->put('/company/settings/candidate-form', [
            'enabled' => true,
            'confirm_disable' => false,
        ])->assertRedirect();
    }

    private function owner(CompanyFixture $company, string $role = 'Owner'): User
    {
        $user = User::factory()->create();
        $user->forceFill([
            'tenant_id' => $company->id,
            'is_platform_admin' => false,
            'invite_accepted_at' => now(),
        ])->save();
        $roleId = DB::table('roles')->where('tenant_id', $company->id)->where('name', $role)->value('id');

        if ($roleId === null) {
            $roleId = DB::table('roles')->insertGetId([
                'tenant_id' => $company->id,
                'name' => $role,
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        DB::table('model_has_roles')->insert([
            'role_id' => $roleId,
            'model_type' => User::class,
            'model_id' => $user->id,
            'tenant_id' => $company->id,
        ]);

        return $user;
    }
}
