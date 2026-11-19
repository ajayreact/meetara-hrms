<?php

namespace Tests\Feature;

use App\Auth\CompanyRole;
use App\Models\Lead;
use App\Models\User;
use App\Services\Hrms\LeadSources;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CompanyFixture;
use Tests\TestCase;

class LeadSourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_sources_stay_with_a_lead_through_assignment_search_and_pagination(): void
    {
        $company = CompanyFixture::make('Lead Sources', 'lead-sources');
        $owner = $this->roleUser($company, 'Owner');
        $this->assertTrue(CompanyRole::permissions('company_admin')['leadsManageSources']);
        $this->assertFalse(CompanyRole::permissions('hr')['leadsManageSources']);
        $this->assertFalse(CompanyRole::permissions('team_lead')['leadsManageSources']);
        $this->assertFalse(CompanyRole::permissions('employee')['leadsManageSources']);

        foreach ([$company->hr, $company->lead, $company->employee] as $user) {
            $this->actingAs($user)->get('/leads/sources')->assertForbidden();
            $this->actingAs($user)->post('/leads/sources', ['name' => 'Referral'])->assertForbidden();
        }

        $this->actingAs($owner)->post('/leads/sources', ['name' => '  Campus   Fair  '])->assertRedirect();
        $this->actingAs($owner)->post('/leads/sources', ['name' => 'campus fair'])->assertSessionHasErrors('name');
        $sourceId = (int) DB::table('lead_sources')->where('tenant_id', $company->id)->value('id');
        $this->assertSame('Campus Fair', DB::table('lead_sources')->where('id', $sourceId)->value('name'));
        $this->assertSame(1, DB::table('lead_sources')->where('tenant_id', $company->id)->count());

        $older = $this->lead($company, 'Jordan Older', '2026-11-01 09:00:00');
        $newer = $this->lead($company, 'Avery Newer', '2026-11-18 15:00:00');
        $this->actingAs($owner)->post('/leads/'.$newer.'/source', ['source_id' => $sourceId])->assertRedirect();
        $this->actingAs($owner)->post('/leads/'.$newer.'/source', ['source_id' => $sourceId])->assertSessionHasErrors('source_id');
        $this->assertSame('Campus Fair', DB::table('leads')->where('id', $newer)->value('source_name'));

        $this->actingAs($owner)->post('/leads/sources/'.$sourceId, ['name' => 'Campus Fair 2026', 'active' => 0])->assertRedirect();
        $this->assertSame('Campus Fair', DB::table('leads')->where('id', $newer)->value('source_name'));
        $this->assertSame(0, (int) DB::table('lead_sources')->where('id', $sourceId)->value('is_active'));
        $this->actingAs($owner)->post('/leads/sources/'.$sourceId, ['name' => 'Campus Fair 2026', 'active' => 1])->assertRedirect();

        $this->actingAs($owner)->post('/leads/assignments', [
            'ids' => [$newer, $older],
            'team_lead_employee_id' => $company->leadEmployeeId,
        ])->assertRedirect();
        $this->assertSame('Campus Fair', DB::table('leads')->where('id', $newer)->value('source_name'));
        $this->assertSame($sourceId, (int) DB::table('leads')->where('id', $newer)->value('lead_source_id'));

        $this->actingAs($company->lead)->post('/my-leads/distribute', [
            'ids' => [$newer],
            'employee_id' => $company->reportEmployeeId,
        ])->assertRedirect();
        $this->actingAs($company->lead)->post('/my-leads/distribute', [
            'ids' => [$newer],
            'employee_id' => $company->reportEmployeeId,
        ])->assertOk()->assertInertia(fn (Assert $page) => $page->where('error', 'Select at least one lead that is not yet distributed.'));
        $this->assertSame(1, DB::table('lead_assignments')->where('lead_id', $newer)->where('to_assignee_employee_id', $company->reportEmployeeId)->count());
        $this->assertSame('Campus Fair', DB::table('leads')->where('id', $newer)->value('source_name'));

        $this->actingAs($owner)->get('/leads?q=ave')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('matching', 1)->where('leads.0.id', $newer)->where('leads.0.source', 'Campus Fair'));
        $this->actingAs($owner)->get('/leads?q=campus')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('matching', 1)->where('leads.0.name', 'Avery Newer'));
        $this->actingAs($owner)->get('/leads')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('leads.0.id', $newer)->where('leads.1.id', $older));
        $this->actingAs($owner)->get("/leads/{$newer}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('lead.source', 'Campus Fair')
                ->where('assignments', fn ($rows): bool => count($rows) === 2
                    && $rows[0]['actor'] !== ''
                    && $rows[0]['toAssignee'] !== ''
                    && $rows[0]['at'] !== ''));

        DB::table('leads')->where('id', $newer)->update(['pipeline_status' => 'converted']);
        $this->actingAs($owner)->get('/leads/reports')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('sources', fn ($rows): bool => collect($rows)->contains(fn ($row): bool => $row['label'] === 'Campus Fair' && (int) $row['total'] === 1 && (int) $row['converted'] === 1 && (int) $row['conversionRate'] === 100)));
        $this->actingAs($owner)->get('/leads/sources')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('sources.0.leads', 1)->where('sources.0.converted', 1));

        foreach (range(1, 11) as $number) {
            $this->lead($company, sprintf('Paged Source %02d', $number), null);
        }
        $this->actingAs($owner)->get('/leads?q=Paged+Source&per_page=10')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('matching', 11)->where('page', 1)->where('pages', 2)->where('perPage', 10)->where('leads', fn ($rows): bool => count($rows) === 10));
        $this->actingAs($owner)->get('/leads?q=Paged+Source&per_page=10&page=2')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('page', 2)->where('leads', fn ($rows): bool => count($rows) === 1));
        $this->actingAs($owner)->get('/leads?q=Paged+Source+01&per_page=10&page=2')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('page', 1)->where('matching', 1));

        $this->actingAs($company->lead)->get('/my-leads?section=total&q=campus')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('matching', 1)->where('leads.0.source', 'Campus Fair'));
        $this->actingAs($company->employee)->get('/leads')->assertForbidden();
        $this->actingAs($company->employee)->get('/leads/mine?q=Ave')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('matching', 1)->where('leads.0.id', $newer)->where('leads.0.source', 'Campus Fair'));
        $this->actingAs($company->employee)->get('/leads/mine?q=Jordan')->assertOk()->assertInertia(fn (Assert $page) => $page->where('matching', 0));
        $this->actingAs($company->employee)->get('/leads/mine/'.$older)->assertNotFound();
        $this->actingAs($company->employee)->get('/my-leads')->assertForbidden();

        $second = app(LeadSources::class)->findOrCreate($company->id, $owner->id, 'campus fair 2026');
        $this->assertSame($sourceId, $second);
        $this->assertSame(1, DB::table('lead_sources')->where('tenant_id', $company->id)->count());
    }

    private function lead(CompanyFixture $company, string $name, ?string $assignedAt): int
    {
        $lead = new Lead;
        $lead->forceFill([
            'tenant_id' => $company->id,
            'name' => $name,
            'pipeline_status' => 'new',
            'assigned_at' => $assignedAt,
            'created_by' => $company->hr->id,
        ])->save();

        return (int) $lead->id;
    }

    private function roleUser(CompanyFixture $company, string $role): User
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
