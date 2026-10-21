<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    protected $table = 'tenants';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'week_off' => 'array',
        ];
    }
}
