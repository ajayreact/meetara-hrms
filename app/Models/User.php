<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Auth\CompanyRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'api_token',
        'invite_token',
        'tenant_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_platform_admin' => 'boolean',
            'invited_at' => 'datetime',
            'invite_accepted_at' => 'datetime',
            'invite_expires_at' => 'datetime',
            'invite_sent_at' => 'datetime',
        ];
    }

    public function isPlatformAdmin(): bool
    {
        return Schema::hasColumn('users', 'is_platform_admin') && (bool) $this->is_platform_admin;
    }

    /**
     * Company key for the signed-in account. Platform administrators are not treated as a member of a company.
     */
    public function companyId(): ?int
    {
        if ($this->isPlatformAdmin() || ! Schema::hasColumn('users', 'tenant_id') || $this->tenant_id === null) {
            return null;
        }

        return (int) $this->tenant_id;
    }

    /**
     * @return list<string>
     */
    public function storedRoleNames(): array
    {
        if (! Schema::hasTable('model_has_roles') || ! Schema::hasTable('roles')) {
            return [];
        }

        $query = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_id', $this->id)
            ->where('model_has_roles.model_type', self::class);

        if (Schema::hasColumn('model_has_roles', 'tenant_id') && $this->companyId() !== null) {
            $query->where('model_has_roles.tenant_id', $this->companyId());
        }

        return $query->pluck('roles.name')
            ->map(fn ($name) => (string) $name)
            ->all();
    }

    public function applicationRole(): string
    {
        return CompanyRole::highest($this->storedRoleNames(), $this->isPlatformAdmin())['role'];
    }
}
