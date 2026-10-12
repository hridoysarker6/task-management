<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Services\PermissionService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * A person inside one company. All business records reference this, never users.id.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $user_id
 * @property int $role_id
 * @property string $status
 */
#[Fillable([
    'organization_id', 'user_id', 'role_id', 'department_id', 'reports_to_id', 'user_type', 'employee_code',
    'designation', 'status', 'joined_at', 'scheduled_exit_at', 'left_at', 'exit_type', 'exit_reason', 'exited_by',
])]
class Membership extends Model
{
    use BelongsToOrganization, HasFactory, SoftDeletes;

    public const INVITED = 'invited';

    public const ACTIVE = 'active';

    public const EXITED = 'exited';

    protected function casts(): array
    {
        return [
            'joined_at' => 'date',
            'scheduled_exit_at' => 'date',
            'left_at' => 'date',
        ];
    }

    protected static function booted(): void
    {
        // A role change, exit or restore must never serve stale permissions.
        $forget = fn (self $m) => app(PermissionService::class)->forget($m);
        static::saved($forget);
        static::deleted($forget);
        static::restored($forget);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return BelongsTo<Membership, $this> */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reports_to_id');
    }

    /**
     * Per-user exceptions to the role: pivot `effect` is `grant` or `revoke`.
     *
     * @return BelongsToMany<Permission, $this>
     */
    public function permissionOverrides(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'membership_permission_overrides')
            ->withPivot(['effect', 'granted_by'])
            ->withTimestamps();
    }

    /**
     * @param  Builder<Membership>  $query
     * @return Builder<Membership>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::ACTIVE);
    }

    /**
     * Former employees: exited and soft-deleted.
     *
     * @param  Builder<Membership>  $query
     * @return Builder<Membership>
     */
    public function scopeFormer(Builder $query): Builder
    {
        return $query->withoutGlobalScope(SoftDeletingScope::class)
            ->whereNotNull($this->getQualifiedDeletedAtColumn())
            ->where('status', self::EXITED);
    }

    /**
     * Effective permission names: (role permissions + grants) - revokes.
     *
     * @return list<string>
     */
    public function permissionNames(): array
    {
        return app(PermissionService::class)->forMembership($this);
    }

    public function hasPermission(string $permission): bool
    {
        return app(PermissionService::class)->can($this, $permission);
    }
}
