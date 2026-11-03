<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        if (! Schema::hasColumn('users', 'invite_expires_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('invite_expires_at')->nullable();
            });
        }

        if (! Schema::hasColumn('users', 'invite_sent_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('invite_sent_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        if (Schema::hasColumn('users', 'invite_sent_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('invite_sent_at');
            });
        }

        if (Schema::hasColumn('users', 'invite_expires_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('invite_expires_at');
            });
        }
    }
};
