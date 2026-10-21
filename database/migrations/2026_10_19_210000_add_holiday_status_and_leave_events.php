<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('holidays') && ! Schema::hasColumn('holidays', 'status')) {
            Schema::table('holidays', function (Blueprint $table) {
                $table->string('status', 20)->default('active');
            });
        }

        if (Schema::hasTable('leave_requests') && Schema::hasTable('tenants') && Schema::hasTable('users') && ! Schema::hasTable('leave_request_events')) {
            Schema::create('leave_request_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants');
                $table->foreignId('leave_request_id')->constrained('leave_requests')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users');
                $table->string('action', 20);
                $table->text('note')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->index(['leave_request_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_request_events');

        if (Schema::hasTable('holidays') && Schema::hasColumn('holidays', 'status')) {
            Schema::table('holidays', function (Blueprint $table) {
                $table->dropColumn('status');
            });
        }
    }
};
