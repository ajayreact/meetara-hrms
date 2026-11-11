<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('lead_import_batches')) {
            Schema::create('lead_import_batches', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('original_name');
                $table->string('stored_path')->nullable();
                $table->unsignedBigInteger('uploaded_by')->nullable();
                $table->string('status', 20)->default('preview');
                $table->unsignedInteger('row_count')->default(0);
                $table->unsignedInteger('created_count')->default(0);
                $table->unsignedInteger('duplicate_count')->default(0);
                $table->unsignedInteger('invalid_count')->default(0);
                $table->timestamps();
                $table->index(['tenant_id', 'status'], 'lead_import_batches_tenant_status_index');
            });
            $this->foreign('lead_import_batches', 'tenant_id', 'tenants');
            $this->foreign('lead_import_batches', 'uploaded_by', 'users');
        }

        if (! Schema::hasTable('leads')) {
            Schema::create('leads', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('import_batch_id')->nullable();
                $table->unsignedInteger('source_row')->nullable();
                $table->date('imported_on')->nullable();
                $table->string('name');
                $table->string('email')->nullable();
                $table->string('email_normalized')->nullable();
                $table->string('phone')->nullable();
                $table->string('phone_digits', 20)->nullable();
                $table->string('linkedin_url')->nullable();
                $table->string('linkedin_key')->nullable();
                $table->string('state')->nullable();
                $table->string('university')->nullable();
                $table->string('graduation_term')->nullable();
                $table->string('experience', 20)->nullable();
                $table->text('designations')->nullable();
                $table->string('pipeline_status', 40)->default('new');
                $table->date('follow_up_on')->nullable();
                $table->text('remarks')->nullable();
                $table->unsignedBigInteger('team_lead_employee_id')->nullable();
                $table->unsignedBigInteger('assignee_employee_id')->nullable();
                $table->timestamp('assigned_at')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
                $table->index(['tenant_id', 'pipeline_status'], 'leads_tenant_status_index');
                $table->index(['tenant_id', 'team_lead_employee_id'], 'leads_tenant_team_lead_index');
                $table->index(['tenant_id', 'assignee_employee_id'], 'leads_tenant_assignee_index');
                $table->index(['tenant_id', 'email_normalized'], 'leads_tenant_email_index');
                $table->index(['tenant_id', 'phone_digits'], 'leads_tenant_phone_index');
                $table->index(['tenant_id', 'linkedin_key'], 'leads_tenant_linkedin_index');
                $table->index(['tenant_id', 'follow_up_on'], 'leads_tenant_follow_up_index');
            });
            $this->foreign('leads', 'tenant_id', 'tenants');
            $this->foreign('leads', 'import_batch_id', 'lead_import_batches');
            $this->foreign('leads', 'team_lead_employee_id', 'employees');
            $this->foreign('leads', 'assignee_employee_id', 'employees');
            $this->foreign('leads', 'created_by', 'users');
            $this->foreign('leads', 'updated_by', 'users');
        }

        if (! Schema::hasTable('lead_assignments')) {
            Schema::create('lead_assignments', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('lead_id');
                $table->unsignedBigInteger('from_team_lead_employee_id')->nullable();
                $table->unsignedBigInteger('to_team_lead_employee_id')->nullable();
                $table->unsignedBigInteger('from_assignee_employee_id')->nullable();
                $table->unsignedBigInteger('to_assignee_employee_id')->nullable();
                $table->unsignedBigInteger('assigned_by');
                $table->text('note')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->index(['tenant_id', 'lead_id'], 'lead_assignments_tenant_lead_index');
            });
            $this->foreign('lead_assignments', 'tenant_id', 'tenants');
            $this->foreign('lead_assignments', 'lead_id', 'leads');
            $this->foreign('lead_assignments', 'from_team_lead_employee_id', 'employees');
            $this->foreign('lead_assignments', 'to_team_lead_employee_id', 'employees');
            $this->foreign('lead_assignments', 'from_assignee_employee_id', 'employees');
            $this->foreign('lead_assignments', 'to_assignee_employee_id', 'employees');
            $this->foreign('lead_assignments', 'assigned_by', 'users');
        }

        if (! Schema::hasTable('lead_activities')) {
            Schema::create('lead_activities', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('lead_id');
                $table->unsignedBigInteger('actor_id');
                $table->string('type', 40);
                $table->string('pipeline_status', 40)->nullable();
                $table->date('follow_up_on')->nullable();
                $table->text('body')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->index(['tenant_id', 'lead_id'], 'lead_activities_tenant_lead_index');
            });
            $this->foreign('lead_activities', 'tenant_id', 'tenants');
            $this->foreign('lead_activities', 'lead_id', 'leads');
            $this->foreign('lead_activities', 'actor_id', 'users');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_activities');
        Schema::dropIfExists('lead_assignments');
        Schema::dropIfExists('leads');
        Schema::dropIfExists('lead_import_batches');
    }

    private function foreign(string $table, string $column, string $references): void
    {
        if (! Schema::hasTable($references) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column, $references): void {
            $blueprint->foreign($column)->references('id')->on($references);
        });
    }
};
