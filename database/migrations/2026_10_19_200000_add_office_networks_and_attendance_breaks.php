<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('locations') && ! Schema::hasColumn('locations', 'timezone')) {
            Schema::table('locations', function (Blueprint $table) {
                $table->string('timezone', 64)->nullable();
            });
        }

        if (Schema::hasTable('locations') && ! Schema::hasColumn('locations', 'status')) {
            Schema::table('locations', function (Blueprint $table) {
                $table->string('status', 20)->default('active');
            });
        }

        if (Schema::hasTable('locations') && Schema::hasTable('tenants') && ! Schema::hasTable('location_networks')) {
            Schema::create('location_networks', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants');
                $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
                $table->string('cidr', 64);
                $table->timestamps();
                $table->unique(['location_id', 'cidr']);
            });
        }

        if (Schema::hasTable('attendance_logs') && Schema::hasTable('locations') && ! Schema::hasColumn('attendance_logs', 'location_id')) {
            Schema::table('attendance_logs', function (Blueprint $table) {
                $table->foreignId('location_id')->nullable()->constrained('locations');
                $table->string('check_out_ip', 45)->nullable();
                $table->text('recovery_note')->nullable();
            });
        }

        if (Schema::hasTable('attendance_logs') && Schema::hasTable('tenants') && ! Schema::hasTable('attendance_breaks')) {
            Schema::create('attendance_breaks', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants');
                $table->foreignId('attendance_log_id')->constrained('attendance_logs')->cascadeOnDelete();
                $table->dateTime('started_at');
                $table->dateTime('ended_at')->nullable();
                $table->timestamps();
                $table->index(['attendance_log_id', 'ended_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_breaks');
        Schema::dropIfExists('location_networks');

        if (Schema::hasTable('attendance_logs') && Schema::hasColumn('attendance_logs', 'location_id')) {
            Schema::table('attendance_logs', function (Blueprint $table) {
                $table->dropConstrainedForeignId('location_id');
                $table->dropColumn(['check_out_ip', 'recovery_note']);
            });
        }

        if (Schema::hasTable('locations') && Schema::hasColumn('locations', 'timezone')) {
            Schema::table('locations', function (Blueprint $table) {
                $table->dropColumn(['timezone', 'status']);
            });
        }
    }
};
