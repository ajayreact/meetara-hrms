<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('locations') && ! Schema::hasColumn('locations', 'map_label')) {
            Schema::table('locations', function (Blueprint $table) {
                $table->string('map_label', 120)->nullable();
            });
        }

        if (Schema::hasTable('tenants') && Schema::hasTable('users') && ! Schema::hasTable('company_attendance_settings')) {
            Schema::create('company_attendance_settings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->unique()->constrained('tenants');
                $table->boolean('gps_capture_enabled')->default(false);
                $table->string('notice_version', 40);
                $table->foreignId('updated_by')->nullable()->constrained('users');
                $table->timestamps();
            });
        }

        if (Schema::hasTable('tenants') && Schema::hasTable('users') && Schema::hasTable('company_attendance_settings') && ! Schema::hasTable('company_attendance_setting_events')) {
            Schema::create('company_attendance_setting_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants');
                $table->foreignId('actor_id')->constrained('users');
                $table->string('action', 20);
                $table->timestamp('created_at')->nullable();
                $table->index(['tenant_id', 'created_at']);
            });
        }

        if (Schema::hasTable('tenants') && Schema::hasTable('employees') && Schema::hasTable('users') && ! Schema::hasTable('gps_attendance_acknowledgements')) {
            Schema::create('gps_attendance_acknowledgements', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants');
                $table->foreignId('employee_id')->constrained('employees');
                $table->foreignId('user_id')->constrained('users');
                $table->string('notice_version', 40);
                $table->timestamp('acknowledged_at');
                $table->unique(['tenant_id', 'employee_id', 'notice_version'], 'gps_ack_employee_version');
            });
        }

        if (Schema::hasTable('tenants') && Schema::hasTable('employees') && Schema::hasTable('attendance_logs') && ! Schema::hasTable('attendance_location_records')) {
            Schema::create('attendance_location_records', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants');
                $table->foreignId('employee_id')->constrained('employees');
                $table->foreignId('attendance_log_id')->constrained('attendance_logs');
                $table->string('event_type', 20);
                $table->decimal('latitude', 10, 7)->nullable();
                $table->decimal('longitude', 10, 7)->nullable();
                $table->unsignedInteger('accuracy_meters')->nullable();
                $table->string('status', 20);
                $table->foreignId('acknowledgement_id')->nullable()->constrained('gps_attendance_acknowledgements');
                $table->timestamp('captured_at');
                $table->unique(['attendance_log_id', 'event_type'], 'attendance_location_event');
                $table->index(['tenant_id', 'employee_id', 'captured_at'], 'attendance_location_employee');
            });
        }

        if (Schema::hasTable('tenants') && Schema::hasTable('users') && ! Schema::hasTable('attendance_location_view_events')) {
            Schema::create('attendance_location_view_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants');
                $table->foreignId('actor_id')->constrained('users');
                $table->string('subject', 40);
                $table->timestamp('created_at')->nullable();
                $table->index(['tenant_id', 'created_at']);
            });
        }

        if (Schema::hasTable('tenants') && ! Schema::hasTable('attendance_location_retention_events')) {
            Schema::create('attendance_location_retention_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants');
                $table->unsignedSmallInteger('retention_days');
                $table->timestamp('cutoff_at');
                $table->unsignedInteger('deleted_count');
                $table->timestamp('created_at')->nullable();
                $table->index(['tenant_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_location_retention_events');
        Schema::dropIfExists('attendance_location_view_events');
        Schema::dropIfExists('attendance_location_records');
        Schema::dropIfExists('gps_attendance_acknowledgements');
        Schema::dropIfExists('company_attendance_setting_events');
        Schema::dropIfExists('company_attendance_settings');

        if (Schema::hasTable('locations') && Schema::hasColumn('locations', 'map_label')) {
            Schema::table('locations', function (Blueprint $table) {
                $table->dropColumn('map_label');
            });
        }
    }
};
