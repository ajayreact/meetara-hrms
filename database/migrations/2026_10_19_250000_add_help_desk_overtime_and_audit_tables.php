<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tickets') && ! Schema::hasColumn('tickets', 'description')) {
            Schema::table('tickets', function (Blueprint $table) {
                $table->text('description')->nullable()->after('title');
            });
        }

        if (Schema::hasTable('tickets') && Schema::hasTable('users') && Schema::hasTable('tenants') && ! Schema::hasTable('ticket_comments')) {
            Schema::create('ticket_comments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants');
                $table->foreignId('ticket_id')->constrained('tickets');
                $table->foreignId('user_id')->constrained('users');
                $table->text('body');
                $table->timestamp('created_at')->nullable();
                $table->index(['ticket_id', 'created_at']);
            });
        }

        if (Schema::hasTable('tickets') && Schema::hasTable('users') && Schema::hasTable('tenants') && ! Schema::hasTable('ticket_events')) {
            Schema::create('ticket_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants');
                $table->foreignId('ticket_id')->constrained('tickets');
                $table->foreignId('actor_id')->constrained('users');
                $table->string('action', 40);
                $table->timestamp('created_at')->nullable();
                $table->index(['tenant_id', 'created_at']);
            });
        }

        if (Schema::hasTable('overtime_entries') && ! Schema::hasColumn('overtime_entries', 'reason')) {
            Schema::table('overtime_entries', function (Blueprint $table) {
                $table->text('reason')->nullable()->after('minutes');
            });
        }

        if (Schema::hasTable('users') && ! Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('type');
                $table->morphs('notifiable');
                $table->text('data');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('overtime_entries') && Schema::hasTable('users') && Schema::hasTable('tenants') && ! Schema::hasTable('overtime_events')) {
            Schema::create('overtime_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants');
                $table->foreignId('overtime_entry_id')->constrained('overtime_entries');
                $table->foreignId('actor_id')->constrained('users');
                $table->string('action', 40);
                $table->timestamp('created_at')->nullable();
                $table->index(['tenant_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('overtime_events');
        Schema::dropIfExists('ticket_events');
        Schema::dropIfExists('ticket_comments');
    }
};
