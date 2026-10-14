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
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A person inside one company. All business records reference this, never users.id.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $user_id
 * @property int $role_id
 * @property int|null $department_id
 * @property int|null $reports_to_id
 * @property string $user_type
 * @property string|null $employee_code
 * @property string|null $designation
 * @property string $status
 * @property Carbon|null $joined_at
 * @property Carbon|null $scheduled_exit_at
 * @property Carbon|null $left_at
 * @property string|null $exit_type
 * @property string|null $exit_reason
 * @property int|null $exited_by
 * @property Carbon|null $deleted_at
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

    /** @return HasMany<Membership, $this> */
    public function directReports(): HasMany
    {
        return $this->hasMany(self::class, 'reports_to_id');
    }

    /**
     * Everyone below this person at any depth (closure table).
     *
     * @return BelongsToMany<Membership, $this>
     */
    public function descendants(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'membership_hierarchy', 'ancestor_id', 'descendant_id')
            ->wherePivot('depth', '>', 0);
    }

    /**
     * Everyone above this person, up to the top (closure table).
     *
     * @return BelongsToMany<Membership, $this>
     */
    public function ancestors(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'membership_hierarchy', 'descendant_id', 'ancestor_id')
            ->wherePivot('depth', '>', 0);
    }

    /** @return HasMany<EmploymentPeriod, $this> */
    public function employmentPeriods(): HasMany
    {
        return $this->hasMany(EmploymentPeriod::class)->orderByDesc('joined_at')->orderByDesc('id');
    }

    public function isExternal(): bool
    {
        return $this->user_type === 'external';
    }

    /**
     * Does this person's data scope reach $other? (own = themself, team = themself + everyone below,
     * department = same department, organization = everyone.)
     */
    public function covers(self $other): bool
    {
        if ($other->id === $this->id) {
            return true;
        }

        return match ($this->role->data_scope) {
            'organization' => $other->organization_id === $this->organization_id,
            'department' => $this->department_id !== null && $other->department_id === $this->department_id,
            'team' => DB::table('membership_hierarchy')->where('ancestor_id', $this->id)->where('descendant_id', $other->id)->exists(),
            default => false,
        };
    }

    /**
     * Limit a people query to what the viewer's role data scope allows:
     * own = themself, team = themself + everyone below, department = same department, organization = all.
     *
     * @param  Builder<Membership>  $query
     * @return Builder<Membership>
     */
    public function scopeVisibleTo(Builder $query, self $viewer): Builder
    {
        $scope = $viewer->role->data_scope;

        return match ($scope) {
            'organization' => $query,
            'department' => $query->where(fn ($q) => $q
                ->where($this->qualifyColumn('id'), $viewer->id)
                ->when($viewer->department_id, fn ($q) => $q->orWhere($this->qualifyColumn('department_id'), $viewer->department_id))),
            'team' => $query->whereIn($this->qualifyColumn('id'), DB::table('membership_hierarchy')
                ->where('ancestor_id', $viewer->id)->select('descendant_id')),
            default => $query->where($this->qualifyColumn('id'), $viewer->id),
        };
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
