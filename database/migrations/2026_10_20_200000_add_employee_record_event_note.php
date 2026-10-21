<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_record_events') || Schema::hasColumn('employee_record_events', 'note')) {
            return;
        }

        Schema::table('employee_record_events', function (Blueprint $table): void {
            $table->text('note')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('employee_record_events') || ! Schema::hasColumn('employee_record_events', 'note')) {
            return;
        }

        Schema::table('employee_record_events', function (Blueprint $table): void {
            $table->dropColumn('note');
        });
    }
};
