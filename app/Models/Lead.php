<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lead extends Model
{
    use BelongsToCompany;

    public const STATUSES = [
        'new' => 'New',
        'contacted' => 'Contacted',
        'follow_up' => 'Follow-up required',
        'interested' => 'Interested',
        'not_interested' => 'Not interested',
        'converted' => 'Converted',
        'do_not_contact' => 'Do not contact',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'imported_on' => 'date',
            'follow_up_on' => 'date',
            'follow_up_reminded_on' => 'date',
            'assigned_at' => 'datetime',
        ];
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(LeadImportBatch::class, 'import_batch_id');
    }

    public function teamLead(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'team_lead_employee_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assignee_employee_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(LeadAssignment::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(LeadActivity::class);
    }

    /**
     * Leads given to one Team Lead, including retained and distributed leads.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForTeamLead(Builder $query, int $employeeId): Builder
    {
        return $query->where($query->getModel()->getTable().'.team_lead_employee_id', $employeeId);
    }

    /**
     * Leads assigned to one employee.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForAssignee(Builder $query, int $employeeId): Builder
    {
        return $query->where($query->getModel()->getTable().'.assignee_employee_id', $employeeId);
    }
}
