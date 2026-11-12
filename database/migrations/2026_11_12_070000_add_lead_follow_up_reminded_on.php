<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('leads') || Schema::hasColumn('leads', 'follow_up_reminded_on')) {
            return;
        }

        Schema::table('leads', function (Blueprint $table): void {
            $table->date('follow_up_reminded_on')->nullable()->after('follow_up_on');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('leads') || ! Schema::hasColumn('leads', 'follow_up_reminded_on')) {
            return;
        }

        Schema::table('leads', function (Blueprint $table): void {
            $table->dropColumn('follow_up_reminded_on');
        });
    }
};
