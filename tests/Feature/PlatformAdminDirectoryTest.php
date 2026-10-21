<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CompanyFixture;
use Tests\TestCase;

class PlatformAdminDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_directory_uses_saved_companies_and_hides_pay_details(): void
    {
        $company = CompanyFixture::make('Northwind', 'northwind');
        $other = CompanyFixture::make('Southwind', 'southwind');
        DB::table('tenants')->where('id', $other->id)->update(['status' => 'inactive']);
        $owner = User::factory()->create(['name' => 'North Owner']);
        $owner->forceFill([
            'tenant_id' => $company->id,
            'is_platform_admin' => false,
            'invite_accepted_at' => null,
        ])->save();
        DB::table('model_has_roles')->insert([
            'role_id' => DB::table('roles')->where('tenant_id', $company->id)->where('name', 'Owner')->value('id'),
            'model_type' => User::class,
            'model_id' => $owner->id,
            'tenant_id' => $company->id,
        ]);
        $company->hr->forceFill(['name' => 'North Hr Actor'])->save();
        DB::table('payroll_events')->insert([
            'tenant_id' => $company->id,
            'payroll_run_id' => $company->payrollRunId,
            'user_id' => $company->hr->id,
            'action' => 'reviewed',
            'note' => 'SECRET-AMOUNT-88421',
            'created_at' => now(),
        ]);
        $platform = User::factory()->create();
        $platform->forceFill(['is_platform_admin' => true, 'tenant_id' => null])->save();

        $this->actingAs($company->employee)->get('/companies')->assertInertia(fn (Assert $page) => $page->component('hrms/forbidden'));
        $this->actingAs($company->employee)->get('/audit')->assertInertia(fn (Assert $page) => $page->component('hrms/forbidden'));

        $this->actingAs($platform)->get('/companies?search=Northwind')
            ->assertOk()
            ->assertDontSee($company->reportEmail)
            ->assertInertia(fn (Assert $page) => $page
                ->where('companies', fn ($rows) => count($rows) === 1 && $rows[0]['name'] === 'Northwind' && $rows[0]['headcount'] === 3)
                ->where('companies.0.administrators', ['North Owner (invite not accepted)']));

        $this->actingAs($platform)->get('/companies?status=inactive&company='.$other->id)
            ->assertInertia(fn (Assert $page) => $page
                ->where('companies.0.name', 'Southwind')
                ->where('companies.0.status', 'Inactive')
                ->where('detail.name', 'Southwind')
                ->where('detail.administrators', []));

        $this->actingAs($platform)->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->where('platform.companies', 2)
                ->where('platform.active', 1)
                ->where('platform.inactive', 1)
                ->where('platform.employees', 6)
                ->where('platform.events', fn ($rows) => collect($rows)->contains(fn ($event) => $event['source'] === 'Payroll' && $event['action'] === 'reviewed' && ! array_key_exists('note', $event))));

        $this->actingAs($platform)->get('/audit?search=North Hr')
            ->assertOk()
            ->assertDontSee('SECRET-AMOUNT-88421')
            ->assertDontSee($company->reportEmail)
            ->assertInertia(fn (Assert $page) => $page->where('events', fn ($rows) => collect($rows)->contains(
                fn ($event) => $event['source'] === 'Payroll' && $event['action'] === 'reviewed' && $event['actor'] === 'North Hr Actor'
            )));

        $this->actingAs($platform)->get('/audit?company='.$other->id)
            ->assertDontSee('SECRET-AMOUNT-88421')
            ->assertInertia(fn (Assert $page) => $page->where('events', fn ($rows) => collect($rows)->every(
                fn ($event) => $event['company'] === 'Southwind'
            )));

        $this->actingAs($platform)->get('/company/settings')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('payrollSettings', null));

        $this->actingAs($platform)->get('/help-desk')
            ->assertInertia(fn (Assert $page) => $page->where('canOpen', false)->where('scoped', false)->where('tickets', []));
        $this->actingAs($platform)->post('/help-desk', [
            'category' => 'Facilities',
            'title' => 'Platform ticket',
            'description' => 'This description is long enough.',
            'priority' => 'low',
        ])->assertForbidden();

        $this->actingAs($company->employee)->get('/help-desk?q=southwind-ticket')
            ->assertInertia(fn (Assert $page) => $page->where('tickets', fn ($rows) => collect($rows)->pluck('title')->doesntContain('southwind-ticket')));
        $this->actingAs($company->employee)->get('/help-desk?status=resolved')
            ->assertInertia(fn (Assert $page) => $page->where('tickets', []));
        $this->actingAs($company->employee)->get('/help-desk?q=northwind-ticket')
            ->assertInertia(fn (Assert $page) => $page->where('tickets.0.title', 'northwind-ticket'));
        $this->actingAs($company->employee)->get('/help-desk?ticket='.$other->ticketId)
            ->assertInertia(fn (Assert $page) => $page->where('selected', null));
    }
}
