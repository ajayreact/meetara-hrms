<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadAssignment;
use App\Models\LeadImportBatch;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CompanyFixture;
use Tests\TestCase;

class LeadSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_lead_tables_are_company_scoped_and_do_not_change_employees(): void
    {
        foreach (['lead_import_batches', 'leads', 'lead_assignments', 'lead_activities'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }

        $this->assertTrue(Schema::hasColumns('leads', [
            'tenant_id',
            'import_batch_id',
            'name',
            'email_normalized',
            'phone_digits',
            'linkedin_key',
            'graduation_term',
            'experience',
            'designations',
            'pipeline_status',
            'follow_up_on',
            'remarks',
            'team_lead_employee_id',
            'assignee_employee_id',
        ]));
        $this->assertTrue(Schema::hasColumns('lead_assignments', [
            'tenant_id',
            'lead_id',
            'from_team_lead_employee_id',
            'to_team_lead_employee_id',
            'from_assignee_employee_id',
            'to_assignee_employee_id',
            'assigned_by',
        ]));
        $this->assertTrue(Schema::hasColumns('lead_activities', [
            'tenant_id',
            'lead_id',
            'actor_id',
            'type',
            'pipeline_status',
            'follow_up_on',
            'body',
        ]));
        $company = CompanyFixture::make('Veltrix Digital Solutions Inc', 'veltrix-leads');
        $other = CompanyFixture::make('Deep Data Inc', 'deep-leads');
        $batch = new LeadImportBatch;
        $batch->forceFill([
            'tenant_id' => $company->id,
            'original_name' => 'Melani Total.xlsx',
            'status' => 'committed',
            'row_count' => 1,
            'created_count' => 1,
        ])->save();

        $lead = new Lead;
        $lead->forceFill([
            'tenant_id' => $company->id,
            'import_batch_id' => $batch->id,
            'name' => 'Hitha Channegowda',
            'email_normalized' => 'hitha@example.test',
            'pipeline_status' => 'new',
            'team_lead_employee_id' => $company->leadEmployeeId,
            'assignee_employee_id' => $company->reportEmployeeId,
        ])->save();

        $assignment = new LeadAssignment;
        $assignment->forceFill([
            'tenant_id' => $company->id,
            'lead_id' => $lead->id,
            'to_team_lead_employee_id' => $company->leadEmployeeId,
            'to_assignee_employee_id' => $company->reportEmployeeId,
            'assigned_by' => $company->hr->id,
            'created_at' => now(),
        ])->save();

        $activity = new LeadActivity;
        $activity->forceFill([
            'tenant_id' => $company->id,
            'lead_id' => $lead->id,
            'actor_id' => $company->employee->id,
            'type' => 'follow_up',
            'pipeline_status' => 'contacted',
            'body' => 'Called the candidate.',
            'created_at' => now(),
        ])->save();

        $this->assertSame(1, Lead::query()->inCompany($company->id)->count());
        $this->assertSame(0, Lead::query()->inCompany($other->id)->count());
        $this->assertSame(1, Lead::query()->inCompany($company->id)->forTeamLead($company->leadEmployeeId)->count());
        $this->assertSame(0, Lead::query()->inCompany($company->id)->forTeamLead($company->reportEmployeeId)->count());
        $this->assertSame(1, Lead::query()->inCompany($company->id)->forAssignee($company->reportEmployeeId)->count());
        $this->assertSame($company->leadEmployeeId, (int) $lead->teamLead->id);
        $this->assertSame($company->reportEmployeeId, (int) $lead->assignee->id);
        $this->assertNotContains('team_lead_employee_id', Schema::getColumnListing('employees'));
        $this->assertNotContains('assignee_employee_id', Schema::getColumnListing('employees'));
        $this->assertCount(1, $lead->assignments);
        $this->assertCount(1, $lead->activities);
        $this->assertSame($batch->id, (int) $lead->importBatch->id);
        $this->assertSame((int) $company->hr->id, (int) $assignment->assignedBy->id);
        $this->assertSame((int) $company->employee->id, (int) $activity->actor->id);

        $migration = require base_path('database/migrations/2026_11_11_120000_add_lead_management_tables.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('leads'));
        $this->assertFalse(Schema::hasTable('lead_activities'));
        $this->assertTrue(Schema::hasTable('employees'));
        $migration->up();
        $this->assertTrue(Schema::hasTable('leads'));
        $this->assertNotEmpty(Schema::getForeignKeys('leads'));
        $this->assertNotEmpty(Schema::getForeignKeys('lead_assignments'));
        $this->assertNotEmpty(Schema::getForeignKeys('lead_activities'));
        $this->assertNotEmpty(Schema::getForeignKeys('lead_import_batches'));

        $relationships = require base_path('database/migrations/2026_11_11_130000_enforce_lead_tenant_relationships.php');
        $relationships->up();

        $this->assertTenantForeign('leads', 'team_lead_employee_id', 'employees');
        $this->assertTenantForeign('leads', 'assignee_employee_id', 'employees');
        $this->assertTenantForeign('leads', 'import_batch_id', 'lead_import_batches');
        $this->assertTenantForeign('leads', 'created_by', 'users');
        $this->assertTenantForeign('leads', 'updated_by', 'users');
        $this->assertTenantForeign('lead_assignments', 'lead_id', 'leads');
        $this->assertTenantForeign('lead_assignments', 'from_team_lead_employee_id', 'employees');
        $this->assertTenantForeign('lead_assignments', 'to_team_lead_employee_id', 'employees');
        $this->assertTenantForeign('lead_assignments', 'from_assignee_employee_id', 'employees');
        $this->assertTenantForeign('lead_assignments', 'to_assignee_employee_id', 'employees');
        $this->assertTenantForeign('lead_assignments', 'assigned_by', 'users');
        $this->assertTenantForeign('lead_activities', 'lead_id', 'leads');
        $this->assertTenantForeign('lead_activities', 'actor_id', 'users');
        $this->assertTenantForeign('lead_import_batches', 'uploaded_by', 'users');

        $ownBatch = new LeadImportBatch;
        $ownBatch->forceFill([
            'tenant_id' => $company->id,
            'original_name' => 'own.xlsx',
            'uploaded_by' => $company->hr->id,
            'status' => 'preview',
        ])->save();

        $foreignBatch = new LeadImportBatch;
        $foreignBatch->forceFill([
            'tenant_id' => $other->id,
            'original_name' => 'other.xlsx',
            'uploaded_by' => $other->hr->id,
            'status' => 'preview',
        ])->save();

        $ownLead = new Lead;
        $ownLead->forceFill([
            'tenant_id' => $company->id,
            'import_batch_id' => $ownBatch->id,
            'name' => 'Owned lead',
            'pipeline_status' => 'new',
            'team_lead_employee_id' => $company->leadEmployeeId,
            'assignee_employee_id' => $company->reportEmployeeId,
            'created_by' => $company->hr->id,
        ])->save();

        $poolLead = new Lead;
        $poolLead->forceFill([
            'tenant_id' => $company->id,
            'name' => 'Unassigned lead',
            'pipeline_status' => 'new',
        ])->save();

        $foreignLead = new Lead;
        $foreignLead->forceFill([
            'tenant_id' => $other->id,
            'name' => 'Other company lead',
            'pipeline_status' => 'new',
            'team_lead_employee_id' => $other->leadEmployeeId,
        ])->save();

        $this->rejectCrossTenant(fn () => (new Lead)->forceFill([
            'tenant_id' => $company->id,
            'name' => 'Wrong team lead',
            'pipeline_status' => 'new',
            'team_lead_employee_id' => $other->leadEmployeeId,
        ])->save());

        $this->rejectCrossTenant(fn () => (new Lead)->forceFill([
            'tenant_id' => $company->id,
            'name' => 'Wrong assignee',
            'pipeline_status' => 'new',
            'assignee_employee_id' => $other->reportEmployeeId,
        ])->save());

        $this->rejectCrossTenant(fn () => (new Lead)->forceFill([
            'tenant_id' => $company->id,
            'name' => 'Wrong batch',
            'pipeline_status' => 'new',
            'import_batch_id' => $foreignBatch->id,
        ])->save());

        $this->rejectCrossTenant(fn () => (new Lead)->forceFill([
            'tenant_id' => $company->id,
            'name' => 'Wrong author',
            'pipeline_status' => 'new',
            'created_by' => $other->hr->id,
        ])->save());

        $this->rejectCrossTenant(fn () => (new LeadImportBatch)->forceFill([
            'tenant_id' => $company->id,
            'original_name' => 'wrong-uploader.xlsx',
            'uploaded_by' => $other->hr->id,
            'status' => 'preview',
        ])->save());

        $this->rejectCrossTenant(fn () => (new LeadAssignment)->forceFill([
            'tenant_id' => $company->id,
            'lead_id' => $foreignLead->id,
            'to_team_lead_employee_id' => $company->leadEmployeeId,
            'assigned_by' => $company->hr->id,
            'created_at' => now(),
        ])->save());

        $this->rejectCrossTenant(fn () => (new LeadAssignment)->forceFill([
            'tenant_id' => $company->id,
            'lead_id' => $ownLead->id,
            'to_assignee_employee_id' => $other->reportEmployeeId,
            'assigned_by' => $company->hr->id,
            'created_at' => now(),
        ])->save());

        $this->rejectCrossTenant(fn () => (new LeadActivity)->forceFill([
            'tenant_id' => $company->id,
            'lead_id' => $foreignLead->id,
            'actor_id' => $company->employee->id,
            'type' => 'follow_up',
            'body' => 'Cross-tenant lead.',
            'created_at' => now(),
        ])->save());

        $this->rejectCrossTenant(fn () => (new LeadActivity)->forceFill([
            'tenant_id' => $company->id,
            'lead_id' => $ownLead->id,
            'actor_id' => $other->employee->id,
            'type' => 'follow_up',
            'body' => 'Cross-tenant actor.',
            'created_at' => now(),
        ])->save());

        $relationships->down();
        $this->assertFalse($this->tenantForeignExists('leads', 'team_lead_employee_id', 'employees'));
        $this->assertFalse($this->tenantForeignExists('lead_activities', 'actor_id', 'users'));
        $this->assertTrue(Schema::hasTable('employees'));
        $this->assertNotContains('team_lead_employee_id', Schema::getColumnListing('employees'));
        $relationships->up();
        $this->assertTenantForeign('leads', 'assignee_employee_id', 'employees');
        $this->assertTenantForeign('lead_assignments', 'lead_id', 'leads');
    }

    private function assertTenantForeign(string $table, string $column, string $references): void
    {
        $this->assertTrue(
            $this->tenantForeignExists($table, $column, $references),
            "Missing tenant relationship {$table}.{$column} -> {$references}.",
        );
    }

    private function tenantForeignExists(string $table, string $column, string $references): bool
    {
        foreach (Schema::getForeignKeys($table) as $foreign) {
            if (($foreign['columns'] ?? []) === ['tenant_id', $column]
                && ($foreign['foreign_table'] ?? '') === $references
                && ($foreign['foreign_columns'] ?? []) === ['tenant_id', 'id']) {
                return true;
            }
        }

        return false;
    }

    private function rejectCrossTenant(callable $write): void
    {
        try {
            $write();
        } catch (QueryException $exception) {
            $this->assertStringContainsStringIgnoringCase('foreign key', $exception->getMessage());

            return;
        }

        $this->fail('A cross-tenant reference was stored.');
    }
}
