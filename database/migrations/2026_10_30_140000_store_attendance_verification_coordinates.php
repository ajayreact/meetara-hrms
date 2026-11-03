<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('attendance_logs') || Schema::hasColumn('attendance_logs', 'check_in_latitude')) {
            return;
        }

        Schema::table('attendance_logs', function (Blueprint $table): void {
            $table->decimal('check_in_latitude', 10, 7)->nullable();
            $table->decimal('check_in_longitude', 10, 7)->nullable();
            $table->unsignedInteger('check_in_distance_meters')->nullable();
            $table->decimal('check_out_latitude', 10, 7)->nullable();
            $table->decimal('check_out_longitude', 10, 7)->nullable();
            $table->unsignedInteger('check_out_distance_meters')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('attendance_logs') || ! Schema::hasColumn('attendance_logs', 'check_in_latitude')) {
            return;
        }

        Schema::table('attendance_logs', function (Blueprint $table): void {
            $table->dropColumn([
                'check_in_latitude',
                'check_in_longitude',
                'check_in_distance_meters',
                'check_out_latitude',
                'check_out_longitude',
                'check_out_distance_meters',
            ]);
        });
    }
};
