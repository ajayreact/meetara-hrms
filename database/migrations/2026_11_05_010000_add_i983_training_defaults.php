<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('i983_employer_profiles')) {
            return;
        }

        if (! Schema::hasColumn('i983_employer_profiles', 'oversight')) {
            Schema::table('i983_employer_profiles', function (Blueprint $table): void {
                $table->text('oversight')->nullable();
            });
        }

        if (! Schema::hasColumn('i983_employer_profiles', 'measures')) {
            Schema::table('i983_employer_profiles', function (Blueprint $table): void {
                $table->text('measures')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('i983_employer_profiles')) {
            return;
        }

        if (Schema::hasColumn('i983_employer_profiles', 'measures')) {
            Schema::table('i983_employer_profiles', function (Blueprint $table): void {
                $table->dropColumn('measures');
            });
        }

        if (Schema::hasColumn('i983_employer_profiles', 'oversight')) {
            Schema::table('i983_employer_profiles', function (Blueprint $table): void {
                $table->dropColumn('oversight');
            });
        }
    }
};
