<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenants') || ! Schema::hasTable('users')) {
            if ($this->incompleteHistoryAlreadySkippedLifecycle()) {
                return;
            }

            throw new \RuntimeException(
                '2026_10_20_140000_add_company_admin_invitations requires the tenants and users tables.'
            );
        }

        if (! Schema::hasTable('company_lifecycle_events')) {
            throw new \RuntimeException(
                '2026_10_20_140000_add_company_admin_invitations requires company_lifecycle_events. Apply 2026_10_20_120000_add_company_lifecycle_events first.'
            );
        }

        if (! Schema::hasColumn('company_lifecycle_events', 'subject_email')) {
            Schema::table('company_lifecycle_events', function (Blueprint $table) {
                $table->string('subject_email', 191)->nullable();
            });
        }

        if (! Schema::hasColumn('company_lifecycle_events', 'subject_email')) {
            throw new \RuntimeException(
                '2026_10_20_140000_add_company_admin_invitations requires company_lifecycle_events.subject_email before company_admin_invitations can be created.'
            );
        }

        if (! Schema::hasTable('company_admin_invitations')) {
            Schema::create('company_admin_invitations', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants');
                $table->string('email', 150);
                $table->string('token_hash', 64)->nullable()->unique();
                $table->foreignId('invited_by')->constrained('users');
                $table->string('role_name', 40);
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('accepted_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->foreignId('accepted_user_id')->nullable()->constrained('users');
                $table->string('active_key', 191)->nullable()->unique();
                $table->string('company_open_key', 20)->nullable()->unique();
                $table->timestamps();
                $table->index(['tenant_id', 'email']);
            });
        }

        if (! Schema::hasTable('company_admin_invitations') || ! Schema::hasColumn('company_lifecycle_events', 'subject_email')) {
            throw new \RuntimeException(
                '2026_10_20_140000_add_company_admin_invitations did not leave company_admin_invitations and company_lifecycle_events.subject_email in place.'
            );
        }
    }

    private function incompleteHistoryAlreadySkippedLifecycle(): bool
    {
        // The PHP tree has no migration that creates tenants. SQLite migrate records
        // 120000 as a no-op, then reaches this file. Leave the invitation table absent.
        $repository = app('migrator')->getRepository();

        if (! $repository->repositoryExists()) {
            return false;
        }

        return in_array('2026_10_20_120000_add_company_lifecycle_events', $repository->getRan(), true)
            && ! Schema::hasTable('company_lifecycle_events');
    }

    public function down(): void
    {
        Schema::dropIfExists('company_admin_invitations');

        if (Schema::hasTable('company_lifecycle_events') && Schema::hasColumn('company_lifecycle_events', 'subject_email')) {
            Schema::table('company_lifecycle_events', function (Blueprint $table) {
                $table->dropColumn('subject_email');
            });
        }
    }
};
