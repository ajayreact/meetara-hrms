<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('candidate_records')) {
            Schema::create('candidate_records', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('reference', 16);
                $table->string('first_name', 80);
                $table->string('last_name', 80);
                $table->string('gender', 20);
                $table->string('email', 191);
                $table->string('phone', 30);
                $table->date('date_of_birth')->nullable();
                $table->text('current_address');
                $table->string('university_name', 191);
                $table->text('ssn_ciphertext');
                $table->char('ssn_last4', 4);
                $table->string('job_title', 120);
                $table->string('employment_type', 20);
                $table->string('work_location', 20);
                $table->decimal('hourly_rate', 8, 2);
                $table->unsignedSmallInteger('hours_per_week');
                $table->date('start_date');
                $table->string('experience', 80);
                $table->text('responsibility_1');
                $table->text('responsibility_2');
                $table->text('responsibility_3');
                $table->text('responsibility_4')->nullable();
                $table->text('responsibility_5');
                $table->text('responsibility_6');
                $table->string('bank_name', 80);
                $table->text('account_ciphertext');
                $table->char('account_last4', 4);
                $table->text('routing_ciphertext');
                $table->char('routing_last4', 4);
                $table->string('work_authorization', 20);
                $table->text('i94_ciphertext');
                $table->char('i94_last4', 4);
                $table->text('ead_number_ciphertext');
                $table->char('ead_last4', 4);
                $table->date('work_authorization_expires_on')->nullable();
                $table->text('student_role')->nullable();
                $table->text('goals')->nullable();
                $table->text('oversight')->nullable();
                $table->text('evaluation_methods')->nullable();
                $table->string('payment_received', 3);
                $table->decimal('stated_amount', 12, 2);
                $table->string('stated_payment_method', 80);
                $table->string('training_agreement_sent', 3)->nullable();
                $table->string('work_day', 20);
                $table->date('ead_expiry_date')->nullable();
                $table->date('passport_expiry_date')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
                $table->unique('reference', 'candidate_records_reference_unique');
                $table->index(['tenant_id', 'ead_expiry_date'], 'candidate_records_tenant_ead_index');
                $table->index(['tenant_id', 'passport_expiry_date'], 'candidate_records_tenant_passport_index');
            });
            $this->foreign('candidate_records', 'tenant_id', 'tenants');
            $this->foreign('candidate_records', 'created_by', 'users');
            $this->foreign('candidate_records', 'updated_by', 'users');
        }

        if (! Schema::hasTable('candidate_record_documents')) {
            Schema::create('candidate_record_documents', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('candidate_record_id');
                $table->string('category', 40);
                $table->string('label', 80)->nullable();
                $table->string('original_name', 180);
                $table->string('storage_path', 255);
                $table->string('mime', 80);
                $table->unsignedInteger('size');
                $table->unsignedBigInteger('uploaded_by')->nullable();
                $table->timestamps();
                $table->index(['tenant_id', 'candidate_record_id'], 'candidate_record_documents_tenant_record_index');
            });
            $this->foreign('candidate_record_documents', 'tenant_id', 'tenants');
            $this->foreign('candidate_record_documents', 'candidate_record_id', 'candidate_records');
            $this->foreign('candidate_record_documents', 'uploaded_by', 'users');
        }

        if (! Schema::hasTable('candidate_payments')) {
            Schema::create('candidate_payments', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('candidate_record_id');
                $table->decimal('amount', 12, 2);
                $table->char('currency', 3)->default('USD');
                $table->date('paid_on');
                $table->string('method', 80);
                $table->string('reference', 80)->nullable();
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('recorded_by')->nullable();
                $table->timestamps();
                $table->index(['tenant_id', 'candidate_record_id'], 'candidate_payments_tenant_record_index');
            });
            $this->foreign('candidate_payments', 'tenant_id', 'tenants');
            $this->foreign('candidate_payments', 'candidate_record_id', 'candidate_records');
            $this->foreign('candidate_payments', 'recorded_by', 'users');
        }

        if (! Schema::hasTable('candidate_payment_receipts')) {
            Schema::create('candidate_payment_receipts', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('candidate_payment_id');
                $table->string('original_name', 180);
                $table->string('storage_path', 255);
                $table->string('mime', 80);
                $table->unsignedInteger('size');
                $table->unsignedBigInteger('uploaded_by')->nullable();
                $table->timestamps();
                $table->index(['tenant_id', 'candidate_payment_id'], 'candidate_payment_receipts_tenant_payment_index');
            });
            $this->foreign('candidate_payment_receipts', 'tenant_id', 'tenants');
            $this->foreign('candidate_payment_receipts', 'candidate_payment_id', 'candidate_payments');
            $this->foreign('candidate_payment_receipts', 'uploaded_by', 'users');
        }

        if (! Schema::hasTable('candidate_checklist_items')) {
            Schema::create('candidate_checklist_items', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('candidate_record_id');
                $table->string('item_key', 40);
                $table->string('status', 20);
                $table->text('remark')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
                $table->unique(['candidate_record_id', 'item_key'], 'candidate_checklist_items_record_key_unique');
                $table->index(['tenant_id', 'candidate_record_id'], 'candidate_checklist_items_tenant_record_index');
            });
            $this->foreign('candidate_checklist_items', 'tenant_id', 'tenants');
            $this->foreign('candidate_checklist_items', 'candidate_record_id', 'candidate_records');
            $this->foreign('candidate_checklist_items', 'updated_by', 'users');
        }

        if (! Schema::hasTable('candidate_record_events')) {
            Schema::create('candidate_record_events', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('candidate_record_id');
                $table->unsignedBigInteger('actor_id')->nullable();
                $table->string('action', 40);
                $table->string('subject', 80)->nullable();
                $table->string('from_value', 40)->nullable();
                $table->string('to_value', 40)->nullable();
                $table->text('note')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->index(['tenant_id', 'candidate_record_id'], 'candidate_record_events_tenant_record_index');
            });
            $this->foreign('candidate_record_events', 'tenant_id', 'tenants');
            $this->foreign('candidate_record_events', 'candidate_record_id', 'candidate_records');
            $this->foreign('candidate_record_events', 'actor_id', 'users');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_record_events');
        Schema::dropIfExists('candidate_checklist_items');
        Schema::dropIfExists('candidate_payment_receipts');
        Schema::dropIfExists('candidate_payments');
        Schema::dropIfExists('candidate_record_documents');
        Schema::dropIfExists('candidate_records');
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
