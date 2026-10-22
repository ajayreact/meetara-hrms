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

        if (! Schema::hasTable('tenants')) {
            Schema::create('tenants', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('slug')->nullable();
                $table->string('timezone')->default('Asia/Kolkata');
                $table->string('status')->default('active');
                $table->timestamps();
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
                $table->string('review_status', 40)->default('pending');
                $table->unsignedBigInteger('uploaded_by')->nullable();
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->text('review_note')->nullable();
                $table->unsignedBigInteger('replaces_document_id')->nullable();
                $table->timestamps();
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
    }
}
