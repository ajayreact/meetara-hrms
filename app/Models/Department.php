<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Department extends Model
{
    use BelongsToCompany;

    /**
     * Company ownership is assigned by the server, never from the request body.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'code',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
