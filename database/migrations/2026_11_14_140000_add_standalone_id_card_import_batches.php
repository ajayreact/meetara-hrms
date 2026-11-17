<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('standalone_id_card_import_batches')) {
            Schema::create('standalone_id_card_import_batches', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('original_name');
                $table->string('stored_path');
                $table->unsignedBigInteger('uploaded_by')->nullable();
                $table->string('status', 20)->default('preview');
                $table->unsignedInteger('row_count')->default(0);
                $table->unsignedInteger('created_count')->default(0);
                $table->unsignedInteger('duplicate_count')->default(0);
                $table->unsignedInteger('failed_count')->default(0);
                $table->timestamps();
                $table->index(['tenant_id', 'status'], 'standalone_id_card_import_batches_tenant_status_index');
                $table->index(['tenant_id', 'created_at'], 'standalone_id_card_import_batches_tenant_created_index');
            });
            $this->foreign('standalone_id_card_import_batches', 'tenant_id', 'tenants');
            $this->foreign('standalone_id_card_import_batches', 'uploaded_by', 'users');
        }

        if (Schema::hasTable('standalone_id_cards') && ! Schema::hasColumn('standalone_id_cards', 'import_batch_id')) {
            Schema::table('standalone_id_cards', function (Blueprint $table): void {
                $table->unsignedBigInteger('import_batch_id')->nullable()->after('updated_by');
                $table->index(['tenant_id', 'import_batch_id'], 'standalone_id_cards_tenant_import_batch_index');
            });
            $this->foreign('standalone_id_cards', 'import_batch_id', 'standalone_id_card_import_batches');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('standalone_id_cards') && Schema::hasColumn('standalone_id_cards', 'import_batch_id')) {
            Schema::table('standalone_id_cards', function (Blueprint $table): void {
                $table->dropForeign(['import_batch_id']);
                $table->dropIndex('standalone_id_cards_tenant_import_batch_index');
                $table->dropColumn('import_batch_id');
            });
        }

        Schema::dropIfExists('standalone_id_card_import_batches');
    }

    private function foreign(string $table, string $column, string $references): void
    {
        if (! Schema::hasTable($references) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column, $references): void {
            $blueprint->foreign($column)->references('id')->on($references);
        });
    }
};
