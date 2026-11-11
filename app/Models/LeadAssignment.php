<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadAssignment extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function fromTeamLead(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'from_team_lead_employee_id');
    }

    public function toTeamLead(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'to_team_lead_employee_id');
    }

    public function fromAssignee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'from_assignee_employee_id');
    }

    public function toAssignee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'to_assignee_employee_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
