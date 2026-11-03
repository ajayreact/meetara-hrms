<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenants') || ! Schema::hasTable('users')) {
            return;
        }

        if (! Schema::hasTable('i983_cases')) {
            Schema::create('i983_cases', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants');
                $table->string('reference', 20);
                $table->string('candidate_name', 120);
                $table->string('candidate_email', 191);
                $table->char('email_hash', 64);
                $table->string('status', 30)->default('awaiting_student');
                $table->timestamp('student_submitted_at')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users');
                $table->timestamps();
                $table->unique(['tenant_id', 'reference']);
                $table->index(['tenant_id', 'status', 'created_at']);
            });
        }

        if (! Schema::hasTable('i983_links')) {
            Schema::create('i983_links', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants');
                $table->foreignId('case_id')->constrained('i983_cases');
                $table->char('token_hash', 64)->unique();
                $table->timestamp('expires_at');
                $table->timestamp('revoked_at')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users');
                $table->timestamp('created_at')->nullable();
                $table->index(['case_id', 'expires_at']);
            });
        }

        if (! Schema::hasTable('i983_students')) {
            Schema::create('i983_students', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants');
                $table->foreignId('case_id')->unique()->constrained('i983_cases');
                $table->string('surname', 80)->nullable();
                $table->string('given_name', 80)->nullable();
                $table->string('middle_name', 80)->nullable();
                $table->string('email', 191)->nullable();
                $table->string('school_recommending', 191)->nullable();
                $table->string('school_degree', 191)->nullable();
                $table->string('sevis_school_code', 20)->nullable();
                $table->string('dso_name', 120)->nullable();
                $table->string('dso_email', 191)->nullable();
                $table->string('dso_phone', 40)->nullable();
                $table->text('sevis_id_ciphertext')->nullable();
                $table->date('opt_start')->nullable();
                $table->date('opt_end')->nullable();
                $table->string('major_cip', 160)->nullable();
                $table->string('degree_level', 80)->nullable();
                $table->date('degree_awarded_on')->nullable();
                $table->boolean('prior_degree')->nullable();
                $table->text('ead_ciphertext')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('i983_employers')) {
            Schema::create('i983_employers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants');
                $table->foreignId('case_id')->unique()->constrained('i983_cases');
                $table->string('legal_name', 191)->nullable();
                $table->string('street', 191)->nullable();
                $table->string('suite', 40)->nullable();
                $table->string('website', 191)->nullable();
                $table->string('city', 80)->nullable();
                $table->string('state', 2)->nullable();
                $table->string('zip', 10)->nullable();
                $table->text('ein_ciphertext')->nullable();
                $table->unsignedInteger('employee_count')->nullable();
                $table->string('naics', 10)->nullable();
                $table->unsignedTinyInteger('opt_hours')->nullable();
                $table->decimal('salary', 12, 2)->nullable();
                $table->string('salary_frequency', 40)->nullable();
                $table->text('other_compensation')->nullable();
                $table->date('start_date')->nullable();
                $table->timestamp('finalized_at')->nullable();
                $table->foreignId('updated_by')->nullable()->constrained('users');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('i983_training_plans')) {
            Schema::create('i983_training_plans', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants');
                $table->foreignId('case_id')->unique()->constrained('i983_cases');
                $table->string('student_name', 160)->nullable();
                $table->string('employer_name', 191)->nullable();
                $table->string('site_name', 191)->nullable();
                $table->string('site_address', 300)->nullable();
                $table->string('official_name', 120)->nullable();
                $table->string('official_title', 120)->nullable();
                $table->string('official_email', 191)->nullable();
                $table->string('official_phone', 40)->nullable();
                $table->text('student_role')->nullable();
                $table->text('goals')->nullable();
                $table->text('oversight')->nullable();
                $table->text('measures')->nullable();
                $table->text('remarks')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('i983_field_revisions')) {
            Schema::create('i983_field_revisions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants');
                $table->foreignId('case_id')->constrained('i983_cases');
                $table->string('field_name', 40);
                $table->text('previous_value')->nullable();
                $table->text('new_value')->nullable();
                $table->string('actor_type', 20);
                $table->foreignId('actor_id')->nullable()->constrained('users');
                $table->timestamp('created_at')->nullable();
                $table->index(['case_id', 'created_at']);
            });
        }

        if (! Schema::hasTable('i983_certifications')) {
            Schema::create('i983_certifications', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants');
                $table->foreignId('case_id')->constrained('i983_cases');
                $table->string('party', 20);
                $table->string('printed_name', 160)->nullable();
                $table->string('title', 120)->nullable();
                $table->date('certified_on')->nullable();
                $table->string('signature_path')->nullable();
                $table->boolean('acknowledged')->default(false);
                $table->timestamps();
                $table->unique(['case_id', 'party']);
            });
        }

        if (! Schema::hasTable('i983_evaluations')) {
            Schema::create('i983_evaluations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants');
                $table->foreignId('case_id')->constrained('i983_cases');
                $table->string('kind', 20);
                $table->date('date_from')->nullable();
                $table->date('date_to')->nullable();
                $table->text('narrative')->nullable();
                $table->date('student_signed_on')->nullable();
                $table->date('employer_signed_on')->nullable();
                $table->string('student_signature_path')->nullable();
                $table->string('employer_signature_path')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users');
                $table->timestamps();
                $table->index(['case_id', 'kind', 'completed_at']);
            });
        }

        if (! Schema::hasTable('i983_documents')) {
            Schema::create('i983_documents', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants');
                $table->foreignId('case_id')->constrained('i983_cases');
                $table->string('kind', 20);
                $table->string('file_path');
                $table->string('original_name', 191);
                $table->string('mime', 80);
                $table->unsignedInteger('size');
                $table->foreignId('created_by')->nullable()->constrained('users');
                $table->timestamp('created_at')->nullable();
                $table->index(['tenant_id', 'case_id']);
            });
        }

        if (! Schema::hasTable('i983_events')) {
            Schema::create('i983_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants');
                $table->foreignId('case_id')->nullable()->constrained('i983_cases');
                $table->foreignId('actor_id')->nullable()->constrained('users');
                $table->string('actor_type', 20);
                $table->string('action', 40);
                $table->string('subject', 80)->nullable();
                $table->timestamp('created_at')->nullable();
                $table->index(['tenant_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('i983_events');
        Schema::dropIfExists('i983_documents');
        Schema::dropIfExists('i983_evaluations');
        Schema::dropIfExists('i983_certifications');
        Schema::dropIfExists('i983_field_revisions');
        Schema::dropIfExists('i983_training_plans');
        Schema::dropIfExists('i983_employers');
        Schema::dropIfExists('i983_students');
        Schema::dropIfExists('i983_links');
        Schema::dropIfExists('i983_cases');
    }
};
