<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

class LeadListing
{
    /**
     * @var list<int>
     */
    public const SIZES = [10, 25, 50, 100];

    /**
     * @param  array<string, mixed>  $input
     */
    public static function perPage(array $input, int $default = 25): int
    {
        $size = (int) ($input['per_page'] ?? $default);

        return in_array($size, self::SIZES, true) ? $size : $default;
    }

    /**
     * Recently assigned leads first. Leads with no assignment time follow them.
     *
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     */
    public static function recentFirst(Builder $query): void
    {
        $query->orderByRaw('CASE WHEN leads.assigned_at IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('leads.assigned_at')
            ->orderByDesc('leads.id');
    }
}
