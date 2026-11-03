<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('i983_documents') && ! Schema::hasColumn('i983_documents', 'source_document_id')) {
            Schema::table('i983_documents', function (Blueprint $table) {
                $table->unsignedBigInteger('source_document_id')->nullable();
            });
        }

        if (Schema::hasTable('i983_signatories')) {
            return;
        }

        Schema::create('i983_signatories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->unique();
            $table->string('signatory_name', 160);
            $table->string('signatory_title', 120);
            $table->string('image_path');
            $table->string('image_mime', 40);
            $table->unsignedInteger('image_width');
            $table->unsignedInteger('image_height');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('i983_signatories');

        if (Schema::hasTable('i983_documents') && Schema::hasColumn('i983_documents', 'source_document_id')) {
            Schema::table('i983_documents', function (Blueprint $table) {
                $table->dropColumn('source_document_id');
            });
        }
    }
};
