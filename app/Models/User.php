<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
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
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles');
    }

    public function hasRole(string $role): bool
    {
        if ($this->relationLoaded('roles')) {
            return $this->roles->contains('slug', $role);
        }

        return $this->roles()->where('slug', $role)->exists();
    }

    /** @param array<int, string> $roles */
    public function hasAnyRole(array $roles): bool
    {
        if ($this->relationLoaded('roles')) {
            return $this->roles->whereIn('slug', $roles)->isNotEmpty();
        }

        return $this->roles()->whereIn('slug', $roles)->exists();
    }

    public function hasPermission(string $permission): bool
    {
        $override = DB::table('user_permission_overrides')
            ->join('permissions', 'permissions.id', '=', 'user_permission_overrides.permission_id')
            ->where('user_permission_overrides.user_id', $this->id)
            ->where('permissions.slug', $permission)
            ->value('user_permission_overrides.allowed');

        if ($override !== null) {
            return (bool) $override;
        }

        return DB::table('role_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->join('user_roles', 'user_roles.role_id', '=', 'role_permissions.role_id')
            ->where('user_roles.user_id', $this->id)
            ->where('permissions.slug', $permission)
            ->exists();
    }

    public function isAssignedToEvent(string $eventId, string $role = 'panitia'): bool
    {
        return DB::table('event_user_assignments')
            ->join('roles', 'roles.id', '=', 'event_user_assignments.role_id')
            ->where('event_user_assignments.event_id', $eventId)
            ->where('event_user_assignments.user_id', $this->id)
            ->where('event_user_assignments.is_active', true)
            ->where('roles.slug', $role)
            ->exists();
    }

    public function accessTokens(): HasMany
    {
        return $this->hasMany(StaffAccessToken::class);
    }

    public function eventAssignments(): HasMany
    {
        return $this->hasMany(EventUserAssignment::class);
    }
}
