<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class HrmsSchema
{
    public static function install(): void
    {
        if (! Schema::hasColumn('users', 'tenant_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedBigInteger('tenant_id')->nullable();
                $table->boolean('is_platform_admin')->default(false);
                $table->string('invite_token', 80)->nullable();
                $table->timestamp('invited_at')->nullable();
                $table->timestamp('invite_accepted_at')->nullable();
            });
        }

        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'invite_expires_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('invite_expires_at')->nullable();
                $table->timestamp('invite_sent_at')->nullable();
            });
        }

        if (! Schema::hasTable('tenants')) {
            Schema::create('tenants', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('slug')->nullable();
                $table->string('timezone')->default('Asia/Kolkata');
                $table->string('status')->default('active');
                $table->boolean('offer_approval_required')->default(true);
                $table->timestamps();
            });
        }

        if (Schema::hasTable('tenants') && ! Schema::hasColumn('tenants', 'offer_approval_required')) {
            Schema::table('tenants', function (Blueprint $table) {
                $table->boolean('offer_approval_required')->default(true);
            });
        }

        foreach ([
            'departments' => true,
            'designations' => false,
            'locations' => false,
        ] as $table => $withParent) {
            if (! Schema::hasTable($table)) {
                Schema::create($table, function (Blueprint $blueprint) use ($withParent) {
                    $blueprint->id();
                    $blueprint->unsignedBigInteger('tenant_id');
                    $blueprint->string('name');
                    if ($withParent) {
                        $blueprint->unsignedBigInteger('parent_id')->nullable();
                    }
                    if ($blueprint->getTable() === 'locations') {
                        $blueprint->text('address')->nullable();
                        $blueprint->text('ip_address')->nullable();
                    }
                    $blueprint->timestamps();
                });
            }
        }

        if (Schema::hasTable('locations') && ! Schema::hasColumn('locations', 'latitude')) {
            Schema::table('locations', function (Blueprint $table) {
                $table->decimal('latitude', 10, 7)->nullable();
                $table->decimal('longitude', 10, 7)->nullable();
                $table->unsignedInteger('verification_radius_meters')->default(150);
                $table->boolean('gps_verification_enabled')->default(false);
            });
        }

        if (! Schema::hasTable('roles')) {
            Schema::create('roles', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable();
                $table->string('name');
                $table->string('guard_name');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('model_has_roles')) {
            Schema::create('model_has_roles', function (Blueprint $table) {
                $table->unsignedBigInteger('role_id');
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
                $table->unsignedBigInteger('tenant_id');
                $table->primary(['role_id', 'model_type', 'model_id', 'tenant_id']);
            });
        }

        if (! Schema::hasTable('employees')) {
            Schema::create('employees', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('employee_code');
                $table->string('first_name');
                $table->string('last_name');
                $table->string('email');
                $table->string('phone')->nullable();
                $table->unsignedBigInteger('department_id')->nullable();
                $table->unsignedBigInteger('designation_id')->nullable();
                $table->unsignedBigInteger('location_id')->nullable();
                $table->unsignedBigInteger('manager_id')->nullable();
                $table->unsignedBigInteger('shift_id')->nullable();
                $table->string('employment_type')->default('full_time');
                $table->string('work_mode')->default('office');
                $table->string('holiday_calendar', 10)->default('india');
                $table->date('date_of_joining');
                $table->string('status')->default('invited');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('attendance_logs')) {
            Schema::create('attendance_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('employee_id');
                $table->unsignedBigInteger('location_id')->nullable();
                $table->date('work_date');
                $table->dateTime('check_in')->nullable();
                $table->dateTime('check_out')->nullable();
                $table->string('work_mode')->default('office');
                $table->string('check_in_ip', 45)->nullable();
                $table->string('check_out_ip', 45)->nullable();
                $table->decimal('check_in_latitude', 10, 7)->nullable();
                $table->decimal('check_in_longitude', 10, 7)->nullable();
                $table->unsignedInteger('check_in_distance_meters')->nullable();
                $table->decimal('check_out_latitude', 10, 7)->nullable();
                $table->decimal('check_out_longitude', 10, 7)->nullable();
                $table->unsignedInteger('check_out_distance_meters')->nullable();
                $table->string('verified_as', 20)->nullable();
                $table->string('status')->default('present');
                $table->unsignedSmallInteger('minutes')->nullable();
                $table->unsignedSmallInteger('late_minutes')->default(0);
                $table->string('payroll_status')->default('pending');
                $table->text('recovery_note')->nullable();
                $table->timestamps();
                $table->unique(['employee_id', 'work_date']);
            });
        }

        if (Schema::hasTable('attendance_logs') && ! Schema::hasColumn('attendance_logs', 'check_in_latitude')) {
            Schema::table('attendance_logs', function (Blueprint $table) {
                $table->decimal('check_in_latitude', 10, 7)->nullable();
                $table->decimal('check_in_longitude', 10, 7)->nullable();
                $table->unsignedInteger('check_in_distance_meters')->nullable();
                $table->decimal('check_out_latitude', 10, 7)->nullable();
                $table->decimal('check_out_longitude', 10, 7)->nullable();
                $table->unsignedInteger('check_out_distance_meters')->nullable();
            });
        }

        if (! Schema::hasTable('holidays')) {
            Schema::create('holidays', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('location_id')->nullable();
                $table->string('calendar', 10)->default('india');
                $table->string('name');
                $table->date('date');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('leave_requests')) {
            Schema::create('leave_requests', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('employee_id');
                $table->date('start_date');
                $table->date('end_date');
                $table->decimal('days', 5, 1)->default(1);
                $table->string('status')->default('pending');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('tickets')) {
            Schema::create('tickets', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('employee_id');
                $table->unsignedBigInteger('assignee_id')->nullable();
                $table->string('category');
                $table->string('title');
                $table->text('description')->nullable();
                $table->string('priority')->default('medium');
                $table->string('status')->default('open');
                $table->string('attachment_path')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('tickets') && ! Schema::hasColumn('tickets', 'description')) {
            Schema::table('tickets', function (Blueprint $table) {
                $table->text('description')->nullable();
                $table->unsignedBigInteger('assignee_id')->nullable();
            });
        }

        if (! Schema::hasTable('ticket_comments')) {
            Schema::create('ticket_comments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('ticket_id');
                $table->unsignedBigInteger('user_id');
                $table->text('body');
                $table->timestamp('created_at')->nullable();
            });
        }

        if (! Schema::hasTable('overtime_entries')) {
            Schema::create('overtime_entries', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('employee_id');
                $table->unsignedBigInteger('attendance_log_id')->nullable();
                $table->date('work_date');
                $table->unsignedSmallInteger('minutes');
                $table->text('reason')->nullable();
                $table->string('status')->default('pending');
                $table->unsignedBigInteger('approved_by')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->string('payroll_status')->default('pending');
                $table->timestamps();
            });
        }

        if (Schema::hasTable('overtime_entries') && ! Schema::hasColumn('overtime_entries', 'reason')) {
            Schema::table('overtime_entries', function (Blueprint $table) {
                $table->text('reason')->nullable();
            });
        }

        if (! Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('type');
                $table->string('notifiable_type');
                $table->unsignedBigInteger('notifiable_id');
                $table->text('data');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('overtime_events')) {
            Schema::create('overtime_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('overtime_entry_id');
                $table->unsignedBigInteger('actor_id');
                $table->string('action', 40);
                $table->timestamp('created_at')->nullable();
            });
        }

        if (! Schema::hasTable('ticket_events')) {
            Schema::create('ticket_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('ticket_id');
                $table->unsignedBigInteger('actor_id');
                $table->string('action', 40);
                $table->timestamp('created_at')->nullable();
            });
        }

        if (! Schema::hasTable('payroll_runs')) {
            Schema::create('payroll_runs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedSmallInteger('year');
                $table->unsignedTinyInteger('month');
                $table->string('status')->default('draft');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('payroll_items')) {
            Schema::create('payroll_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('payroll_run_id');
                $table->unsignedBigInteger('employee_id');
                $table->decimal('net', 12, 2)->default(0);
                $table->timestamps();
            });
        }

        if (Schema::hasTable('tenants') && ! Schema::hasColumn('tenants', 'week_off')) {
            Schema::table('tenants', function (Blueprint $table) {
                $table->json('week_off')->nullable();
            });
        }

        if (Schema::hasTable('tenants') && ! Schema::hasColumn('tenants', 'email')) {
            Schema::table('tenants', function (Blueprint $table) {
                $table->string('email')->nullable();
                $table->string('phone')->nullable();
                $table->text('address')->nullable();
            });
        }

        if (Schema::hasTable('tenants') && Schema::hasTable('users') && ! Schema::hasTable('company_lifecycle_events')) {
            Schema::create('company_lifecycle_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('actor_id');
                $table->string('action', 20);
                $table->string('subject_email', 191)->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }

        if (Schema::hasTable('company_lifecycle_events') && ! Schema::hasColumn('company_lifecycle_events', 'subject_email')) {
            Schema::table('company_lifecycle_events', function (Blueprint $table) {
                $table->string('subject_email', 191)->nullable();
            });
        }

        if (Schema::hasTable('tenants') && Schema::hasTable('users') && ! Schema::hasTable('company_admin_invitations')) {
            Schema::create('company_admin_invitations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('email', 150);
                $table->string('token_hash', 64)->nullable()->unique();
                $table->unsignedBigInteger('invited_by');
                $table->string('role_name', 40);
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('accepted_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->unsignedBigInteger('accepted_user_id')->nullable();
                $table->string('active_key', 191)->nullable()->unique();
                $table->string('company_open_key', 20)->nullable()->unique();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('locations') && ! Schema::hasColumn('locations', 'timezone')) {
            Schema::table('locations', function (Blueprint $table) {
                $table->string('timezone', 64)->nullable();
                $table->string('status', 20)->default('active');
            });
        }

        if (Schema::hasTable('locations') && ! Schema::hasTable('location_networks')) {
            Schema::create('location_networks', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('location_id');
                $table->string('cidr', 64);
                $table->timestamps();
                $table->unique(['location_id', 'cidr']);
            });
        }

        if (! Schema::hasTable('attendance_breaks')) {
            Schema::create('attendance_breaks', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('attendance_log_id');
                $table->dateTime('started_at');
                $table->dateTime('ended_at')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('holidays') && ! Schema::hasColumn('holidays', 'status')) {
            Schema::table('holidays', function (Blueprint $table) {
                $table->string('status', 20)->default('active');
            });
        }

        if (Schema::hasTable('leave_requests') && ! Schema::hasColumn('leave_requests', 'leave_type_id')) {
            Schema::table('leave_requests', function (Blueprint $table) {
                $table->unsignedBigInteger('leave_type_id')->nullable();
                $table->text('reason')->nullable();
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->text('reviewer_note')->nullable();
                $table->string('payroll_status')->default('pending');
            });
        }

        if (! Schema::hasTable('leave_types')) {
            Schema::create('leave_types', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('name');
                $table->string('code');
                $table->decimal('days_per_year', 5, 1)->default(0);
                $table->boolean('is_paid')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('leave_policies')) {
            Schema::create('leave_policies', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('leave_type_id');
                $table->string('name');
                $table->string('accrual')->default('yearly');
                $table->unsignedSmallInteger('applicable_after_days')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('leave_balances')) {
            Schema::create('leave_balances', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('employee_id');
                $table->unsignedBigInteger('leave_type_id');
                $table->unsignedSmallInteger('year');
                $table->decimal('allocated', 5, 1)->default(0);
                $table->decimal('used', 5, 1)->default(0);
                $table->decimal('pending', 5, 1)->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('leave_request_events')) {
            Schema::create('leave_request_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('leave_request_id');
                $table->unsignedBigInteger('user_id');
                $table->string('action', 20);
                $table->text('note')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }

        if (Schema::hasTable('salary_structures') === false) {
            Schema::create('salary_structures', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('employee_id');
                $table->decimal('basic', 12, 2);
                $table->decimal('hra', 12, 2)->default(0);
                $table->decimal('special_allowance', 12, 2)->default(0);
                $table->date('effective_from');
                $table->char('currency', 3)->nullable();
                $table->timestamps();
                $table->unique(['employee_id', 'effective_from']);
            });
        }

        if (Schema::hasTable('payroll_runs') && ! Schema::hasColumn('payroll_runs', 'warnings')) {
            Schema::table('payroll_runs', function (Blueprint $table) {
                $table->unsignedBigInteger('calculated_by')->nullable();
                $table->timestamp('calculated_at')->nullable();
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->text('review_note')->nullable();
                $table->unsignedBigInteger('approved_by')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->unsignedBigInteger('processed_by')->nullable();
                $table->timestamp('processed_at')->nullable();
                $table->json('warnings')->nullable();
                $table->unique(['tenant_id', 'year', 'month']);
            });
        }

        if (Schema::hasTable('payroll_items') && ! Schema::hasColumn('payroll_items', 'basic')) {
            Schema::table('payroll_items', function (Blueprint $table) {
                $table->char('currency', 3)->nullable();
                $table->decimal('basic', 12, 2)->default(0);
                $table->decimal('hra', 12, 2)->default(0);
                $table->decimal('special_allowance', 12, 2)->default(0);
                $table->decimal('working_days', 5, 1)->default(0);
                $table->decimal('present_days', 5, 1)->default(0);
                $table->decimal('leave_days', 5, 1)->default(0);
                $table->decimal('wfh_days', 5, 1)->default(0);
                $table->decimal('lop_days', 5, 1)->default(0);
                $table->decimal('overtime_amount', 12, 2)->default(0);
                $table->decimal('reimbursement', 12, 2)->default(0);
                $table->decimal('perk_amount', 12, 2)->default(0);
                $table->decimal('gratuity_amount', 12, 2)->default(0);
                $table->decimal('lop_amount', 12, 2)->default(0);
                $table->decimal('pf_employee', 12, 2)->default(0);
                $table->decimal('pf_employer', 12, 2)->default(0);
                $table->decimal('esi_employee', 12, 2)->default(0);
                $table->decimal('esi_employer', 12, 2)->default(0);
                $table->decimal('lwf', 12, 2)->default(0);
                $table->decimal('tds', 12, 2)->default(0);
                $table->decimal('loan_recovery', 12, 2)->default(0);
                $table->decimal('gross', 12, 2)->default(0);
                $table->decimal('deductions', 12, 2)->default(0);
                $table->string('payroll_status')->default('draft');
                $table->unique(['payroll_run_id', 'employee_id']);
            });
        }

        if (! Schema::hasTable('salary_structure_events')) {
            Schema::create('salary_structure_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('salary_structure_id');
                $table->unsignedBigInteger('user_id');
                $table->string('action', 40);
                $table->date('effective_from');
                $table->timestamp('created_at')->nullable();
            });
        }

        if (! Schema::hasTable('payroll_events')) {
            Schema::create('payroll_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('payroll_run_id');
                $table->unsignedBigInteger('user_id');
                $table->string('action', 40);
                $table->text('note')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }

        if (! Schema::hasTable('payroll_payments')) {
            Schema::create('payroll_payments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('payroll_item_id')->unique();
                $table->decimal('amount', 12, 2);
                $table->string('status', 20)->default('pending');
                $table->date('paid_on')->nullable();
                $table->string('method', 40)->nullable();
                $table->string('reference', 80)->nullable();
                $table->text('note')->nullable();
                $table->unsignedBigInteger('recorded_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('company_payroll_settings')) {
            Schema::create('company_payroll_settings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->unique();
                $table->char('currency', 3)->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('company_payroll_setting_events')) {
            Schema::create('company_payroll_setting_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('actor_id');
                $table->string('action', 20);
                $table->timestamp('created_at')->nullable();
            });
        }

        if (! Schema::hasTable('role_assignment_events')) {
            Schema::create('role_assignment_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('actor_id');
                $table->unsignedBigInteger('subject_user_id')->nullable();
                $table->string('role_name', 40);
                $table->string('action', 20);
                $table->timestamp('created_at')->nullable();
            });
        }

        if (! Schema::hasTable('onboardings')) {
            Schema::create('onboardings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('employee_id')->unique();
                $table->unsignedBigInteger('onboarding_template_id')->nullable();
                $table->string('status', 40)->default('pending');
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('welcomed_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('onboarding_tasks')) {
            Schema::create('onboarding_tasks', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('onboarding_id');
                $table->string('code', 40)->nullable();
                $table->string('title');
                $table->text('description')->nullable();
                $table->string('assignee_role')->default('HR');
                $table->date('due_date')->nullable();
                $table->unsignedBigInteger('completed_by')->nullable();
                $table->string('status', 40)->default('pending');
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
                $table->unique(['onboarding_id', 'code']);
            });
        }

        if (! Schema::hasTable('employee_documents')) {
            Schema::create('employee_documents', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('employee_id');
                $table->string('category', 40);
                $table->string('title');
                $table->string('file_path');
                $table->string('original_name');
                $table->string('reference', 80)->nullable();
                $table->text('description')->nullable();
                $table->string('review_status', 40)->default('pending');
                $table->unsignedBigInteger('uploaded_by')->nullable();
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->text('review_note')->nullable();
                $table->unsignedBigInteger('replaces_document_id')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('employee_documents') && ! Schema::hasColumn('employee_documents', 'reference')) {
            Schema::table('employee_documents', function (Blueprint $table) {
                $table->string('reference', 80)->nullable();
                $table->text('description')->nullable();
            });
        }

        if (! Schema::hasTable('document_category_settings')) {
            Schema::create('document_category_settings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('category_key', 40);
                $table->string('name', 80);
                $table->string('group_key', 30);
                $table->boolean('is_required')->default(false);
                $table->boolean('is_active')->default(true);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
                $table->unique(['tenant_id', 'category_key']);
            });
        }

        if (! Schema::hasTable('employee_record_events')) {
            Schema::create('employee_record_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('employee_id');
                $table->unsignedBigInteger('actor_id');
                $table->string('action', 40);
                $table->string('subject', 40)->nullable();
                $table->text('note')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }

        if (! Schema::hasTable('offer_letters')) {
            Schema::create('offer_letters', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('employee_id')->nullable();
                $table->string('position_code', 40);
                $table->string('employee_name');
                $table->decimal('monthly_salary', 12, 2);
                $table->decimal('annual_ctc', 12, 2);
                $table->date('joining_date');
                $table->unsignedTinyInteger('notice_months')->default(3);
                $table->string('hr_name');
                $table->string('hr_signature');
                $table->string('status', 20)->default('draft');
                $table->unsignedInteger('version')->default(1);
                $table->text('preview_body')->nullable();
                $table->text('issued_body')->nullable();
                $table->text('issued_values')->nullable();
                $table->timestamp('issued_at')->nullable();
                $table->unsignedBigInteger('issued_by')->nullable();
                $table->string('file_path')->nullable();
                $table->unsignedBigInteger('replaces_letter_id')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('locations') && ! Schema::hasColumn('locations', 'map_label')) {
            Schema::table('locations', function (Blueprint $table) {
                $table->string('map_label', 120)->nullable();
            });
        }

        if (Schema::hasTable('tenants') && ! Schema::hasTable('company_attendance_settings')) {
            Schema::create('company_attendance_settings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->unique();
                $table->boolean('gps_capture_enabled')->default(false);
                $table->string('notice_version', 40);
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('company_attendance_setting_events')) {
            Schema::create('company_attendance_setting_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('actor_id');
                $table->string('action', 20);
                $table->timestamp('created_at')->nullable();
            });
        }

        if (Schema::hasTable('employees') && ! Schema::hasTable('gps_attendance_acknowledgements')) {
            Schema::create('gps_attendance_acknowledgements', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('employee_id');
                $table->unsignedBigInteger('user_id');
                $table->string('notice_version', 40);
                $table->timestamp('acknowledged_at');
                $table->unique(['tenant_id', 'employee_id', 'notice_version'], 'gps_ack_employee_version');
            });
        }

        if (Schema::hasTable('attendance_logs') && ! Schema::hasTable('attendance_location_records')) {
            Schema::create('attendance_location_records', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('employee_id');
                $table->unsignedBigInteger('attendance_log_id');
                $table->string('event_type', 20);
                $table->decimal('latitude', 10, 7)->nullable();
                $table->decimal('longitude', 10, 7)->nullable();
                $table->unsignedInteger('accuracy_meters')->nullable();
                $table->string('status', 20);
                $table->unsignedBigInteger('acknowledgement_id')->nullable();
                $table->timestamp('captured_at');
                $table->unique(['attendance_log_id', 'event_type'], 'attendance_location_event');
            });
        }

        if (! Schema::hasTable('attendance_location_view_events')) {
            Schema::create('attendance_location_view_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('actor_id');
                $table->string('subject', 40);
                $table->timestamp('created_at')->nullable();
            });
        }

        if (! Schema::hasTable('attendance_location_retention_events')) {
            Schema::create('attendance_location_retention_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedSmallInteger('retention_days');
                $table->timestamp('cutoff_at');
                $table->unsignedInteger('deleted_count');
                $table->timestamp('created_at')->nullable();
            });
        }

        if (! Schema::hasTable('shifts')) {
            Schema::create('shifts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('name');
                $table->string('type')->default('fixed');
                $table->time('start_time')->nullable();
                $table->time('end_time')->nullable();
                $table->unsignedSmallInteger('expected_minutes')->nullable();
                $table->unsignedSmallInteger('grace_minutes')->default(10);
                $table->boolean('is_default')->default(false);
                $table->timestamps();
            });
        }

        if (Schema::hasTable('tenants') && ! Schema::hasTable('candidate_onboarding_forms')) {
            Schema::create('candidate_onboarding_forms', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->unique();
                $table->char('public_token', 64)->unique();
                $table->boolean('enabled')->default(false);
                $table->string('notice_version', 40);
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('candidate_submissions')) {
            Schema::create('candidate_submissions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('form_id');
                $table->string('reference', 20)->unique();
                $table->string('status', 30)->default('pending_review');
                $table->string('first_name', 80);
                $table->string('last_name', 80);
                $table->string('gender', 40);
                $table->string('email', 191);
                $table->char('email_hash', 64);
                $table->text('phone_ciphertext');
                $table->char('phone_last4', 4)->nullable();
                $table->text('date_of_birth_ciphertext');
                $table->text('address_ciphertext');
                $table->string('university_name', 191);
                $table->string('job_title', 120);
                $table->string('employment_type', 80);
                $table->string('work_location', 120);
                $table->decimal('hourly_rate', 8, 2);
                $table->unsignedTinyInteger('hours_per_week');
                $table->date('start_date');
                $table->string('experience', 80);
                $table->text('responsibility_1');
                $table->text('responsibility_2')->nullable();
                $table->text('responsibility_3')->nullable();
                $table->text('responsibility_4')->nullable();
                $table->text('responsibility_5')->nullable();
                $table->text('responsibility_6')->nullable();
                $table->text('bank_name_ciphertext');
                $table->text('account_ciphertext');
                $table->char('account_last4', 4);
                $table->text('routing_ciphertext');
                $table->char('routing_last4', 4);
                $table->string('work_authorization', 80);
                $table->text('i94_ciphertext');
                $table->char('i94_last4', 4)->nullable();
                $table->text('ead_ciphertext');
                $table->char('ead_last4', 4)->nullable();
                $table->date('work_authorization_expires_on');
                $table->text('ssn_ciphertext');
                $table->char('ssn_last4', 4);
                $table->text('student_role');
                $table->text('goals');
                $table->text('oversight');
                $table->text('evaluation_methods');
                $table->string('notice_version', 40);
                $table->timestamp('consented_at');
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->text('review_note')->nullable();
                $table->text('change_request')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('candidate_submission_documents')) {
            Schema::create('candidate_submission_documents', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('submission_id');
                $table->string('category', 40);
                $table->string('file_path');
                $table->string('original_name', 191);
                $table->string('mime', 80);
                $table->unsignedInteger('size');
                $table->timestamp('created_at')->nullable();
                $table->unique(['submission_id', 'category'], 'candidate_documents_category');
            });
        }

        if (! Schema::hasTable('candidate_submission_operations')) {
            Schema::create('candidate_submission_operations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('submission_id')->unique();
                $table->boolean('payment_received')->default(false);
                $table->decimal('amount_paid', 10, 2)->nullable();
                $table->string('payment_method', 20)->nullable();
                $table->boolean('training_agreement_sent')->default(false);
                $table->date('work_day')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('candidate_retention_audits')) {
            Schema::create('candidate_retention_audits', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('reference', 20);
                $table->unsignedBigInteger('submission_id')->nullable();
                $table->unsignedBigInteger('actor_id')->nullable();
                $table->unsignedInteger('event_count')->default(0);
                $table->unsignedInteger('document_count')->default(0);
                $table->boolean('files_removed')->default(false);
                $table->timestamp('created_at')->nullable();
            });
        }

        if (! Schema::hasTable('candidate_onboarding_events')) {
            Schema::create('candidate_onboarding_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('submission_id')->nullable();
                $table->unsignedBigInteger('actor_id')->nullable();
                $table->string('action', 40);
                $table->string('subject', 80)->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }

        if (! Schema::hasTable('company_legal_profiles')) {
            Schema::create('company_legal_profiles', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('country', 10)->default('india');
                $table->string('legal_name');
                $table->text('legal_address');
                $table->string('registration_details')->nullable();
                $table->string('signatory_name');
                $table->string('signatory_designation');
                $table->string('hr_email');
                $table->string('logo_path')->nullable();
                $table->string('signature_path')->nullable();
                $table->boolean('signature_enabled')->default(false);
                $table->boolean('signature_active')->default(false);
                $table->unsignedInteger('signature_version')->default(0);
                $table->unsignedBigInteger('signature_uploaded_by')->nullable();
                $table->timestamp('signature_uploaded_at')->nullable();
                $table->unsignedSmallInteger('signature_width')->default(140);
                $table->unsignedSmallInteger('signature_height')->default(48);
                $table->string('signature_align', 10)->default('left');
                $table->unsignedSmallInteger('signature_gap_before')->default(8);
                $table->unsignedSmallInteger('signature_gap_after')->default(6);
                $table->string('status', 20)->default('draft');
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->unsignedBigInteger('verified_by')->nullable();
                $table->timestamp('verified_at')->nullable();
                $table->timestamps();
                $table->unique(['tenant_id', 'country']);
            });
        }

        if (! Schema::hasTable('company_legal_profile_events')) {
            Schema::create('company_legal_profile_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('actor_id');
                $table->string('action', 40);
                $table->json('previous_values')->nullable();
                $table->json('updated_values')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->index(['tenant_id', 'created_at']);
            });
        }

        if (! Schema::hasTable('offer_letterheads')) {
            Schema::create('offer_letterheads', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('country', 10);
                $table->unsignedInteger('version');
                $table->string('source_path');
                $table->string('background_path')->nullable();
                $table->string('mime', 80);
                $table->string('original_name');
                $table->string('status', 20)->default('active');
                $table->string('page_size', 10)->default('A4');
                $table->decimal('page_width', 8, 2)->nullable();
                $table->decimal('page_height', 8, 2)->nullable();
                $table->unsignedSmallInteger('header_height')->nullable();
                $table->unsignedSmallInteger('footer_height')->nullable();
                $table->unsignedSmallInteger('margin_top')->default(120);
                $table->unsignedSmallInteger('margin_right')->default(48);
                $table->unsignedSmallInteger('margin_bottom')->default(56);
                $table->unsignedSmallInteger('margin_left')->default(48);
                $table->unsignedBigInteger('uploaded_by')->nullable();
                $table->string('printed_legal_name')->nullable();
                $table->text('printed_address')->nullable();
                $table->timestamps();
                $table->unique(['tenant_id', 'country', 'version']);
            });
        }

        if (Schema::hasTable('offer_letterheads') && ! Schema::hasColumn('offer_letterheads', 'printed_legal_name')) {
            Schema::table('offer_letterheads', function (Blueprint $table) {
                $table->string('printed_legal_name')->nullable();
                $table->text('printed_address')->nullable();
            });
        }

        if (! Schema::hasTable('hr_announcements')) {
            Schema::create('hr_announcements', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('title');
                $table->text('description');
                $table->string('category', 30);
                $table->string('visibility', 20)->default('company');
                $table->string('status', 20)->default('draft');
                $table->timestamp('publish_at')->nullable();
                $table->date('expires_on')->nullable();
                $table->timestamp('published_at')->nullable();
                $table->string('attachment_path')->nullable();
                $table->string('attachment_name')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
                $table->index(['tenant_id', 'status', 'publish_at']);
            });
        }

        if (! Schema::hasTable('offer_letter_corrections')) {
            Schema::create('offer_letter_corrections', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('offer_letter_id');
                $table->unsignedBigInteger('actor_id');
                $table->string('reason', 500);
                $table->json('previous_values')->nullable();
                $table->json('updated_values')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->index(['tenant_id', 'offer_letter_id']);
            });
        }

        if (! Schema::hasTable('offer_letter_templates')) {
            Schema::create('offer_letter_templates', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('country', 10);
                $table->string('name');
                $table->string('status', 20)->default('draft');
                $table->unsignedInteger('current_version')->default(1);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('offer_letter_template_versions')) {
            Schema::create('offer_letter_template_versions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('template_id');
                $table->unsignedInteger('version');
                $table->string('country', 10);
                $table->text('body');
                $table->unsignedBigInteger('letterhead_id')->nullable();
                $table->string('status', 20)->default('draft');
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('submitted_by')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->unsignedBigInteger('approved_by')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamps();
                $table->unique(['template_id', 'version']);
            });
        }

        if (! Schema::hasTable('offer_letter_downloads')) {
            Schema::create('offer_letter_downloads', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('offer_letter_id');
                $table->unsignedBigInteger('user_id');
                $table->timestamp('created_at')->nullable();
            });
        }

        if (Schema::hasTable('employees') && ! Schema::hasColumn('employees', 'date_of_birth')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->date('date_of_birth')->nullable();
            });
        }

        if (! Schema::hasTable('followup_candidates')) {
            Schema::create('followup_candidates', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('owner_employee_id');
                $table->string('name');
                $table->string('email');
                $table->string('phone')->nullable();
                $table->string('university')->nullable();
                $table->string('state')->nullable();
                $table->text('remarks')->nullable();
                $table->date('follow_up_on')->nullable();
                $table->string('status', 30)->default('new');
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
                $table->unique(['tenant_id', 'email']);
                $table->index(['tenant_id', 'owner_employee_id', 'status']);
            });
        }

        if (! Schema::hasTable('followup_reminders')) {
            Schema::create('followup_reminders', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('candidate_id');
                $table->dateTime('remind_at');
                $table->text('notes')->nullable();
                $table->string('status', 20)->default('open');
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('completed_by')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('followup_events')) {
            Schema::create('followup_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('candidate_id');
                $table->unsignedBigInteger('actor_id');
                $table->string('action', 40);
                $table->text('note')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }

        if (! Schema::hasTable('work_tasks')) {
            Schema::create('work_tasks', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('assignee_employee_id');
                $table->string('title');
                $table->text('description')->nullable();
                $table->string('priority', 20)->default('medium');
                $table->date('start_on')->nullable();
                $table->date('due_on')->nullable();
                $table->date('expected_on')->nullable();
                $table->text('remarks')->nullable();
                $table->string('status', 20)->default('assigned');
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('assigned_by')->nullable();
                $table->unsignedBigInteger('completed_by')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('work_task_events')) {
            Schema::create('work_task_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('task_id');
                $table->unsignedBigInteger('actor_id');
                $table->string('action', 40);
                $table->text('note')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }

        if (Schema::hasTable('offer_letters') && ! Schema::hasColumn('offer_letters', 'country')) {
            Schema::table('offer_letters', function (Blueprint $table) {
                $table->string('country', 10)->default('india');
                $table->string('reference_code', 40)->nullable();
                $table->unsignedBigInteger('template_version_id')->nullable();
                $table->string('employee_email')->nullable();
                $table->string('compensation_type', 20)->nullable();
                $table->decimal('hourly_rate', 12, 2)->nullable();
                $table->decimal('basic_salary', 12, 2)->nullable();
                $table->decimal('hra', 12, 2)->nullable();
                $table->decimal('special_allowance', 12, 2)->nullable();
                $table->unsignedTinyInteger('probation_months')->nullable();
                $table->string('department_name')->nullable();
                $table->string('work_location')->nullable();
                $table->string('employment_type')->nullable();
                $table->string('work_mode')->nullable();
                $table->string('working_hours')->nullable();
                $table->unsignedBigInteger('submitted_by')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->unsignedBigInteger('approved_by')->nullable();
                $table->timestamp('approved_at')->nullable();
            });
        }

        if (Schema::hasTable('offer_letters') && ! Schema::hasColumn('offer_letters', 'notice_days')) {
            Schema::table('offer_letters', function (Blueprint $table) {
                $table->unsignedSmallInteger('notice_days')->nullable();
                $table->date('date_of_issue')->nullable();
                $table->string('designation_name')->nullable();
                $table->string('reporting_manager_name')->nullable();
                $table->string('employee_phone', 40)->nullable();
                $table->text('offer_notes')->nullable();
            });
        }

        if (Schema::hasTable('offer_letters') && ! Schema::hasColumn('offer_letters', 'salutation')) {
            Schema::table('offer_letters', function (Blueprint $table) {
                $table->string('salutation', 20)->nullable();
            });
        }

        if (Schema::hasTable('offer_letters') && ! Schema::hasColumn('offer_letters', 'candidate_address')) {
            Schema::table('offer_letters', function (Blueprint $table) {
                $table->text('candidate_address')->nullable();
            });
        }

        if (Schema::hasTable('company_legal_profiles') && ! Schema::hasColumn('company_legal_profiles', 'letterhead_confirmed_by')) {
            Schema::table('company_legal_profiles', function (Blueprint $table) {
                $table->unsignedBigInteger('letterhead_confirmed_by')->nullable();
                $table->timestamp('letterhead_confirmed_at')->nullable();
                $table->unsignedBigInteger('letterhead_confirmed_id')->nullable();
            });
        }

        if (Schema::hasTable('payroll_runs') && ! Schema::hasColumn('payroll_runs', 'source')) {
            Schema::table('payroll_runs', function (Blueprint $table) {
                $table->string('source', 20)->default('calculated');
                $table->string('original_name')->nullable();
                $table->string('file_path')->nullable();
                $table->unsignedBigInteger('uploaded_by')->nullable();
                $table->timestamp('uploaded_at')->nullable();
                $table->unsignedBigInteger('confirmed_by')->nullable();
                $table->string('payslip_status', 20)->nullable();
                $table->json('import_rows')->nullable();
            });
        }

        if (! Schema::hasTable('attendance_correction_requests')) {
            Schema::create('attendance_correction_requests', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('employee_id');
                $table->unsignedBigInteger('attendance_log_id')->nullable();
                $table->date('work_date');
                $table->string('type', 40);
                $table->dateTime('requested_check_in')->nullable();
                $table->dateTime('requested_check_out')->nullable();
                $table->text('reason');
                $table->string('status', 20)->default('pending');
                $table->unsignedBigInteger('requested_by');
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->text('review_reason')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('attendance_correction_audits')) {
            Schema::create('attendance_correction_audits', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('employee_id');
                $table->unsignedBigInteger('attendance_log_id')->nullable();
                $table->unsignedBigInteger('request_id');
                $table->dateTime('original_check_in')->nullable();
                $table->dateTime('original_check_out')->nullable();
                $table->dateTime('corrected_check_in')->nullable();
                $table->dateTime('corrected_check_out')->nullable();
                $table->unsignedSmallInteger('original_late_minutes')->nullable();
                $table->unsignedSmallInteger('corrected_late_minutes')->nullable();
                $table->unsignedBigInteger('changed_by')->nullable();
                $table->unsignedBigInteger('approved_by')->nullable();
                $table->text('employee_reason');
                $table->text('review_reason')->nullable();
                $table->string('decision', 20);
                $table->timestamp('created_at')->nullable();
            });
        }

        if (! Schema::hasTable('holiday_work_authorizations')) {
            Schema::create('holiday_work_authorizations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('employee_id');
                $table->date('work_date');
                $table->string('kind', 20);
                $table->unsignedBigInteger('holiday_id')->nullable();
                $table->text('reason')->nullable();
                $table->unsignedBigInteger('authorized_by');
                $table->timestamps();
            });
        }

        if (Schema::hasTable('payroll_items') && ! Schema::hasColumn('payroll_items', 'import_supplied')) {
            Schema::table('payroll_items', function (Blueprint $table) {
                $table->decimal('other_earnings', 12, 2)->nullable();
                $table->decimal('professional_tax', 12, 2)->nullable();
                $table->decimal('other_deductions', 12, 2)->nullable();
                $table->string('payslip_path')->nullable();
                $table->text('payslip_error')->nullable();
                $table->json('import_supplied')->nullable();
            });
        }

        if (! Schema::hasTable('usa_offer_designations')) {
            Schema::create('usa_offer_designations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('designation_key', 80);
                $table->string('name', 120);
                $table->string('group_key', 30);
                $table->json('responsibilities');
                $table->json('factory_responsibilities');
                $table->boolean('is_active')->default(true);
                $table->boolean('is_builtin')->default(false);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
                $table->unique(['tenant_id', 'designation_key']);
                $table->unique(['tenant_id', 'name']);
            });
        }

        if (! Schema::hasTable('usa_offer_profiles')) {
            Schema::create('usa_offer_profiles', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->unique();
                $table->string('company_name');
                $table->string('address_line')->nullable();
                $table->string('city_line')->nullable();
                $table->string('country_line')->nullable();
                $table->string('website')->nullable();
                $table->string('email')->nullable();
                $table->string('phone')->nullable();
                $table->string('e_verify', 40)->nullable();
                $table->string('federal_id', 40)->nullable();
                $table->string('manager_name')->nullable();
                $table->string('manager_title')->nullable();
                $table->string('manager_email')->nullable();
                $table->string('manager_phone')->nullable();
                $table->string('hr_name')->nullable();
                $table->string('hr_title')->nullable();
                $table->string('hr_phone')->nullable();
                $table->string('letterhead_path')->nullable();
                $table->string('letterhead_name')->nullable();
                $table->string('signature_path')->nullable();
                $table->string('signature_name')->nullable();
                $table->string('template_version', 40);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('usa_offers')) {
            Schema::create('usa_offers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('designation_id')->nullable();
                $table->string('designation_name');
                $table->json('responsibilities');
                $table->string('candidate_name');
                $table->string('candidate_email')->nullable();
                $table->string('candidate_phone')->nullable();
                $table->text('candidate_address')->nullable();
                $table->string('pay_type', 20);
                $table->decimal('pay_amount', 12, 2);
                $table->string('working_hours', 80);
                $table->string('work_arrangement', 120);
                $table->string('university', 160);
                $table->date('start_date');
                $table->date('issue_date');
                $table->json('profile_snapshot')->nullable();
                $table->string('template_version', 40);
                $table->string('status', 20)->default('draft');
                $table->string('file_path')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('usa_offers') && ! Schema::hasColumn('usa_offers', 'employment_type')) {
            Schema::table('usa_offers', function (Blueprint $table) {
                $table->string('employment_type', 80)->nullable();
            });
        }

        if (! Schema::hasTable('i983_cases')) {
            Schema::create('i983_cases', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('reference', 20);
                $table->string('candidate_name', 120);
                $table->string('candidate_email', 191);
                $table->char('email_hash', 64);
                $table->string('status', 30)->default('awaiting_student');
                $table->timestamp('student_submitted_at')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->unique(['tenant_id', 'reference']);
            });
        }

        if (! Schema::hasTable('i983_links')) {
            Schema::create('i983_links', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('case_id');
                $table->char('token_hash', 64)->unique();
                $table->timestamp('expires_at');
                $table->timestamp('revoked_at')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }

        if (! Schema::hasTable('i983_students')) {
            Schema::create('i983_students', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('case_id')->unique();
                $table->string('surname', 80)->nullable();
                $table->string('given_name', 80)->nullable();
                $table->string('middle_name', 80)->nullable();
                $table->string('email', 191)->nullable();
                $table->string('school_recommending', 191)->nullable();
                $table->string('school_degree', 191)->nullable();
                $table->string('sevis_school_code', 20)->nullable();
                $table->string('dso_name', 120)->nullable();
                $table->string('dso_email', 191)->nullable();
                $table->string('dso_phone', 40)->nullable();
                $table->text('sevis_id_ciphertext')->nullable();
                $table->date('opt_start')->nullable();
                $table->date('opt_end')->nullable();
                $table->string('major_cip', 160)->nullable();
                $table->string('degree_level', 80)->nullable();
                $table->date('degree_awarded_on')->nullable();
                $table->boolean('prior_degree')->nullable();
                $table->text('ead_ciphertext')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('i983_employers')) {
            Schema::create('i983_employers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('case_id')->unique();
                $table->string('legal_name', 191)->nullable();
                $table->string('street', 191)->nullable();
                $table->string('suite', 40)->nullable();
                $table->string('website', 191)->nullable();
                $table->string('city', 80)->nullable();
                $table->string('state', 2)->nullable();
                $table->string('zip', 10)->nullable();
                $table->text('ein_ciphertext')->nullable();
                $table->unsignedInteger('employee_count')->nullable();
                $table->string('naics', 10)->nullable();
                $table->unsignedTinyInteger('opt_hours')->nullable();
                $table->decimal('salary', 12, 2)->nullable();
                $table->string('salary_frequency', 40)->nullable();
                $table->text('other_compensation')->nullable();
                $table->date('start_date')->nullable();
                $table->timestamp('finalized_at')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('i983_training_plans')) {
            Schema::create('i983_training_plans', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('case_id')->unique();
                $table->string('student_name', 160)->nullable();
                $table->string('employer_name', 191)->nullable();
                $table->string('site_name', 191)->nullable();
                $table->string('site_address', 300)->nullable();
                $table->string('official_name', 120)->nullable();
                $table->string('official_title', 120)->nullable();
                $table->string('official_email', 191)->nullable();
                $table->string('official_phone', 40)->nullable();
                $table->text('student_role')->nullable();
                $table->text('goals')->nullable();
                $table->text('oversight')->nullable();
                $table->text('measures')->nullable();
                $table->text('remarks')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('i983_field_revisions')) {
            Schema::create('i983_field_revisions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('case_id');
                $table->string('field_name', 40);
                $table->text('previous_value')->nullable();
                $table->text('new_value')->nullable();
                $table->string('actor_type', 20);
                $table->unsignedBigInteger('actor_id')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }

        if (! Schema::hasTable('i983_certifications')) {
            Schema::create('i983_certifications', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('case_id');
                $table->string('party', 20);
                $table->string('printed_name', 160)->nullable();
                $table->string('title', 120)->nullable();
                $table->date('certified_on')->nullable();
                $table->string('signature_path')->nullable();
                $table->boolean('acknowledged')->default(false);
                $table->timestamps();
                $table->unique(['case_id', 'party']);
            });
        }

        if (! Schema::hasTable('i983_student_certifications')) {
            Schema::create('i983_student_certifications', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('case_id');
                $table->unsignedBigInteger('link_id');
                $table->unsignedInteger('version');
                $table->string('printed_name', 160);
                $table->string('student_email', 191);
                $table->boolean('acknowledged');
                $table->char('statements_sha256', 64);
                $table->date('certified_on');
                $table->timestamp('certified_at');
                $table->string('timezone', 64);
                $table->string('signature_path');
                $table->char('signature_sha256', 64);
                $table->char('upload_sha256', 64);
                $table->timestamp('created_at')->nullable();
                $table->unique(['case_id', 'version']);
                $table->index('signature_sha256');
                $table->index('upload_sha256');
            });
        }

        if (! Schema::hasTable('i983_evaluations')) {
            Schema::create('i983_evaluations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('case_id');
                $table->string('kind', 20);
                $table->date('date_from')->nullable();
                $table->date('date_to')->nullable();
                $table->text('narrative')->nullable();
                $table->date('student_signed_on')->nullable();
                $table->date('employer_signed_on')->nullable();
                $table->string('student_signature_path')->nullable();
                $table->string('employer_signature_path')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('i983_documents')) {
            Schema::create('i983_documents', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('case_id');
                $table->string('kind', 20);
                $table->unsignedInteger('version')->default(1);
                $table->char('sha256', 64)->nullable();
                $table->string('file_path');
                $table->string('original_name', 191);
                $table->string('mime', 80);
                $table->unsignedInteger('size');
                $table->unsignedBigInteger('source_document_id')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }

        if (! Schema::hasTable('i983_signatories')) {
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

        if (! Schema::hasTable('i983_corrections')) {
            Schema::create('i983_corrections', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('case_id');
                $table->text('reason');
                $table->unsignedBigInteger('requested_by')->nullable();
                $table->timestamp('requested_at');
                $table->timestamp('resolved_at')->nullable();
            });
        }

        if (! Schema::hasTable('i983_events')) {
            Schema::create('i983_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('case_id')->nullable();
                $table->unsignedBigInteger('actor_id')->nullable();
                $table->string('actor_type', 20);
                $table->string('action', 40);
                $table->string('subject', 80)->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }
    }
}
