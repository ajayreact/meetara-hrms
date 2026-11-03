<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('offer_letters') || Schema::hasColumn('offer_letters', 'notice_days')) {
            return;
        }

        Schema::table('offer_letters', function (Blueprint $table) {
            $table->unsignedSmallInteger('notice_days')->nullable();
            $table->date('date_of_issue')->nullable();
            $table->string('designation_name')->nullable();
            $table->string('reporting_manager_name')->nullable();
            $table->string('employee_phone', 40)->nullable();
            $table->text('offer_notes')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('offer_letters') || ! Schema::hasColumn('offer_letters', 'notice_days')) {
            return;
        }

        Schema::table('offer_letters', function (Blueprint $table) {
            $table->dropColumn([
                'notice_days',
                'date_of_issue',
                'designation_name',
                'reporting_manager_name',
                'employee_phone',
                'offer_notes',
            ]);
        });
    }
};
