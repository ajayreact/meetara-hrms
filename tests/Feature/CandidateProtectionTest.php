<?php

namespace Tests\Feature;

use App\Contracts\CaptchaVerifier;
use App\Contracts\MalwareScanner;
use App\Models\User;
use App\Services\Hrms\CandidateRetention;
use App\Support\Candidate\ClamAvScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Process\Process;
use Tests\Support\CompanyFixture;
use Tests\TestCase;

class CandidateProtectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_sensitive_reveals_and_downloads_require_password_confirmation(): void
    {
        [$company, $owner, $submissionId, $documentId] = $this->submission();
        $logged = [];
        Log::listen(function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message.' '.json_encode($event->context);
        });

        $this->actingAs($company->hr)->postJson('/candidates/'.$submissionId.'/ssn')->assertStatus(423);
        $this->actingAs($company->hr)->postJson('/candidates/'.$submissionId.'/banking')->assertStatus(423);
        $this->actingAs($company->hr)->get('/candidates/'.$submissionId.'/documents/'.$documentId)->assertRedirect('/confirm-password');

        $this->actingAs($company->hr)->postJson('/confirm-password', ['password' => 'wrong-password'])->assertStatus(422);
        $this->actingAs($company->hr)->postJson('/candidates/'.$submissionId.'/ssn')->assertStatus(423);
        $this->assertStringNotContainsString('wrong-password', implode("\n", $logged));

        $this->actingAs($company->hr)->withSession(['auth.password_confirmed_at' => time() - 901])
            ->postJson('/candidates/'.$submissionId.'/ssn')
            ->assertStatus(423);

        $confirmed = $this->actingAs($company->hr)->postJson('/confirm-password', ['password' => 'password']);
        $confirmed->assertOk()->assertJson(['confirmed' => true]);
        $this->assertStringContainsString('no-store', (string) $confirmed->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $confirmed->headers->get('Cache-Control'));
        $this->actingAs($company->hr)->postJson('/candidates/'.$submissionId.'/ssn')
            ->assertOk()
            ->assertJson(['ssn' => '123-45-6789']);

        $this->actingAs($company->employee)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/candidates/'.$submissionId.'/ssn')
            ->assertForbidden();
        $this->actingAs($company->employee)->withSession(['auth.password_confirmed_at' => time()])
            ->get('/candidates/'.$submissionId.'/documents/'.$documentId)
            ->assertForbidden();

        $other = CompanyFixture::make('Deep Data Inc', 'deep-data');
        $this->actingAs($other->hr)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/candidates/'.$submissionId.'/ssn')
            ->assertNotFound();
        $this->actingAs($other->hr)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/candidates/'.$submissionId.'/banking')
            ->assertNotFound();
        $this->actingAs($other->hr)->withSession(['auth.password_confirmed_at' => time()])
            ->get('/candidates/'.$submissionId.'/documents/'.$documentId)
            ->assertNotFound();
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])
            ->get('/candidates')
            ->assertOk()
            ->assertDontSee('123-45-6789');
    }

    public function test_required_scanning_and_captcha_fail_closed_until_configured(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $owner = $this->owner($company);
        config(['candidate.malware_scan' => 'required', 'candidate.clamav_command' => '']);

        $this->actingAs($owner)->put('/company/settings/candidate-form', [
            'enabled' => true,
            'confirm_disable' => false,
        ])->assertSessionHasErrors('enabled');
        $this->assertNull(DB::table('candidate_onboarding_forms')->where('tenant_id', $company->id)->where('enabled', true)->first());

        config(['candidate.malware_scan' => 'off']);
        $this->enable($owner);
        DB::table('candidate_onboarding_forms')->where('tenant_id', $company->id)->update(['enabled' => true]);
        config(['candidate.malware_scan' => 'required', 'candidate.clamav_command' => '']);
        $token = (string) DB::table('candidate_onboarding_forms')->where('tenant_id', $company->id)->value('public_token');
        $this->app['auth']->forgetGuards();
        $this->post('/apply/'.$token, [...$this->fields(), ...$this->files()])
            ->assertSessionHasErrors(['form' => 'This form is not accepting submissions.']);
        $this->assertSame(0, DB::table('candidate_submissions')->count());

        config(['candidate.malware_scan' => 'off', 'candidate.captcha' => 'required', 'candidate.turnstile_site_key' => '', 'candidate.turnstile_secret' => '']);
        $this->assertFalse(app(CaptchaVerifier::class)->configured());
        $this->post('/apply/'.$token, [...$this->fields('closed@example.test'), ...$this->files()])
            ->assertSessionHasErrors(['form' => 'This form is not accepting submissions.']);
    }

    public function test_a_configured_scanner_rejects_a_harmless_infected_fixture_and_captcha_tokens_are_checked(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $owner = $this->owner($company);
        $this->enable($owner);
        $token = (string) DB::table('candidate_onboarding_forms')->where('tenant_id', $company->id)->value('public_token');
        $this->app['auth']->forgetGuards();
        config([
            'candidate.malware_scan' => 'required',
            'candidate.captcha' => 'required',
            'candidate.turnstile_site_key' => 'site-key-example',
            'candidate.turnstile_secret' => 'secret-example',
        ]);
        $scanner = new class implements MalwareScanner
        {
            public string $verdict = 'infected';

            public function available(): bool
            {
                return true;
            }

            public function scan(string $absolutePath): string
            {
                return $this->verdict;
            }
        };
        $this->app->instance(MalwareScanner::class, $scanner);
        $logged = [];
        Log::listen(function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message.' '.json_encode($event->context);
        });

        Http::fake([
            'https://challenges.cloudflare.com/turnstile/v0/siteverify' => Http::sequence()
                ->push(['success' => true])
                ->push(['success' => false, 'error-codes' => ['invalid-input-response']])
                ->push(['success' => false, 'error-codes' => ['timeout-or-duplicate']])
                ->push([], 503)
                ->push(['success' => true]),
        ]);
        $this->post('/apply/'.$token, [...$this->fields(), ...$this->files(), 'captcha_token' => 'token-example'])
            ->assertSessionHasErrors(['document_passport' => 'The file was rejected by the malware check.']);
        $this->assertSame(0, DB::table('candidate_submissions')->count());
        $this->assertStringNotContainsString('token-example', implode("\n", $logged));
        $this->assertStringNotContainsString('secret-example', implode("\n", $logged));

        $scanner->verdict = 'clean';
        $this->assertSame('required', config('candidate.captcha'));
        $this->post('/apply/'.$token, [...$this->fields('missing-check@example.test'), ...$this->files()])
            ->assertSessionHasErrors(['captcha_token' => 'Complete the verification check before submitting.']);
        $this->post('/apply/'.$token, [...$this->fields('invalid-check@example.test'), ...$this->files(), 'captcha_token' => 'bad-token'])
            ->assertSessionHasErrors(['captcha_token' => 'Complete the verification check before submitting.']);
        $this->post('/apply/'.$token, [...$this->fields('expired-check@example.test'), ...$this->files(), 'captcha_token' => 'expired-token'])
            ->assertSessionHasErrors(['captcha_token' => 'The verification check expired. Complete it again.']);
        $this->post('/apply/'.$token, [...$this->fields('down-check@example.test'), ...$this->files(), 'captcha_token' => 'offline-token'])
            ->assertSessionHasErrors(['captcha_token' => 'The verification check is unavailable. The form was not submitted.']);
        $this->assertSame(0, DB::table('candidate_submissions')->count());

        $this->post('/apply/'.$token, [...$this->fields('accepted-check@example.test'), ...$this->files(), 'captcha_token' => 'good-token'])
            ->assertRedirect();
        $this->assertSame(1, DB::table('candidate_submissions')->count());
        $this->assertArrayNotHasKey('captcha_token', session()->get('_old_input', []));
    }

    public function test_an_unhandled_public_exception_omits_secret_fields(): void
    {
        config(['app.debug' => true]);
        $this->mock(\App\Services\Hrms\CandidateOnboarding::class, function ($mock): void {
            $mock->shouldReceive('submit')->once()->andThrow(new \RuntimeException('candidate-fault-marker'));
        });
        $ssn = '123'.'-45'.'-6789';
        $bank = 'Example'.' Bank';
        $account = '123456'.'789012';
        $routing = '123456'.'780';
        $address = '100 Example'.' Street';
        $born = '1990-01'.'-15';
        $phone = '+1 202 '.'555 0100';
        $tokenValue = 'secret'.'-token-value';

        $response = $this->post('/apply/'.str_repeat('ab', 32), [
            'ssn' => $ssn,
            'bank_name' => $bank,
            'account_number' => $account,
            'routing_number' => $routing,
            'phone' => $phone,
            'date_of_birth' => $born,
            'current_address' => $address,
            'i94_number' => 'ABC'.'123',
            'ead_number' => 'EAD'.'123',
            'captcha_token' => $tokenValue,
            'first_name' => 'Ada',
        ]);

        $response->assertStatus(500)->assertSee('candidate-fault-marker');
        $body = $response->getContent();
        $this->assertIsString($body);
        $this->assertStringNotContainsString($ssn, $body);
        $this->assertStringNotContainsString($bank, $body);
        $this->assertStringNotContainsString($account, $body);
        $this->assertStringNotContainsString($routing, $body);
        $this->assertStringNotContainsString($address, $body);
        $this->assertStringNotContainsString($tokenValue, $body);
        $this->assertStringNotContainsString('ABC123', $body);
        $this->assertStringNotContainsString('EAD123', $body);
        $this->assertStringNotContainsString($born, $body);
        $this->assertStringNotContainsString($phone, $body);
        $this->assertStringContainsString('Ada', $body);
    }

    public function test_the_public_form_receives_the_captcha_site_key_only(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $owner = $this->owner($company);
        $this->enable($owner);
        $token = (string) DB::table('candidate_onboarding_forms')->where('tenant_id', $company->id)->value('public_token');
        $this->app['auth']->forgetGuards();
        config([
            'candidate.captcha' => 'required',
            'candidate.turnstile_site_key' => 'site-key-example',
            'candidate.turnstile_secret' => 'secret-example',
        ]);

        $this->get('/apply/'.$token)
            ->assertOk()
            ->assertDontSee('secret-example')
            ->assertInertia(fn (Assert $page) => $page
                ->component('apply/form')
                ->where('form.captcha.required', true)
                ->where('form.captcha.siteKey', 'site-key-example')
                ->missing('form.captcha.secret'));
    }

    public function test_a_document_with_an_unknown_category_is_not_downloaded(): void
    {
        [$company, $owner, $submissionId, $documentId] = $this->submission();
        DB::table('candidate_submission_documents')->where('id', $documentId)->update(['category' => 'not-a-category']);

        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])
            ->get('/candidates/'.$submissionId.'/documents/'.$documentId)
            ->assertNotFound();
        $this->assertSame(0, DB::table('candidate_onboarding_events')->where('action', 'document_downloaded')->count());
    }

    public function test_the_scanner_boundary_classifies_a_harmless_fixture_without_logging_it(): void
    {
        $directory = storage_path('framework/testing');
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        $command = $directory.DIRECTORY_SEPARATOR.'candidate-scan-fixture.bat';
        $clean = $directory.DIRECTORY_SEPARATOR.'candidate-clean-fixture.txt';
        $infected = $directory.DIRECTORY_SEPARATOR.'candidate-infected-fixture.txt';
        file_put_contents($command, "@echo off\r\nif \"%~1\"==\"--version\" exit /b 0\r\nfindstr /C:\"MEETARA-HARMLESS-SCAN-FIXTURE-INFECTED\" \"%~2\" >nul && exit /b 1\r\nfindstr /C:\"MEETARA-HARMLESS-SCAN-FIXTURE\" \"%~2\" >nul && exit /b 0\r\nexit /b 2\r\n");
        file_put_contents($clean, 'MEETARA-HARMLESS-SCAN-FIXTURE');
        file_put_contents($infected, 'MEETARA-HARMLESS-SCAN-FIXTURE-INFECTED');
        config(['candidate.clamav_command' => $command, 'candidate.clamav_timeout' => 5]);

        try {
            $scanner = new ClamAvScanner;
            $this->assertTrue($scanner->available());
            $this->assertSame('clean', $scanner->scan($clean));
            $this->assertSame('infected', $scanner->scan($infected));
            config(['candidate.clamav_command' => '']);
            $this->assertFalse((new ClamAvScanner)->available());
            $this->assertSame('unavailable', (new ClamAvScanner)->scan($clean));
        } finally {
            @unlink($command);
            @unlink($clean);
            @unlink($infected);
        }
    }

    public function test_token_rotation_invalidates_only_the_previous_link(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $owner = $this->owner($company);
        $this->enable($owner);
        $original = (string) DB::table('candidate_onboarding_forms')->where('tenant_id', $company->id)->value('public_token');
        $this->app['auth']->forgetGuards();
        $this->post('/apply/'.$original, [...$this->fields(), ...$this->files()])->assertRedirect();
        $submissionId = (int) DB::table('candidate_submissions')->value('id');

        $this->actingAs($company->hr)->post('/company/settings/candidate-form/token', ['confirm_rotate' => true])->assertForbidden();
        $this->actingAs($owner)->post('/company/settings/candidate-form/token', ['confirm_rotate' => false])->assertSessionHasErrors('confirm_rotate');
        $this->actingAs($owner)->post('/company/settings/candidate-form/token', ['confirm_rotate' => true])->assertRedirect();
        $rotated = (string) DB::table('candidate_onboarding_forms')->where('tenant_id', $company->id)->value('public_token');
        $this->assertNotSame($original, $rotated);
        $this->assertSame(64, strlen($rotated));
        $this->assertSame(1, DB::table('candidate_onboarding_events')->where('action', 'form_token_rotated')->whereNull('subject')->count());
        $this->assertSame($submissionId, (int) DB::table('candidate_submissions')->value('id'));

        $this->app['auth']->forgetGuards();
        $this->post('/apply/'.$original, [...$this->fields('old-link@example.test'), ...$this->files()])->assertNotFound();
        $this->post('/apply/'.$rotated, [...$this->fields('new-link@example.test'), ...$this->files()])->assertRedirect();
        $this->actingAs($owner)->put('/company/settings/candidate-form', ['enabled' => false, 'confirm_disable' => true])->assertRedirect();
        $disabledToken = (string) DB::table('candidate_onboarding_forms')->where('tenant_id', $company->id)->value('public_token');
        $this->app['auth']->forgetGuards();
        $this->post('/apply/'.$disabledToken, [...$this->fields('disabled-link@example.test'), ...$this->files()])
            ->assertSessionHasErrors(['form' => 'This form is not accepting submissions.']);
    }

    public function test_retention_dry_run_does_not_delete_and_purge_stays_inside_the_tenant(): void
    {
        [$company, $owner, $submissionId] = $this->submission();
        unset($owner);
        $other = CompanyFixture::make('Deep Data Inc', 'deep-data');
        $otherOwner = $this->owner($other);
        $this->enable($otherOwner);
        $otherToken = (string) DB::table('candidate_onboarding_forms')->where('tenant_id', $other->id)->value('public_token');
        $this->app['auth']->forgetGuards();
        $this->post('/apply/'.$otherToken, [...$this->fields('other-tenant@example.test'), ...$this->files()])->assertRedirect();
        $otherId = (int) DB::table('candidate_submissions')->where('tenant_id', $other->id)->value('id');
        Storage::disk('local')->put('candidate-submissions/'.$company->id.'/'.$submissionId.'/passport-test.pdf', 'synthetic-file');
        DB::table('candidate_submissions')->where('id', $submissionId)->update(['created_at' => now()->subDays(10)]);

        $this->artisan('candidates:retention-dry-run')
            ->expectsOutputToContain('No retention period is approved')
            ->assertExitCode(0);
        $this->assertSame(2, DB::table('candidate_submissions')->count());

        config(['candidate.retention_days' => 7]);
        $reference = (string) DB::table('candidate_submissions')->where('id', $submissionId)->value('reference');
        $this->artisan('candidates:retention-dry-run')
            ->expectsOutputToContain($reference)
            ->assertExitCode(0);
        $this->assertNotNull(DB::table('candidate_submissions')->where('id', $submissionId)->first());

        $retention = app(CandidateRetention::class);
        $retention->purge($other->id, $submissionId, null, 7);
        $this->assertNotNull(DB::table('candidate_submissions')->where('id', $submissionId)->first());

        $retention->purge($company->id, $submissionId, null, 7);
        $this->assertNull(DB::table('candidate_submissions')->where('id', $submissionId)->first());
        $this->assertNotNull(DB::table('candidate_submissions')->where('id', $otherId)->first());
        $this->assertFalse(Storage::disk('local')->exists('candidate-submissions/'.$company->id.'/'.$submissionId.'/passport-test.pdf'));
        $audit = DB::table('candidate_retention_audits')->where('submission_id', $submissionId)->first();
        $this->assertSame($reference, $audit->reference);
        $this->assertSame($company->id, (int) $audit->tenant_id);
        $this->assertTrue((bool) $audit->files_removed);
        $this->assertStringNotContainsString('synthetic-file', json_encode($audit));

        Storage::disk('local')->put('candidate-submissions/'.$company->id.'/'.$submissionId.'/left.pdf', 'still-there');
        $this->assertTrue($retention->retryOrphanFiles($company->id, $submissionId));
        $this->assertFalse(Storage::disk('local')->exists('candidate-submissions/'.$company->id.'/'.$submissionId.'/left.pdf'));
        Storage::disk('local')->put('candidate-submissions/'.$other->id.'/'.$otherId.'/kept.pdf', 'kept');
        $this->assertFalse($retention->retryOrphanFiles($other->id, $otherId));
        $this->assertTrue(Storage::disk('local')->exists('candidate-submissions/'.$other->id.'/'.$otherId.'/kept.pdf'));
    }

    public function test_a_file_deletion_failure_does_not_commit_when_the_audit_write_fails(): void
    {
        [$company, , $submissionId] = $this->submission();
        DB::table('candidate_submissions')->where('id', $submissionId)->update(['created_at' => now()->subDays(10)]);
        Storage::disk('local')->put('candidate-submissions/'.$company->id.'/'.$submissionId.'/passport-test.pdf', 'synthetic-file');
        $armed = true;
        DB::listen(function ($query) use (&$armed): void {
            if ($armed && str_contains(strtolower($query->sql), 'candidate_retention_audits')) {
                $armed = false;
                throw new \RuntimeException('forced rollback');
            }
        });

        try {
            app(CandidateRetention::class)->purge($company->id, $submissionId, null, 7);
            $this->fail('The purge should have rolled back.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('forced rollback', $exception->getMessage());
        }

        $this->assertNotNull(DB::table('candidate_submissions')->where('id', $submissionId)->first());
        $this->assertTrue(Storage::disk('local')->exists('candidate-submissions/'.$company->id.'/'.$submissionId.'/passport-test.pdf'));
        $this->assertSame(0, DB::table('candidate_retention_audits')->count());
    }

    public function test_the_pending_migration_builds_encrypted_columns_on_an_isolated_sqlite_file(): void
    {
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $path = storage_path('framework/testing-candidate-schema.sqlite');
        if (is_file($path)) {
            unlink($path);
        }
        $pdo = new \PDO('sqlite:'.$path);
        $pdo->exec('create table tenants (id integer primary key)');
        $pdo->exec('create table users (id integer primary key)');
        $pdo = null;

        $this->assertStringEndsWith('testing-candidate-schema.sqlite', $path);
        $this->assertStringNotContainsString('meetara_hrms', $path);
        $env = array_merge(getenv() ?: [], $_ENV, [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $path,
            'DB_URL' => '',
            'DATABASE_URL' => '',
        ]);
        unset($env['DB_USERNAME'], $env['DB_PASSWORD']);
        $this->assertSame($path, $env['DB_DATABASE']);
        $this->assertNotSame('meetara_hrms', $env['DB_DATABASE']);
        $migrate = new Process([
            PHP_BINARY,
            'artisan',
            'migrate',
            '--path=database/migrations/2026_10_23_200000_add_candidate_onboarding.php',
            '--force',
            '--no-interaction',
        ], base_path(), $env);
        $migrate->setTimeout(60);
        $migrate->run();
        $this->assertTrue($migrate->isSuccessful(), $migrate->getErrorOutput().$migrate->getOutput());

        $check = new \PDO('sqlite:'.$path);
        $columns = array_column($check->query('pragma table_info(candidate_submissions)')->fetchAll(\PDO::FETCH_ASSOC), 'name');
        foreach (['phone', 'date_of_birth', 'current_address', 'i94_number', 'ead_number', 'ssn', 'bank_name', 'account_number', 'routing_number'] as $plaintext) {
            $this->assertNotContains($plaintext, $columns);
        }
        foreach (['phone_ciphertext', 'date_of_birth_ciphertext', 'address_ciphertext', 'i94_ciphertext', 'ead_ciphertext', 'ssn_ciphertext', 'bank_name_ciphertext', 'account_ciphertext', 'routing_ciphertext'] as $encrypted) {
            $this->assertContains($encrypted, $columns);
        }
        $tables = array_column($check->query("select name from sqlite_master where type = 'table'")->fetchAll(\PDO::FETCH_ASSOC), 'name');
        $this->assertContains('candidate_retention_audits', $tables);

        $rollback = new Process([
            PHP_BINARY,
            'artisan',
            'migrate:rollback',
            '--path=database/migrations/2026_10_23_200000_add_candidate_onboarding.php',
            '--force',
            '--no-interaction',
        ], base_path(), $env);
        $rollback->setTimeout(60);
        $rollback->run();
        $this->assertTrue($rollback->isSuccessful(), $rollback->getErrorOutput().$rollback->getOutput());
        $after = array_column($check->query("select name from sqlite_master where type = 'table'")->fetchAll(\PDO::FETCH_ASSOC), 'name');
        $this->assertNotContains('candidate_submissions', $after);
        $this->assertNotContains('candidate_retention_audits', $after);
        $check = null;
        unlink($path);
    }

    /**
     * @return array{0: CompanyFixture, 1: User, 2: int, 3: int}
     */
    private function submission(): array
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix');
        $owner = $this->owner($company);
        $this->enable($owner);
        $token = (string) DB::table('candidate_onboarding_forms')->where('tenant_id', $company->id)->value('public_token');
        $this->app['auth']->forgetGuards();
        $this->post('/apply/'.$token, [...$this->fields(), ...$this->files()])->assertRedirect();

        return [
            $company,
            $owner,
            (int) DB::table('candidate_submissions')->where('tenant_id', $company->id)->value('id'),
            (int) DB::table('candidate_submission_documents')->where('category', 'passport')->value('id'),
        ];
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

    private function enable(User $owner): void
    {
        $this->actingAs($owner)->put('/company/settings/candidate-form', [
            'enabled' => true,
            'confirm_disable' => false,
        ])->assertRedirect();
    }

    private function owner(CompanyFixture $company): User
    {
        $user = User::factory()->create();
        $user->forceFill([
            'tenant_id' => $company->id,
            'is_platform_admin' => false,
            'invite_accepted_at' => now(),
        ])->save();
        $roleId = (int) DB::table('roles')->where('tenant_id', $company->id)->where('name', 'Owner')->value('id');
        DB::table('model_has_roles')->insert([
            'role_id' => $roleId,
            'model_type' => User::class,
            'model_id' => $user->id,
            'tenant_id' => $company->id,
        ]);

        return $user->refresh();
    }
}
