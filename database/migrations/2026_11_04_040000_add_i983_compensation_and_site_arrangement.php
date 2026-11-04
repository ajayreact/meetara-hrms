<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('i983_employers') && ! Schema::hasColumn('i983_employers', 'hourly_rate')) {
            Schema::table('i983_employers', function (Blueprint $table): void {
                $table->decimal('hourly_rate', 12, 2)->nullable()->after('opt_hours');
                $table->decimal('annual_compensation', 12, 2)->nullable()->after('salary_frequency');
            });
        }

        if (Schema::hasTable('i983_training_plans') && ! Schema::hasColumn('i983_training_plans', 'work_arrangement')) {
            Schema::table('i983_training_plans', function (Blueprint $table): void {
                $table->string('work_arrangement', 20)->nullable()->after('site_address');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('i983_training_plans') && Schema::hasColumn('i983_training_plans', 'work_arrangement')) {
            Schema::table('i983_training_plans', function (Blueprint $table): void {
                $table->dropColumn('work_arrangement');
            });
        }

        if (Schema::hasTable('i983_employers') && Schema::hasColumn('i983_employers', 'hourly_rate')) {
            Schema::table('i983_employers', function (Blueprint $table): void {
                $table->dropColumn(['hourly_rate', 'annual_compensation']);
            });
        }
    }
};
