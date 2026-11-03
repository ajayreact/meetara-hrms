<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('i983_documents')) {
            return;
        }

        Schema::table('i983_documents', function (Blueprint $table) {
            if (! Schema::hasColumn('i983_documents', 'version')) {
                $table->unsignedInteger('version')->default(1);
            }

            if (! Schema::hasColumn('i983_documents', 'sha256')) {
                $table->char('sha256', 64)->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('i983_documents')) {
            return;
        }

        Schema::table('i983_documents', function (Blueprint $table) {
            if (Schema::hasColumn('i983_documents', 'sha256')) {
                $table->dropColumn('sha256');
            }

            if (Schema::hasColumn('i983_documents', 'version')) {
                $table->dropColumn('version');
            }
        });
    }
};
