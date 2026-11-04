<?php

namespace App\Support;

use App\Models\Office;

class EmployeeOffice
{
    /**
     * Office used for attendance. An explicit assignment is used as stored.
     * An employee with no assignment uses the company's only active office.
     * The caller still applies that office's public-IP and GPS checks.
     */
    public static function resolve(int $tenantId, mixed $locationId): ?Office
    {
        if ($tenantId <= 0) {
            return null;
        }

        if ($locationId !== null && $locationId !== '') {
            return Office::query()->whereKey($locationId)->where('tenant_id', $tenantId)->first();
        }

        $offices = Office::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->limit(2)
            ->get();

        return $offices->count() === 1 ? $offices->first() : null;
    }
}
