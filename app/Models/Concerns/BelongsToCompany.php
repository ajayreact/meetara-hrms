<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

trait BelongsToCompany
{
    /**
     * Limit a query to one company. The company key is the stored tenant_id column.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeInCompany(Builder $query, int $companyId): Builder
    {
        return $query->where($query->getModel()->getTable().'.tenant_id', $companyId);
    }
}
