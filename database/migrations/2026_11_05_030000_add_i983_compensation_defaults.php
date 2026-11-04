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

        $columns = [
            'opt_hours' => fn (Blueprint $table) => $table->unsignedTinyInteger('opt_hours')->nullable(),
            'hourly_rate' => fn (Blueprint $table) => $table->decimal('hourly_rate', 12, 2)->nullable(),
            'salary_frequency' => fn (Blueprint $table) => $table->string('salary_frequency', 40)->nullable(),
            'annual_compensation' => fn (Blueprint $table) => $table->decimal('annual_compensation', 12, 2)->nullable(),
        ];

        foreach ($columns as $name => $define) {
            if (! Schema::hasColumn('i983_employer_profiles', $name)) {
                Schema::table('i983_employer_profiles', $define);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('i983_employer_profiles')) {
            return;
        }

        foreach (['annual_compensation', 'salary_frequency', 'hourly_rate', 'opt_hours'] as $column) {
            if (Schema::hasColumn('i983_employer_profiles', $column)) {
                Schema::table('i983_employer_profiles', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
