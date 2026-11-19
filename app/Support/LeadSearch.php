<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

class LeadSearch
{
    /**
     * Match a name, email, or phone across the whole role-scoped query.
     * Spaces and capitalization are ignored for names. Phone search also
     * ignores separators and uses the stored digit form when it exists.
     *
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     */
    public static function apply(Builder $query, string $term): void
    {
        $term = mb_substr(trim(preg_replace('/\s+/u', ' ', $term) ?? ''), 0, 120);

        if ($term === '') {
            return;
        }

        $like = '%'.addcslashes(mb_strtolower($term), '%_\\').'%';
        $compact = '%'.addcslashes(str_replace(' ', '', mb_strtolower($term)), '%_\\').'%';
        $digits = preg_replace('/\D+/', '', $term) ?? '';
        $hasEmail = Schema::hasColumn('leads', 'email_normalized');
        $hasDigits = Schema::hasColumn('leads', 'phone_digits');

        $query->where(function (Builder $inner) use ($like, $compact, $digits, $hasEmail, $hasDigits): void {
            $inner->whereRaw("LOWER(REPLACE(leads.name, ' ', '')) LIKE ?", [$compact])
                ->orWhereRaw('LOWER(leads.email) LIKE ?', [$like])
                ->orWhereRaw('LOWER(leads.phone) LIKE ?', [$like]);

            if ($hasEmail) {
                $inner->orWhereRaw('LOWER(leads.email_normalized) LIKE ?', [$like]);
            }

            if (Schema::hasColumn('leads', 'source_name')) {
                $inner->orWhereRaw('LOWER(leads.source_name) LIKE ?', [$like]);
            }

            if (strlen($digits) < 3) {
                return;
            }

            $inner->orWhereRaw('LOWER('.self::digitsExpression('leads.phone').') LIKE ?', ['%'.$digits.'%']);

            if ($hasDigits) {
                $inner->orWhere('leads.phone_digits', 'like', '%'.$digits.'%');
            }
        });
    }

    private static function digitsExpression(string $column): string
    {
        $expression = $column;

        foreach ([' ', '-', '(', ')', '+', '.', '/'] as $character) {
            $expression = "REPLACE({$expression}, '{$character}', '')";
        }

        return $expression;
    }
}
