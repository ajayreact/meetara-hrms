<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('i983_employer_profiles')) {
            return;
        }

        $columns = [
            'site_name' => fn (Blueprint $table) => $table->string('site_name', 191)->nullable(),
            'site_address' => fn (Blueprint $table) => $table->string('site_address', 300)->nullable(),
            'work_arrangement' => fn (Blueprint $table) => $table->string('work_arrangement', 20)->nullable(),
            'official_email' => fn (Blueprint $table) => $table->string('official_email', 191)->nullable(),
            'official_phone' => fn (Blueprint $table) => $table->string('official_phone', 40)->nullable(),
            'student_role' => fn (Blueprint $table) => $table->text('student_role')->nullable(),
            'goals' => fn (Blueprint $table) => $table->text('goals')->nullable(),
        ];

        foreach ($columns as $name => $define) {
            if (! Schema::hasColumn('i983_employer_profiles', $name)) {
                Schema::table('i983_employer_profiles', $define);
            }
        }

        if (Schema::hasTable('i983_signatories')) {
            DB::table('i983_signatories')->where('signatory_name', 'Apali S')->update([
                'signatory_title' => 'HR Manager & Operations Manager',
            ]);
        }

        if (Schema::hasTable('i983_training_plans')) {
            DB::table('i983_training_plans')->where('official_name', 'Apali S')->update([
                'official_title' => 'HR Manager & Operations Manager',
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('i983_employer_profiles')) {
            return;
        }

        foreach (['goals', 'student_role', 'official_phone', 'official_email', 'work_arrangement', 'site_address', 'site_name'] as $column) {
            if (Schema::hasColumn('i983_employer_profiles', $column)) {
                Schema::table('i983_employer_profiles', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
