<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('roles') && Schema::hasTable('users') && Schema::hasTable('tenants') && ! Schema::hasTable('role_assignment_events')) {
            Schema::create('role_assignment_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants');
                $table->foreignId('actor_id')->constrained('users');
                $table->foreignId('subject_user_id')->nullable()->constrained('users');
                $table->string('role_name', 40);
                $table->string('action', 20);
                $table->timestamp('created_at')->nullable();
                $table->index(['tenant_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('role_assignment_events');
    }
};
