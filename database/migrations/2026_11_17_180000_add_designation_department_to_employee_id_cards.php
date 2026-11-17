<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_id_cards')) {
            return;
        }

        Schema::table('employee_id_cards', function (Blueprint $table): void {
            if (! Schema::hasColumn('employee_id_cards', 'designation')) {
                $table->string('designation', 120)->nullable()->after('emergency_contact_number');
            }

            if (! Schema::hasColumn('employee_id_cards', 'department')) {
                $table->string('department', 120)->nullable()->after('designation');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('employee_id_cards')) {
            return;
        }

        Schema::table('employee_id_cards', function (Blueprint $table): void {
            if (Schema::hasColumn('employee_id_cards', 'department')) {
                $table->dropColumn('department');
            }

            if (Schema::hasColumn('employee_id_cards', 'designation')) {
                $table->dropColumn('designation');
            }
        });
    }
};
