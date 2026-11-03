<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employees') && ! Schema::hasColumn('employees', 'date_of_birth')) {
            Schema::table('employees', function (Blueprint $table): void {
                $table->date('date_of_birth')->nullable();
            });
        }

        if (Schema::hasTable('tenants') && Schema::hasTable('employees') && Schema::hasTable('users') && ! Schema::hasTable('followup_candidates')) {
            Schema::create('followup_candidates', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants');
                $table->foreignId('owner_employee_id')->constrained('employees');
                $table->string('name');
                $table->string('email');
                $table->string('phone')->nullable();
                $table->string('university')->nullable();
                $table->string('state')->nullable();
                $table->text('remarks')->nullable();
                $table->date('follow_up_on')->nullable();
                $table->string('status', 30)->default('new');
                $table->foreignId('created_by')->nullable()->constrained('users');
                $table->foreignId('updated_by')->nullable()->constrained('users');
                $table->timestamps();
                $table->unique(['tenant_id', 'email']);
                $table->index(['tenant_id', 'owner_employee_id', 'status']);
            });
        }

        if (Schema::hasTable('followup_candidates') && ! Schema::hasTable('followup_reminders')) {
            Schema::create('followup_reminders', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants');
                $table->foreignId('candidate_id')->constrained('followup_candidates');
                $table->dateTime('remind_at');
                $table->text('notes')->nullable();
                $table->string('status', 20)->default('open');
                $table->foreignId('created_by')->nullable()->constrained('users');
                $table->foreignId('completed_by')->nullable()->constrained('users');
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
                $table->index(['tenant_id', 'status', 'remind_at']);
            });
        }

        if (Schema::hasTable('followup_candidates') && ! Schema::hasTable('followup_events')) {
            Schema::create('followup_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants');
                $table->foreignId('candidate_id')->constrained('followup_candidates');
                $table->foreignId('actor_id')->constrained('users');
                $table->string('action', 40);
                $table->text('note')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->index(['tenant_id', 'candidate_id']);
            });
        }

        if (Schema::hasTable('tenants') && Schema::hasTable('employees') && Schema::hasTable('users') && ! Schema::hasTable('work_tasks')) {
            Schema::create('work_tasks', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants');
                $table->foreignId('assignee_employee_id')->constrained('employees');
                $table->string('title');
                $table->text('description')->nullable();
                $table->string('priority', 20)->default('medium');
                $table->date('start_on')->nullable();
                $table->date('due_on')->nullable();
                $table->date('expected_on')->nullable();
                $table->text('remarks')->nullable();
                $table->string('status', 20)->default('assigned');
                $table->foreignId('created_by')->nullable()->constrained('users');
                $table->foreignId('assigned_by')->nullable()->constrained('users');
                $table->foreignId('completed_by')->nullable()->constrained('users');
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
                $table->index(['tenant_id', 'assignee_employee_id', 'status']);
                $table->index(['tenant_id', 'due_on']);
            });
        }

        if (Schema::hasTable('work_tasks') && ! Schema::hasTable('work_task_events')) {
            Schema::create('work_task_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants');
                $table->foreignId('task_id')->constrained('work_tasks');
                $table->foreignId('actor_id')->constrained('users');
                $table->string('action', 40);
                $table->text('note')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->index(['tenant_id', 'task_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('work_task_events');
        Schema::dropIfExists('work_tasks');
        Schema::dropIfExists('followup_events');
        Schema::dropIfExists('followup_reminders');
        Schema::dropIfExists('followup_candidates');

        if (Schema::hasTable('employees') && Schema::hasColumn('employees', 'date_of_birth')) {
            Schema::table('employees', function (Blueprint $table): void {
                $table->dropColumn('date_of_birth');
            });
        }
    }
};
