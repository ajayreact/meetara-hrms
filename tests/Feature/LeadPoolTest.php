<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadImportBatch;
use App\Models\User;
use App\Services\Hrms\LeadDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CompanyFixture;
use Tests\TestCase;

class LeadPoolTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_only_head_of_operations_and_hr_can_open_the_company_pool(): void
    {
        $company = CompanyFixture::make('Lead Pool Access', 'leadpool');
        $leadId = $this->lead($company, ['name' => 'Visible']);
        $owner = $this->roleUser($company, 'Owner');

        $this->actingAs($company->hr)->get('/leads')->assertOk()->assertInertia(fn (Assert $page) => $page->component('leads/index')->where('canImport', true));
        $this->actingAs($owner)->get('/leads')->assertOk()->assertInertia(fn (Assert $page) => $page->component('leads/index')->where('summary.total', 1));
        $this->actingAs($company->hr)->get("/leads/{$leadId}")->assertOk()->assertInertia(fn (Assert $page) => $page->component('leads/show')->where('lead.name', 'Visible'));

        $this->actingAs($company->lead)->get('/leads')->assertForbidden();
        $this->actingAs($company->lead)->get("/leads/{$leadId}")->assertForbidden();
        $this->actingAs($company->lead)->get('/leads?selection=matching')->assertForbidden();
        $this->actingAs($company->employee)->get('/leads')->assertForbidden();
        $this->actingAs($company->employee)->get("/leads/{$leadId}")->assertForbidden();
        $this->actingAs($this->roleUser($company, 'Accounts'))->get('/leads')->assertForbidden();
        $this->actingAs($this->roleUser($company, 'Accounts'))->get('/leads?selection=chosen&ids[]='.$leadId)->assertForbidden();
    }

    public function test_filters_pagination_and_the_unassigned_pool_stay_inside_the_company(): void
    {
        $company = CompanyFixture::make('Lead Filters', 'leadfilters');
        $other = CompanyFixture::make('Other Lead Filters', 'leadfiltersother');
        $employees = DB::table('employees')->count();
        $batch = $this->batch($company, 'Melani.xlsx');
        $ada = $this->lead($company, [
            'name' => 'Ada Lovelace',
            'email' => 'Ada@Example.com',
            'email_normalized' => 'ada@example.com',
            'phone' => '(555) 010-2000',
            'phone_digits' => '5550102000',
            'state' => 'TX',
            'university' => 'State University',
            'graduation_term' => 'MAY(2025)',
            'pipeline_status' => 'contacted',
            'import_batch_id' => $batch,
        ]);
        $this->lead($company, [
            'name' => 'Grace Hopper',
            'email' => 'grace@example.com',
            'email_normalized' => 'grace@example.com',
            'state' => 'CA',
            'university' => 'Other University',
            'graduation_term' => 'DEC(2025)',
            'pipeline_status' => 'new',
            'team_lead_employee_id' => $company->leadEmployeeId,
        ]);
        $foreign = $this->lead($other, [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'email_normalized' => 'ada@example.com',
            'phone_digits' => '5550102000',
            'state' => 'ZZ',
            'university' => 'Hidden University',
            'graduation_term' => 'MAY(2025)',
        ]);

        $this->actingAs($company->hr)->get('/leads?q=ada@example')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.total', 2)
                ->where('summary.unassigned', 1)
                ->where('summary.imported', 1)
                ->where('matching', 1)
                ->where('leads.0.id', $ada)
                ->where('leads.0.batchName', 'Melani.xlsx')
                ->where('options.states', fn ($states): bool => collect($states)->contains('TX') && ! collect($states)->contains('ZZ')));

        $this->actingAs($company->hr)->get('/leads?q=555-010')
            ->assertInertia(fn (Assert $page) => $page->where('matching', 1)->where('leads.0.id', $ada));
        $this->actingAs($company->hr)->get('/leads?state=CA&university=Other+University&term=DEC(2025)&status=new')
            ->assertInertia(fn (Assert $page) => $page->where('matching', 1)->where('leads.0.name', 'Grace Hopper')->where('leads.0.unassigned', false));
        $this->actingAs($company->hr)->get('/leads?pool=unassigned')
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.pool', 'unassigned')
                ->where('matching', 1)
                ->where('leads.0.id', $ada)
                ->where('leads.0.unassigned', true));
        $this->actingAs($company->hr)->get("/leads/{$foreign}")->assertNotFound();
        $this->actingAs($company->hr)->get("/leads/{$ada}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('lead.email', 'Ada@Example.com')
                ->where('lead.graduationTerm', 'MAY(2025)')
                ->where('lead.university', 'State University')
                ->where('batch.fileName', 'Melani.xlsx')
                ->where('batch.createdCount', 1)
                ->where('batch.uploadedBy', $company->hr->name));

        foreach (range(1, 26) as $number) {
            $this->lead($company, ['name' => sprintf('Paged %02d', $number)]);
        }

        $this->actingAs($company->hr)->get('/leads?q=Paged')
            ->assertInertia(fn (Assert $page) => $page
                ->where('matching', 26)
                ->where('page', 1)
                ->where('pages', 2)
                ->where('leads', fn ($rows): bool => count($rows) === LeadDirectory::PAGE_SIZE && collect($rows)->contains('name', 'Paged 26') && ! collect($rows)->contains('name', 'Paged 01')));
        $this->actingAs($company->hr)->get('/leads?q=Paged&page=2')
            ->assertInertia(fn (Assert $page) => $page
                ->where('page', 2)
                ->where('leads', fn ($rows): bool => count($rows) === 1 && $rows[0]['name'] === 'Paged 01'));
        $this->assertSame($employees, DB::table('employees')->count());
    }

    public function test_individual_and_bulk_selection_cannot_include_another_company(): void
    {
        $company = CompanyFixture::make('Lead Selection', 'leadselect');
        $other = CompanyFixture::make('Other Lead Selection', 'leadselectother');
        $texas = $this->lead($company, ['name' => 'Texas One', 'state' => 'TX']);
        $texasTwo = $this->lead($company, ['name' => 'Texas Two', 'state' => 'TX']);
        $california = $this->lead($company, ['name' => 'California', 'state' => 'CA']);
        $foreign = $this->lead($other, ['name' => 'Foreign Texas', 'state' => 'TX']);

        $this->actingAs($company->hr)->get('/leads?selection=chosen&ids[]='.$texas.'&ids[]='.$foreign)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('selectedIds', fn ($ids): bool => collect($ids)->all() === [$texas]));

        $this->actingAs($company->hr)->get('/leads?selection=matching&state=TX')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('selectedIds', function ($ids) use ($texas, $texasTwo, $california, $foreign): bool {
                $selected = collect($ids)->sort()->values()->all();

                return $selected === collect([$texas, $texasTwo])->sort()->values()->all()
                    && ! in_array($california, $selected, true)
                    && ! in_array($foreign, $selected, true);
            }));
    }

    private function lead(CompanyFixture $company, array $overrides): int
    {
        $lead = new Lead;
        $lead->forceFill(array_merge([
            'tenant_id' => $company->id,
            'name' => 'Candidate',
            'pipeline_status' => 'new',
            'created_by' => $company->hr->id,
        ], $overrides))->save();

        return (int) $lead->id;
    }

    private function batch(CompanyFixture $company, string $name): int
    {
        $batch = new LeadImportBatch;
        $batch->forceFill([
            'tenant_id' => $company->id,
            'original_name' => $name,
            'uploaded_by' => $company->hr->id,
            'status' => 'imported',
            'row_count' => 1,
            'created_count' => 1,
            'duplicate_count' => 0,
            'invalid_count' => 0,
        ])->save();

        return (int) $batch->id;
    }

    private function roleUser(CompanyFixture $company, string $role): User
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
