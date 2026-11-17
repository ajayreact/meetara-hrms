<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CompanyFixture;
use Tests\TestCase;

class IdCardCompanyProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_hr_and_owner_can_save_and_edit_india_and_usa_profiles_independently(): void
    {
        $this->withoutVite();
        Storage::fake('local');
        $company = CompanyFixture::make('ID Card Settings Co', 'idcard-settings');
        $owner = $this->member($company, 'Owner');
        $legalBefore = DB::table('company_legal_profiles')->count();
        $employeeSnapshot = DB::table('employees')->where('id', $company->reportEmployeeId)->first();

        $this->actingAs($company->hr)->get('/id-cards/settings')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('id-cards/settings')
            ->where('country', 'india')
            ->where('profile.id', null)
            ->where('preview.displayName', '')
        );

        $this->actingAs($company->hr)->post('/id-cards/settings', $this->profile('india', [
            'legal_name' => 'Veltrix India Pvt Ltd',
            'display_name' => 'Veltrix India',
            'footer_address' => 'Hyderabad, India',
        ]))->assertRedirect('/id-cards/settings?country=india');

        $india = DB::table('id_card_company_profiles')
            ->where('tenant_id', $company->id)
            ->where('country', 'india')
            ->first();
        $this->assertNotNull($india);
        $this->assertSame('Veltrix India Pvt Ltd', $india->legal_name);
        $this->assertSame('Veltrix India', $india->display_name);
        $this->assertSame('Hyderabad, India', $india->footer_address);
        $this->assertSame($company->hr->id, (int) $india->updated_by);

        $this->actingAs($owner)->post('/id-cards/settings', $this->profile('usa', [
            'legal_name' => 'Veltrix Digital Solutions Inc',
            'display_name' => 'Veltrix USA',
            'office_address' => 'Farmington, MI',
            'contact_number' => '+1-248-000-0000',
            'email' => 'usa@veltrix.test',
            'website' => 'https://veltrixds.com',
            'footer_address' => 'Farmington Hills, MI, USA',
        ]))->assertRedirect('/id-cards/settings?country=usa');

        $usa = DB::table('id_card_company_profiles')
            ->where('tenant_id', $company->id)
            ->where('country', 'usa')
            ->first();
        $this->assertNotNull($usa);
        $this->assertSame('Veltrix USA', $usa->display_name);
        $this->assertSame('Farmington Hills, MI, USA', $usa->footer_address);
        $this->assertSame($owner->id, (int) $usa->updated_by);

        $this->actingAs($company->hr)->post('/id-cards/settings', $this->profile('india', [
            'legal_name' => 'Veltrix India Pvt Ltd',
            'display_name' => 'Veltrix India Updated',
            'footer_address' => 'Updated Hyderabad footer',
        ]))->assertRedirect('/id-cards/settings?country=india');

        $india = DB::table('id_card_company_profiles')->where('id', $india->id)->first();
        $usa = DB::table('id_card_company_profiles')->where('id', $usa->id)->first();
        $this->assertSame('Veltrix India Updated', $india->display_name);
        $this->assertSame('Updated Hyderabad footer', $india->footer_address);
        $this->assertSame('Veltrix USA', $usa->display_name);
        $this->assertSame('Farmington Hills, MI, USA', $usa->footer_address);
        $this->assertSame(2, DB::table('id_card_company_profiles')->where('tenant_id', $company->id)->count());

        $this->actingAs($company->hr)->get('/id-cards/settings?country=usa')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('id-cards/settings')
            ->where('country', 'usa')
            ->where('profile.displayName', 'Veltrix USA')
            ->where('preview.footerAddress', 'Farmington Hills, MI, USA')
            ->where('preview.hasLogo', false)
        );

        $this->assertSame($legalBefore, DB::table('company_legal_profiles')->count());
        $this->assertEquals($employeeSnapshot, DB::table('employees')->where('id', $company->reportEmployeeId)->first());
    }

    public function test_logo_upload_is_private_tenant_scoped_and_servable_only_to_managers(): void
    {
        $this->withoutVite();
        Storage::fake('local');
        $company = CompanyFixture::make('ID Card Logo Co', 'idcard-logo');
        $other = CompanyFixture::make('Other ID Card Logo Co', 'other-idcard-logo');

        $this->actingAs($company->hr)->post('/id-cards/settings', array_merge($this->profile('india'), [
            'logo' => UploadedFile::fake()->image('logo.png', 120, 120),
        ]))->assertRedirect('/id-cards/settings?country=india');

        $row = DB::table('id_card_company_profiles')
            ->where('tenant_id', $company->id)
            ->where('country', 'india')
            ->first();
        $this->assertNotNull($row);
        $this->assertNotNull($row->logo_path);
        $this->assertStringStartsWith('id-cards/'.$company->id.'/logos/', (string) $row->logo_path);
        $this->assertSame('image/png', $row->logo_mime);
        Storage::disk('local')->assertExists((string) $row->logo_path);

        $this->actingAs($company->hr)->get('/id-cards/settings')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('profile.hasLogo', true)
            ->where('profile.logoUrl', '/id-cards/settings/logo?country=india')
            ->where('preview.hasLogo', true)
        );

        $logo = $this->actingAs($company->hr)->get('/id-cards/settings/logo?country=india');
        $logo->assertOk();
        $logo->assertHeader('Cache-Control', 'no-store, private');
        $logo->assertHeader('Content-Type', 'image/png');

        $this->actingAs($other->hr)->get('/id-cards/settings/logo?country=india')->assertNotFound();
        $this->actingAs($company->employee)->get('/id-cards/settings/logo?country=india')->assertForbidden();
        $this->actingAs($company->lead)->get('/id-cards/settings/logo?country=india')->assertForbidden();

        $previous = (string) $row->logo_path;
        $this->actingAs($company->hr)->post('/id-cards/settings', array_merge($this->profile('india', [
            'display_name' => 'Veltrix India Relogo',
        ]), [
            'logo' => UploadedFile::fake()->image('replacement.jpg', 100, 100),
        ]))->assertRedirect();

        $updated = DB::table('id_card_company_profiles')->where('id', $row->id)->first();
        $this->assertNotSame($previous, (string) $updated->logo_path);
        $this->assertStringStartsWith('id-cards/'.$company->id.'/logos/', (string) $updated->logo_path);
        Storage::disk('local')->assertMissing($previous);
        Storage::disk('local')->assertExists((string) $updated->logo_path);
        $this->assertSame('image/jpeg', $updated->logo_mime);
    }

    public function test_validation_rejects_missing_fields_and_bad_logo(): void
    {
        $this->withoutVite();
        Storage::fake('local');
        $company = CompanyFixture::make('ID Card Validation Co', 'idcard-validation');

        $this->from('/id-cards/settings')
            ->actingAs($company->hr)
            ->post('/id-cards/settings', [
                'country' => 'india',
                'legal_name' => '',
                'display_name' => '',
                'office_address' => '',
                'footer_address' => '',
                'email' => 'not-an-email',
            ])
            ->assertSessionHasErrors(['legal_name', 'display_name', 'office_address', 'footer_address', 'email']);

        $this->from('/id-cards/settings')
            ->actingAs($company->hr)
            ->post('/id-cards/settings', array_merge($this->profile('canada'), [
                'logo' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
            ]))
            ->assertSessionHasErrors(['country', 'logo']);

        $this->assertSame(0, DB::table('id_card_company_profiles')->where('tenant_id', $company->id)->count());
    }

    public function test_permissions_and_tenant_isolation_block_cross_company_access(): void
    {
        $this->withoutVite();
        Storage::fake('local');
        $company = CompanyFixture::make('ID Card Access Co', 'idcard-access');
        $other = CompanyFixture::make('Other ID Card Access Co', 'other-idcard-access');
        $accounts = $this->accounts($company);

        $this->actingAs($company->hr)->post('/id-cards/settings', $this->profile('india', [
            'display_name' => 'Private India Brand',
            'footer_address' => 'Secret footer',
        ]))->assertRedirect();

        foreach ([$company->employee, $company->lead, $accounts] as $user) {
            $this->actingAs($user)->get('/id-cards/settings')->assertForbidden();
            $this->actingAs($user)->post('/id-cards/settings', $this->profile('india'))->assertForbidden();
        }

        $this->actingAs($other->hr)->get('/id-cards/settings')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('profile.id', null)
            ->where('profile.displayName', '')
            ->where('preview.footerAddress', '')
        );
        $this->assertSame(0, DB::table('id_card_company_profiles')->where('tenant_id', $other->id)->count());
        $this->assertSame(1, DB::table('id_card_company_profiles')->where('tenant_id', $company->id)->count());

        $foreign = DB::table('id_card_company_profiles')->where('tenant_id', $company->id)->first();
        DB::table('id_card_company_profiles')->insert([
            'tenant_id' => $other->id,
            'country' => 'india',
            'legal_name' => 'Other Legal',
            'display_name' => 'Other Display',
            'office_address' => 'Other Office',
            'footer_address' => 'Other Footer',
            'logo_path' => $foreign->logo_path ?? ('id-cards/'.$company->id.'/logos/stolen.png'),
            'logo_mime' => 'image/png',
            'status' => 'active',
            'updated_by' => $other->hr->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Storage::disk('local')->put('id-cards/'.$company->id.'/logos/stolen.png', 'png-bytes');

        $this->actingAs($other->hr)->get('/id-cards/settings/logo?country=india')->assertNotFound();
        $this->actingAs($other->hr)->get('/id-cards/settings')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('profile.hasLogo', false)
            ->where('profile.displayName', 'Other Display')
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function profile(string $country = 'india', array $overrides = []): array
    {
        return array_merge([
            'country' => $country,
            'legal_name' => 'Veltrix Digital Solutions Private Limited',
            'display_name' => 'Veltrix',
            'office_address' => 'Sample office address',
            'contact_number' => '+91-40-00000000',
            'email' => 'hr@veltrix.test',
            'website' => 'https://veltrix.test',
            'footer_address' => 'Sample footer address',
        ], $overrides);
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
}
