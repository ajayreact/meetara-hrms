<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Shift extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'name',
        'type',
        'start_time',
        'end_time',
        'expected_minutes',
        'grace_minutes',
        'is_default',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }
}
