<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('attendance_correction_requests')) {
            Schema::create('attendance_correction_requests', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('employee_id');
                $table->unsignedBigInteger('attendance_log_id')->nullable();
                $table->date('work_date');
                $table->string('type', 40);
                $table->dateTime('requested_check_in')->nullable();
                $table->dateTime('requested_check_out')->nullable();
                $table->text('reason');
                $table->string('status', 20)->default('pending');
                $table->unsignedBigInteger('requested_by');
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->text('review_reason')->nullable();
                $table->timestamps();
                $table->index(['tenant_id', 'status']);
                $table->index(['employee_id', 'work_date']);
            });
        }

        if (! Schema::hasTable('attendance_correction_audits')) {
            Schema::create('attendance_correction_audits', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('employee_id');
                $table->unsignedBigInteger('attendance_log_id')->nullable();
                $table->unsignedBigInteger('request_id');
                $table->dateTime('original_check_in')->nullable();
                $table->dateTime('original_check_out')->nullable();
                $table->dateTime('corrected_check_in')->nullable();
                $table->dateTime('corrected_check_out')->nullable();
                $table->unsignedSmallInteger('original_late_minutes')->nullable();
                $table->unsignedSmallInteger('corrected_late_minutes')->nullable();
                $table->unsignedBigInteger('changed_by')->nullable();
                $table->unsignedBigInteger('approved_by')->nullable();
                $table->text('employee_reason');
                $table->text('review_reason')->nullable();
                $table->string('decision', 20);
                $table->timestamp('created_at')->nullable();
                $table->index(['tenant_id', 'request_id']);
            });
        }

        if (! Schema::hasTable('holiday_work_authorizations')) {
            Schema::create('holiday_work_authorizations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('employee_id');
                $table->date('work_date');
                $table->string('kind', 20);
                $table->unsignedBigInteger('holiday_id')->nullable();
                $table->text('reason')->nullable();
                $table->unsignedBigInteger('authorized_by');
                $table->timestamps();
                $table->unique(['employee_id', 'work_date', 'kind'], 'holiday_work_employee_date_kind');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('holiday_work_authorizations');
        Schema::dropIfExists('attendance_correction_audits');
        Schema::dropIfExists('attendance_correction_requests');
    }
};
