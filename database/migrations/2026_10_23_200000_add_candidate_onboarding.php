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

        if (! Schema::hasTable('candidate_onboarding_forms')) {
            Schema::create('candidate_onboarding_forms', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->unique()->constrained('tenants');
                $table->char('public_token', 64)->unique();
                $table->boolean('enabled')->default(false);
                $table->string('notice_version', 40);
                $table->foreignId('updated_by')->nullable()->constrained('users');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('candidate_submissions')) {
            Schema::create('candidate_submissions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants');
                $table->foreignId('form_id')->constrained('candidate_onboarding_forms');
                $table->string('reference', 20)->unique();
                $table->string('status', 30)->default('pending_review');
                $table->string('first_name', 80);
                $table->string('last_name', 80);
                $table->string('gender', 40);
                $table->string('email', 191);
                $table->char('email_hash', 64);
                $table->text('phone_ciphertext');
                $table->char('phone_last4', 4)->nullable();
                $table->text('date_of_birth_ciphertext');
                $table->text('address_ciphertext');
                $table->string('university_name', 191);
                $table->string('job_title', 120);
                $table->string('employment_type', 80);
                $table->string('work_location', 120);
                $table->decimal('hourly_rate', 8, 2);
                $table->unsignedTinyInteger('hours_per_week');
                $table->date('start_date');
                $table->string('experience', 80);
                $table->text('responsibility_1');
                $table->text('responsibility_2')->nullable();
                $table->text('responsibility_3')->nullable();
                $table->text('responsibility_4')->nullable();
                $table->text('responsibility_5')->nullable();
                $table->text('responsibility_6')->nullable();
                $table->text('bank_name_ciphertext');
                $table->text('account_ciphertext');
                $table->char('account_last4', 4);
                $table->text('routing_ciphertext');
                $table->char('routing_last4', 4);
                $table->string('work_authorization', 80);
                $table->text('i94_ciphertext');
                $table->char('i94_last4', 4)->nullable();
                $table->text('ead_ciphertext');
                $table->char('ead_last4', 4)->nullable();
                $table->date('work_authorization_expires_on');
                $table->text('ssn_ciphertext');
                $table->char('ssn_last4', 4);
                $table->text('student_role');
                $table->text('goals');
                $table->text('oversight');
                $table->text('evaluation_methods');
                $table->string('notice_version', 40);
                $table->timestamp('consented_at');
                $table->foreignId('reviewed_by')->nullable()->constrained('users');
                $table->timestamp('reviewed_at')->nullable();
                $table->text('review_note')->nullable();
                $table->text('change_request')->nullable();
                $table->timestamps();
                $table->index(['tenant_id', 'email_hash']);
                $table->index(['tenant_id', 'status', 'created_at'], 'candidate_submissions_status');
            });
        }

        if (! Schema::hasTable('candidate_submission_documents')) {
            Schema::create('candidate_submission_documents', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants');
                $table->foreignId('submission_id')->constrained('candidate_submissions');
                $table->string('category', 40);
                $table->string('file_path');
                $table->string('original_name', 191);
                $table->string('mime', 80);
                $table->unsignedInteger('size');
                $table->timestamp('created_at')->nullable();
                $table->unique(['submission_id', 'category'], 'candidate_documents_category');
            });
        }

        if (! Schema::hasTable('candidate_submission_operations')) {
            Schema::create('candidate_submission_operations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants');
                $table->foreignId('submission_id')->unique()->constrained('candidate_submissions');
                $table->boolean('payment_received')->default(false);
                $table->decimal('amount_paid', 10, 2)->nullable();
                $table->string('payment_method', 20)->nullable();
                $table->boolean('training_agreement_sent')->default(false);
                $table->date('work_day')->nullable();
                $table->foreignId('updated_by')->nullable()->constrained('users');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('candidate_retention_audits')) {
            Schema::create('candidate_retention_audits', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('reference', 20);
                $table->unsignedBigInteger('submission_id')->nullable();
                $table->unsignedBigInteger('actor_id')->nullable();
                $table->unsignedInteger('event_count');
                $table->unsignedInteger('document_count');
                $table->boolean('files_removed')->default(false);
                $table->timestamp('created_at')->nullable();
                $table->index(['tenant_id', 'created_at']);
            });
        }

        if (! Schema::hasTable('candidate_onboarding_events')) {
            Schema::create('candidate_onboarding_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants');
                $table->foreignId('submission_id')->nullable()->constrained('candidate_submissions');
                $table->foreignId('actor_id')->nullable()->constrained('users');
                $table->string('action', 40);
                $table->string('subject', 80)->nullable();
                $table->timestamp('created_at')->nullable();
                $table->index(['tenant_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_retention_audits');
        Schema::dropIfExists('candidate_onboarding_events');
        Schema::dropIfExists('candidate_submission_operations');
        Schema::dropIfExists('candidate_submission_documents');
        Schema::dropIfExists('candidate_submissions');
        Schema::dropIfExists('candidate_onboarding_forms');
    }
};
