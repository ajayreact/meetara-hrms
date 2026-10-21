<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('locations') || Schema::hasColumn('locations', 'latitude')) {
            return;
        }

        Schema::table('locations', function (Blueprint $table): void {
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('verification_radius_meters')->default(150);
            $table->boolean('gps_verification_enabled')->default(false);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('locations') || ! Schema::hasColumn('locations', 'latitude')) {
            return;
        }

        Schema::table('locations', function (Blueprint $table): void {
            $table->dropColumn([
                'latitude',
                'longitude',
                'verification_radius_meters',
                'gps_verification_enabled',
            ]);
        });
    }
};
