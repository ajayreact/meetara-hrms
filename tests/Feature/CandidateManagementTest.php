<?php

namespace Tests\Feature;

use App\Models\CandidateChecklistItem;
use App\Models\CandidatePayment;
use App\Models\CandidatePaymentReceipt;
use App\Models\CandidateRecord;
use App\Models\CandidateRecordDocument;
use App\Models\CandidateRecordEvent;
use App\Models\User;
use App\Support\CandidateRegistry;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\Support\CompanyFixture;
use Tests\TestCase;

class CandidateManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        config(['app.timezone' => 'UTC']);
        Carbon::setTestNow(Carbon::parse('2026-11-19 15:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_registration_keeps_optional_responsibility_and_does_not_create_a_ledger_payment(): void
    {
        $company = CompanyFixture::make('Candidate Co', 'candidate-co');
        $payload = $this->payload();
        unset($payload['responsibility_4']);

        $this->actingAs($company->hr)->post('/candidate-records', $payload)->assertRedirect();

        $record = CandidateRecord::query()->firstOrFail();
        $this->assertNull($record->responsibility_4);
        $this->assertSame('Yes', $record->payment_received);
        $this->assertSame('250.00', $record->stated_amount);
        $this->assertSame(0, CandidatePayment::query()->count());
        $this->assertSame(11, CandidateChecklistItem::query()->where('candidate_record_id', $record->id)->count());
        $this->assertSame('pending', CandidateChecklistItem::query()->where('item_key', 'drivers_license_verified')->value('status'));
        $this->assertDatabaseMissing('candidate_records', ['ssn_ciphertext' => '123456789']);
    }

    public function test_a_candidate_can_be_saved_without_a_drivers_license_and_the_checklist_stays_pending(): void
    {
        $company = CompanyFixture::make('License Co', 'license-co');
        $this->actingAs($company->hr)->post('/candidate-records', $this->payload())->assertRedirect();

        $this->assertSame(0, CandidateRecordDocument::query()->count());
        $this->assertSame('pending', CandidateChecklistItem::query()->where('item_key', 'drivers_license_verified')->value('status'));
    }

    public function test_profile_masks_secrets_and_reveal_requires_password_confirmation(): void
    {
        $company = CompanyFixture::make('Secret Co', 'secret-co');
        $record = $this->register($company->hr);

        $this->actingAs($company->hr)->get('/candidate-records/'.$record->id)
            ->assertOk()
            ->assertDontSee('123456789')
            ->assertDontSee('123456789012')
            ->assertDontSee('123456780')
            ->assertDontSee('I94TEST0001')
            ->assertDontSee('EADTEST0001')
            ->assertSee('***-**-6789')
            ->assertInertia(fn (Assert $page) => $page
                ->where('record.personal.ssnMask', '***-**-6789')
                ->where('record.bank.accountMask', '****9012')
                ->where('record.bank.routingMask', '****6780')
                ->where('record.authorization.i94Mask', '****0001')
                ->where('record.authorization.eadMask', '****0001')
                ->missing('record.ssn')
            );

        $this->actingAs($company->hr)->postJson('/candidate-records/'.$record->id.'/reveal', ['field' => 'ssn'])->assertStatus(423);

        $revealed = $this->actingAs($company->hr)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/candidate-records/'.$record->id.'/reveal', ['field' => 'ssn']);
        $revealed->assertOk()
            ->assertJson(['value' => '123456789'])
            ->assertHeader('x-content-type-options', 'nosniff');
        $cache = (string) $revealed->headers->get('cache-control');
        $this->assertStringContainsString('private', $cache);
        $this->assertStringContainsString('no-store', $cache);

        $this->assertArrayNotHasKey('ssn_ciphertext', $record->fresh()->toArray());

        $event = CandidateRecordEvent::query()->where('action', 'revealed')->firstOrFail();
        $this->assertSame('ssn', $event->subject);
        $this->assertNull($event->to_value);
        $this->assertNull($event->note);
        $this->assertStringNotContainsString('123456789', json_encode($event->only(['subject', 'from_value', 'to_value', 'note'])));
    }

    public function test_uploads_do_not_verify_the_checklist_or_fill_expiry_dates(): void
    {
        $company = CompanyFixture::make('Upload Co', 'upload-co');
        $record = $this->register($company->hr);
        $record->forceFill([
            'ead_expiry_date' => '2027-05-01',
            'passport_expiry_date' => '2028-01-01',
            'work_authorization_expires_on' => '2027-04-01',
        ])->save();

        $this->actingAs($company->hr)->post('/candidate-records/'.$record->id.'/documents', [
            'category' => 'passport',
            'file' => $this->pdf('passport.pdf'),
        ])->assertRedirect();

        $record->refresh();
        $this->assertSame('2027-05-01', $record->ead_expiry_date?->toDateString());
        $this->assertSame('2028-01-01', $record->passport_expiry_date?->toDateString());
        $this->assertSame('2027-04-01', $record->work_authorization_expires_on?->toDateString());
        $this->assertSame('pending', CandidateChecklistItem::query()->where('item_key', 'passport_verified')->value('status'));
        $this->assertSame(0, CandidateRecordEvent::query()->where('action', 'checklist_updated')->count());

        $document = CandidateRecordDocument::query()->firstOrFail();
        $this->assertTrue(CandidateRegistry::ownsPath($document->storage_path, $company->id, $record->id, 'documents'));
        $this->assertTrue(Storage::disk('local')->exists($document->storage_path));

        $this->actingAs($company->hr)->get('/candidate-records/'.$record->id.'/documents/'.$document->id)
            ->assertOk()
            ->assertHeader('x-content-type-options', 'nosniff');
        $this->assertStringContainsString('private', (string) $this->actingAs($company->hr)->get('/candidate-records/'.$record->id.'/documents/'.$document->id)->headers->get('cache-control'));
    }

    public function test_ssn_document_download_requires_password_confirmation_and_rejects_a_foreign_path(): void
    {
        $company = CompanyFixture::make('File Co', 'file-co');
        $other = CompanyFixture::make('Other File Co', 'other-file-co');
        $record = $this->register($company->hr);

        $this->actingAs($company->hr)->post('/candidate-records/'.$record->id.'/documents', [
            'category' => 'ssn_document',
            'file' => $this->pdf('ssn.pdf'),
        ])->assertRedirect();
        $document = CandidateRecordDocument::query()->firstOrFail();

        $this->actingAs($company->hr)->getJson('/candidate-records/'.$record->id.'/documents/'.$document->id)->assertStatus(423);
        $this->actingAs($company->hr)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get('/candidate-records/'.$record->id.'/documents/'.$document->id)
            ->assertOk();

        $document->forceFill(['storage_path' => 'candidate-records/'.$company->id.'/'.$record->id.'/documents/../../.env'])->save();
        $this->actingAs($company->hr)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->getJson('/candidate-records/'.$record->id.'/documents/'.$document->id)
            ->assertNotFound();

        $this->actingAs($other->hr)->get('/candidate-records/'.$record->id.'/documents/'.$document->id)->assertNotFound();
        $this->actingAs($other->hr)->get('/candidate-records/'.$record->id)->assertNotFound();
        $this->actingAs($other->hr)->postJson('/candidate-records/'.$record->id.'/reveal', ['field' => 'ssn'])->assertNotFound();
    }

    public function test_usd_payments_and_receipts_stay_linked_and_other_currencies_are_rejected(): void
    {
        $company = CompanyFixture::make('Pay Co', 'pay-co');
        $record = $this->register($company->hr);

        $this->actingAs($company->hr)->post('/candidate-records/'.$record->id.'/payments', [
            'amount' => '40.00',
            'currency' => 'EUR',
            'paid_on' => '2026-11-01',
            'method' => 'Wire',
        ])->assertSessionHasErrors('currency');
        $this->assertSame(0, CandidatePayment::query()->count());

        $this->actingAs($company->hr)->post('/candidate-records/'.$record->id.'/payments', $this->payment('=SUM(A1)', '80.00'))->assertRedirect();
        $this->actingAs($company->hr)->post('/candidate-records/'.$record->id.'/payments', $this->payment('Wire two', '45.50'))->assertRedirect();
        $first = CandidatePayment::query()->orderBy('id')->firstOrFail();
        $second = CandidatePayment::query()->orderByDesc('id')->firstOrFail();

        $this->actingAs($company->hr)->post('/candidate-records/'.$record->id.'/payments/'.$first->id.'/receipts', ['file' => $this->pdf('one.pdf')])->assertRedirect();
        $this->actingAs($company->hr)->post('/candidate-records/'.$record->id.'/payments/'.$first->id.'/receipts', ['file' => $this->pdf('two.pdf')])->assertRedirect();
        $this->actingAs($company->hr)->post('/candidate-records/'.$record->id.'/payments/999999/receipts', ['file' => $this->pdf('missing.pdf')])->assertNotFound();

        $this->assertSame(2, CandidatePaymentReceipt::query()->where('candidate_payment_id', $first->id)->count());
        $this->assertSame(0, CandidatePaymentReceipt::query()->where('candidate_payment_id', $second->id)->count());
        $this->assertSame('USD', $first->currency);
        $receipt = CandidatePaymentReceipt::query()->firstOrFail();
        $this->assertTrue(CandidateRegistry::ownsPath($receipt->storage_path, $company->id, $record->id, 'receipts'));
        $this->actingAs($company->hr)->get('/candidate-records/'.$record->id.'/receipts/'.$receipt->id)->assertOk();
    }

    public function test_checklist_changes_are_manual_and_audited(): void
    {
        $company = CompanyFixture::make('Check Co', 'check-co');
        $record = $this->register($company->hr);
        $item = CandidateChecklistItem::query()->where('item_key', 'passport_verified')->firstOrFail();

        $this->actingAs($company->hr)->put('/candidate-records/'.$record->id.'/checklist/'.$item->id, [
            'status' => 'verified',
            'remark' => 'Reviewed in person',
        ])->assertRedirect();

        $item->refresh();
        $this->assertSame('verified', $item->status);
        $this->assertSame('Reviewed in person', $item->remark);
        $event = CandidateRecordEvent::query()->where('action', 'checklist_updated')->firstOrFail();
        $this->assertSame('passport_verified', $event->subject);
        $this->assertSame('pending', $event->from_value);
        $this->assertSame('verified', $event->to_value);
        $this->actingAs($company->hr)->get('/candidate-records/'.$record->id)
            ->assertInertia(fn (Assert $page) => $page->where('record.completion', 9));
    }

    public function test_expiry_dates_are_independent_and_use_the_application_timezone(): void
    {
        $company = CompanyFixture::make('Date Co', 'date-co');
        $record = $this->register($company->hr);
        $today = CandidateRegistry::today();

        $this->actingAs($company->hr)->put('/candidate-records/'.$record->id.'/expiries', [
            'work_authorization_expires_on' => $today->subDay()->toDateString(),
        ])->assertRedirect();
        $record->refresh();
        $this->assertNull($record->ead_expiry_date);
        $this->assertNull($record->passport_expiry_date);
        $this->actingAs($company->hr)->get('/candidate-records')
            ->assertInertia(fn (Assert $page) => $page
                ->where('records.rows.0.ead.label', 'Not set')
                ->where('records.rows.0.passport.label', 'Not set')
            );

        $this->actingAs($company->hr)->put('/candidate-records/'.$record->id.'/expiries', [
            'ead_expiry_date' => $today->addDays(90)->toDateString(),
            'passport_expiry_date' => $today->addDays(180)->toDateString(),
        ])->assertRedirect();
        $record->refresh();
        $this->assertSame($today->subDay()->toDateString(), $record->work_authorization_expires_on?->toDateString());
        $this->actingAs($company->hr)->get('/candidate-records/'.$record->id)
            ->assertInertia(fn (Assert $page) => $page
                ->where('record.ead.label', 'Expiring within 90 days')
                ->where('record.ead.days', 90)
                ->where('record.passport.label', 'Expiring within 180 days')
                ->where('record.passport.days', 180)
            );

        $this->actingAs($company->hr)->put('/candidate-records/'.$record->id.'/expiries', [
            'ead_expiry_date' => $today->toDateString(),
        ])->assertRedirect();
        $record->refresh();
        $this->assertSame($today->addDays(180)->toDateString(), $record->passport_expiry_date?->toDateString());
        $this->actingAs($company->hr)->get('/candidate-records/'.$record->id)
            ->assertInertia(fn (Assert $page) => $page
                ->where('record.ead.label', 'Expiring within 90 days')
                ->where('record.ead.days', 0)
                ->where('record.passport.days', 180)
            );

        $this->actingAs($company->hr)->put('/candidate-records/'.$record->id.'/expiries', [
            'ead_expiry_date' => $today->addDays(91)->toDateString(),
            'passport_expiry_date' => $today->addDays(181)->toDateString(),
        ])->assertRedirect();
        $this->actingAs($company->hr)->get('/candidate-records/'.$record->id)
            ->assertInertia(fn (Assert $page) => $page
                ->where('record.ead.label', 'Clear')
                ->where('record.passport.label', 'Clear')
            );

        $this->actingAs($company->hr)->put('/candidate-records/'.$record->id.'/expiries', [
            'ead_expiry_date' => $today->subDay()->toDateString(),
            'passport_expiry_date' => $today->subDay()->toDateString(),
        ])->assertRedirect();
        $this->actingAs($company->hr)->get('/candidate-records?expiry[]=ead_90&expiry[]=passport_180')
            ->assertInertia(fn (Assert $page) => $page->has('records.rows', 0));
        $this->actingAs($company->hr)->get('/candidate-records?expiry[]=expired_ead&expiry[]=expired_passport')
            ->assertInertia(fn (Assert $page) => $page
                ->has('records.rows', 1)
                ->where('records.rows.0.ead.label', 'Expired')
                ->where('records.rows.0.passport.label', 'Expired')
            );

        $event = CandidateRecordEvent::query()->where('action', 'expiry_changed')->where('subject', 'ead_expiry_date')->orderByDesc('id')->firstOrFail();
        $this->assertNotNull($event->from_value);
        $this->assertSame($today->subDay()->toDateString(), $event->to_value);
        $this->assertSame($company->hr->id, $event->actor_id);
    }

    public function test_alert_status_follows_the_application_timezone_rather_than_utc_when_they_differ(): void
    {
        config(['app.timezone' => 'America/New_York']);
        Carbon::setTestNow(Carbon::parse('2026-11-19 23:30:00', 'America/New_York'));
        $company = CompanyFixture::make('Zone Co', 'zone-co');
        $record = $this->register($company->hr);

        $this->actingAs($company->hr)->put('/candidate-records/'.$record->id.'/expiries', [
            'ead_expiry_date' => '2026-11-19',
        ])->assertRedirect();

        $this->actingAs($company->hr)->get('/candidate-records/'.$record->id)
            ->assertInertia(fn (Assert $page) => $page
                ->where('record.ead.label', 'Expiring within 90 days')
                ->where('record.ead.days', 0)
                ->where('record.passport.label', 'Not set')
            );
    }

    public function test_failed_registration_does_not_return_a_secret_and_rejects_a_non_alphanumeric_i94(): void
    {
        $company = CompanyFixture::make('Flash Co', 'flash-co');
        $payload = $this->payload(['first_name' => '']);

        $this->actingAs($company->hr)
            ->from('/candidate-records/create')
            ->post('/candidate-records', $payload)
            ->assertRedirect('/candidate-records/create')
            ->assertSessionHasErrors('first_name');

        $this->actingAs($company->hr)->get('/candidate-records/create')
            ->assertDontSee('123-45-6789')
            ->assertDontSee('123456789')
            ->assertDontSee('123456789012')
            ->assertDontSee('123456780')
            ->assertDontSee('I94TEST0001')
            ->assertDontSee('EADTEST0001');

        $this->actingAs($company->hr)
            ->post('/candidate-records', $this->payload(['i94_number' => 'I94-TEST']))
            ->assertSessionHasErrors('i94_number');
        $this->assertSame(0, CandidateRecord::query()->count());
    }

    public function test_passport_today_is_expiring_and_day_counts_ignore_daylight_saving(): void
    {
        config(['app.timezone' => 'America/New_York']);
        Carbon::setTestNow(Carbon::parse('2026-03-01 12:00:00', 'America/New_York'));
        $company = CompanyFixture::make('Saving Co', 'saving-co');
        $record = $this->register($company->hr);
        $today = CandidateRegistry::today();

        $this->actingAs($company->hr)->put('/candidate-records/'.$record->id.'/expiries', [
            'passport_expiry_date' => $today->toDateString(),
            'ead_expiry_date' => $today->addDays(90)->toDateString(),
            'work_authorization_expires_on' => $today->subDay()->toDateString(),
        ])->assertRedirect();

        $this->actingAs($company->hr)->get('/candidate-records/'.$record->id)
            ->assertInertia(fn (Assert $page) => $page
                ->where('record.passport.label', 'Expiring within 180 days')
                ->where('record.passport.days', 0)
                ->where('record.ead.label', 'Expiring within 90 days')
                ->where('record.ead.days', 90)
            );
    }

    public function test_expiry_filters_combine_with_or_and_search_with_and(): void
    {
        $company = CompanyFixture::make('Filter Co', 'filter-co');
        $today = CandidateRegistry::today();
        $ead = $this->register($company->hr, ['first_name' => 'Ead', 'email' => 'ead@example.test']);
        $passport = $this->register($company->hr, ['first_name' => 'Passport', 'email' => 'passport@example.test']);
        $ead->forceFill(['ead_expiry_date' => $today->subDay()->toDateString()])->save();
        $passport->forceFill(['passport_expiry_date' => $today->addDays(10)->toDateString()])->save();

        $this->actingAs($company->hr)->get('/candidate-records?expiry[]=expired_ead&expiry[]=passport_180')
            ->assertInertia(fn (Assert $page) => $page->has('records.rows', 2));
        $this->actingAs($company->hr)->get('/candidate-records?search=Passport&expiry[]=expired_ead&expiry[]=passport_180')
            ->assertInertia(fn (Assert $page) => $page
                ->has('records.rows', 1)
                ->where('records.rows.0.name', 'Passport Candidate')
            );
        $this->actingAs($company->hr)->get('/candidate-records?expiry[]=ead_90')
            ->assertInertia(fn (Assert $page) => $page->has('records.rows', 0));
    }

    public function test_only_head_of_operations_and_hr_can_manage_records_and_only_the_head_can_export(): void
    {
        $company = CompanyFixture::make('Access Co', 'access-co');
        $owner = $this->member($company, 'Owner');
        $accounts = $this->member($company, 'Accounts');
        $admin = User::factory()->create();
        $admin->forceFill(['tenant_id' => $company->id, 'is_platform_admin' => true, 'invite_accepted_at' => now()])->save();
        $record = $this->register($company->hr, ['first_name' => '=1+1']);
        $today = CandidateRegistry::today();
        $record->forceFill([
            'ead_expiry_date' => $today->subDay()->toDateString(),
            'passport_expiry_date' => $today->addDays(180)->toDateString(),
        ])->save();

        $this->actingAs($company->hr)->post('/candidate-records/'.$record->id.'/payments', $this->payment('=SUM(A1)', '15.00'))->assertRedirect();

        foreach ([$company->employee, $company->lead, $accounts, $admin] as $user) {
            $this->actingAs($user)->get('/candidate-records')->assertForbidden();
            $this->actingAs($user)->get('/candidate-records/'.$record->id)->assertForbidden();
            $this->actingAs($user)->get('/candidate-records/export')->assertForbidden();
        }

        $this->actingAs($company->hr)->get('/candidate-records/export')->assertForbidden();
        $this->actingAs($company->hr)->post('/candidate-records/'.$record->id.'/payments/'.CandidatePayment::query()->value('id').'/receipts', [
            'file' => $this->pdf('receipt.pdf'),
        ])->assertRedirect();

        $response = $this->actingAs($owner)->get('/candidate-records/export?search='.urlencode('=1+1'));
        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml.sheet', (string) $response->headers->get('content-type'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('cache-control'));
        $this->assertInstanceOf(BinaryFileResponse::class, $response->baseResponse);

        $book = IOFactory::load($response->baseResponse->getFile()->getPathname());
        $this->assertSame(['Candidates', 'Payments'], $book->getSheetNames());
        $candidates = $book->getSheetByName('Candidates');
        $payments = $book->getSheetByName('Payments');
        $this->assertNotNull($candidates);
        $this->assertNotNull($payments);
        $candidateValues = $candidates->toArray();
        $paymentValues = $payments->toArray();
        $flat = json_encode([$candidateValues, $paymentValues]);
        $this->assertIsString($flat);
        $this->assertStringContainsString('=1+1', $flat);
        $this->assertStringContainsString('=SUM(A1)', $flat);
        $this->assertStringContainsString('Expired', $flat);
        $this->assertStringContainsString('Expiring within 180 days', $flat);
        $this->assertStringContainsString('EAD alert', $flat);
        $this->assertStringContainsString('Passport alert', $flat);
        $this->assertStringContainsString('USD', $flat);
        $this->assertStringContainsString('15.00', $flat);
        $this->assertStringNotContainsString('123456789', $flat);
        $this->assertStringNotContainsString('123456789012', $flat);
        $this->assertStringNotContainsString('123456780', $flat);
        $this->assertStringNotContainsString('candidate-records/', $flat);
        $this->assertStringNotContainsString('eyJ', $flat);
        $nameCell = $candidates->getCell('B2');
        $this->assertSame('=1+1', $nameCell->getValue());
        $this->assertSame('s', $nameCell->getDataType());
    }

    public function test_other_documents_require_a_label_and_can_be_uploaded_more_than_once(): void
    {
        $company = CompanyFixture::make('Other Co', 'other-co');
        $record = $this->register($company->hr);

        $this->actingAs($company->hr)->post('/candidate-records/'.$record->id.'/documents', [
            'category' => 'other',
            'file' => $this->pdf('note.pdf'),
        ])->assertSessionHasErrors('label');

        $this->actingAs($company->hr)->post('/candidate-records/'.$record->id.'/documents', [
            'category' => 'other',
            'label' => 'Portfolio',
            'file' => $this->pdf('one.pdf'),
        ])->assertRedirect();
        $this->actingAs($company->hr)->post('/candidate-records/'.$record->id.'/documents', [
            'category' => 'other',
            'label' => 'Transcript',
            'file' => $this->pdf('two.pdf'),
        ])->assertRedirect();

        $this->assertSame(2, CandidateRecordDocument::query()->where('category', 'other')->count());
        $this->assertSame(0, CandidateChecklistItem::query()->where('item_key', 'other')->count());
    }

    public function test_profile_update_keeps_expiry_dates_secrets_and_the_payment_ledger(): void
    {
        $company = CompanyFixture::make('Edit Co', 'edit-co');
        $record = $this->register($company->hr);
        $this->actingAs($company->hr)->put('/candidate-records/'.$record->id.'/expiries', [
            'ead_expiry_date' => '2027-06-01',
            'passport_expiry_date' => '2028-06-01',
            'work_authorization_expires_on' => '2027-05-01',
        ])->assertRedirect();

        $payload = $this->payload(['first_name' => 'Updated']);
        $payload['ssn'] = '';
        $payload['account_number'] = '';
        $payload['routing_number'] = '';
        $payload['i94_number'] = '';
        $payload['ead_number'] = '';
        $payload['ead_expiry_date'] = '2020-01-01';
        $payload['passport_expiry_date'] = '2020-01-01';
        $payload['work_authorization_expires_on'] = '2020-01-01';

        $this->actingAs($company->hr)->put('/candidate-records/'.$record->id, $payload)->assertRedirect();
        $record->refresh();

        $this->assertSame('Updated', $record->first_name);
        $this->assertSame('2027-06-01', $record->ead_expiry_date?->toDateString());
        $this->assertSame('2028-06-01', $record->passport_expiry_date?->toDateString());
        $this->assertSame('2027-05-01', $record->work_authorization_expires_on?->toDateString());
        $this->assertSame('6789', $record->ssn_last4);
        $this->assertSame(0, CandidatePayment::query()->count());
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Sample',
            'last_name' => 'Candidate',
            'gender' => 'Female',
            'email' => 'sample.candidate@example.test',
            'phone' => '555-0100',
            'current_address' => '100 Test Avenue',
            'university_name' => 'Example University',
            'ssn' => '123-45-6789',
            'job_title' => 'Analyst',
            'employment_type' => 'Full-Time',
            'work_location' => 'Remote',
            'hourly_rate' => '25.50',
            'hours_per_week' => '40',
            'start_date' => '2026-12-01',
            'experience' => '2 years',
            'responsibility_1' => 'Research',
            'responsibility_2' => 'Writing',
            'responsibility_3' => 'Review',
            'responsibility_5' => 'Reporting',
            'responsibility_6' => 'Training',
            'bank_name' => 'Example Bank',
            'account_number' => '123456789012',
            'routing_number' => '123456780',
            'work_authorization' => 'OPT',
            'i94_number' => 'I94TEST0001',
            'ead_number' => 'EADTEST0001',
            'payment_received' => 'Yes',
            'stated_amount' => '250.00',
            'stated_payment_method' => 'Card',
            'work_day' => 'Monday',
        ], $overrides);
    }

    /**
     * @param  array<string, string>  $overrides
     */
    private function register(User $actor, array $overrides = []): CandidateRecord
    {
        $this->actingAs($actor)->post('/candidate-records', $this->payload($overrides))->assertRedirect();

        return CandidateRecord::query()->orderByDesc('id')->firstOrFail();
    }

    /**
     * @return array<string, string>
     */
    private function payment(string $notes, string $amount): array
    {
        return [
            'amount' => $amount,
            'currency' => 'USD',
            'paid_on' => '2026-11-01',
            'method' => 'Wire',
            'reference' => 'REF-100',
            'notes' => $notes,
        ];
    }

    private function pdf(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
    }

    private function member(CompanyFixture $company, string $role): User
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

        return $user->refresh();
    }
}
