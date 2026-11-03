<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CompanyFixture;
use Tests\TestCase;

class OfferLetterTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_template_and_separate_approval_are_required_before_issue(): void
    {
        Mail::fake();
        Storage::fake('local');
        $company = CompanyFixture::make('Acme People', 'acme-offers');
        $other = CompanyFixture::make('Other People', 'other-offers');
        $hr = $company->hr;
        $owner = $this->owner($company);
        $outsider = $this->owner($other);

        $this->actingAs($hr)->post(route('hrms.offer-letters.legal'), [
            'legal_name' => 'Acme People Private Limited',
            'legal_address' => 'Registered office, Hyderabad',
            'signatory_name' => 'Priya Shah',
            'signatory_designation' => 'Director',
            'hr_email' => 'hr@acme.test',
        ])->assertRedirect();

        $this->actingAs($hr)->post(route('hrms.offer-letters.legal.verify'), ['confirm' => 'VERIFY'])
            ->assertSessionHasErrors('legal');
        $this->actingAs($owner)->post(route('hrms.offer-letters.legal.verify'), ['confirm' => 'VERIFY'])
            ->assertRedirect();

        $this->assertSame('verified', DB::table('company_legal_profiles')->where('tenant_id', $company->id)->value('status'));
        $this->assertNotSame(
            (string) DB::table('tenants')->where('id', $company->id)->value('name'),
            (string) DB::table('company_legal_profiles')->where('tenant_id', $company->id)->value('legal_name'),
        );

        $this->actingAs($hr)->post(route('hrms.offer-letters.letterheads.store'), [
            'country' => 'india',
            'letterhead' => UploadedFile::fake()->create('evil.php', 20, 'application/x-php'),
        ])->assertSessionHasErrors('letterhead');

        $this->actingAs($hr)->post(route('hrms.offer-letters.letterheads.store'), [
            'country' => 'india',
            'letterhead' => UploadedFile::fake()->image('india.jpg', 80, 40),
            'margin_top' => 90,
        ])->assertRedirect();
        $this->actingAs($owner)->post(route('hrms.offer-letters.letterhead-confirmation'), [
            'country' => 'india',
            'confirm' => 'CONFIRM LETTERHEAD',
        ])->assertRedirect();

        $this->actingAs($hr)->post(route('hrms.offer-letters.templates.save'), [
            'country' => 'india',
            'name' => 'India offer',
            'body' => "Dear {{employee_name}}\n{{company_legal_name}}\n{{annual_ctc}}",
        ])->assertRedirect();

        $templateId = (int) DB::table('offer_letter_templates')->where('tenant_id', $company->id)->value('id');
        $versionId = (int) DB::table('offer_letter_template_versions')->where('template_id', $templateId)->value('id');
        $this->actingAs($hr)->post(route('hrms.offer-letters.templates.submit', $templateId))->assertRedirect();
        $this->actingAs($hr)->post(route('hrms.offer-letters.templates.approve', $templateId), ['confirm' => 'APPROVE'])
            ->assertSessionHasErrors('template');
        $this->actingAs($outsider)->post(route('hrms.offer-letters.templates.approve', $templateId), ['confirm' => 'APPROVE'])
            ->assertNotFound();
        $this->actingAs($owner)->post(route('hrms.offer-letters.templates.approve', $templateId), ['confirm' => 'APPROVE'])
            ->assertRedirect();
        $this->actingAs($owner)->post(route('hrms.offer-letters.templates.activate', $templateId), ['confirm' => 'ACTIVATE'])
            ->assertRedirect();

        $this->actingAs($hr)->post(route('hrms.offer-letters.store'), [
            'country' => 'india',
            'position_code' => 'opt_recruiter',
            'employee_id' => $company->reportEmployeeId,
            'monthly_salary' => '45000',
            'basic_salary' => '20000',
            'hra' => '8000',
            'special_allowance' => '5000',
            'joining_date' => '2026-04-01',
            'hr_name' => 'Meera Nair',
            'hr_signature' => 'Meera Nair',
        ])->assertRedirect();

        $letterId = (int) DB::table('offer_letters')->where('tenant_id', $company->id)->value('id');
        $this->actingAs($hr)->post(route('hrms.offer-letters.preview', $letterId))->assertRedirect();
        $preview = (string) DB::table('offer_letters')->where('id', $letterId)->value('preview_body');
        $this->assertStringContainsString('Acme People Private Limited', $preview);
        $this->assertStringNotContainsString('DRAFT — NOT APPROVED FOR ISSUANCE', $preview);

        $this->actingAs($hr)->post(route('hrms.offer-letters.issue', $letterId), ['confirm' => 'ISSUE'])
            ->assertSessionHasErrors('letter');
        $this->actingAs($hr)->post(route('hrms.offer-letters.submit', $letterId))->assertRedirect();
        $this->actingAs($hr)->post(route('hrms.offer-letters.approve', $letterId), ['confirm' => 'APPROVE'])
            ->assertSessionHasErrors('letter');
        $this->actingAs($owner)->post(route('hrms.offer-letters.approve', $letterId), ['confirm' => 'APPROVE'])
            ->assertRedirect();
        $this->actingAs($hr)->post(route('hrms.offer-letters.issue', $letterId), ['confirm' => 'ISSUE'])
            ->assertRedirect();

        $issued = DB::table('offer_letters')->where('id', $letterId)->first();
        $this->assertSame('issued', $issued->status);
        $this->assertStringStartsWith('OL-'.$company->id.'-', (string) $issued->reference_code);
        $this->assertSame($versionId, (int) $issued->template_version_id);
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($issued->file_path));

        $this->actingAs($hr)->post(route('hrms.offer-letters.templates.save'), [
            'country' => 'india',
            'name' => 'India offer',
            'body' => 'Replacement wording that must not rewrite issued letters.',
        ])->assertRedirect();

        $this->assertSame($issued->issued_body, DB::table('offer_letters')->where('id', $letterId)->value('issued_body'));
        $this->actingAs($outsider)->get(route('hrms.offer-letters.pdf', $letterId))->assertNotFound();
        $this->actingAs($owner)->get(route('hrms.offer-letters.pdf', $letterId))->assertOk();
        $this->assertSame(1, DB::table('offer_letter_downloads')->where('offer_letter_id', $letterId)->count());
        Mail::assertNothingSent();
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
