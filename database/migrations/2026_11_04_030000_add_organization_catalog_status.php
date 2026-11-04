<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addActiveFlag('departments');
        $this->addActiveFlag('designations');
    }

    public function down(): void
    {
        $this->dropActiveFlag('designations');
        $this->dropActiveFlag('departments');
    }

    private function addActiveFlag(string $table): void
    {
        if (! Schema::hasTable($table) || Schema::hasColumn($table, 'is_active')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->boolean('is_active')->default(true);
        });
    }

    private function dropActiveFlag(string $table): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'is_active')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->dropColumn('is_active');
        });
    }
};
