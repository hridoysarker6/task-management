<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $status
 * @property int|null $user_limit
 * @property int $applicant_user_id
 * @property array<string, mixed>|null $settings
 * @property array<int, int>|null $working_days
 * @property Carbon $created_at
 * @property Carbon|null $approved_at
 * @property Carbon|null $suspended_at
 */
#[Fillable([
    'name', 'slug', 'industry', 'size', 'address', 'phone', 'email', 'website', 'registration_no',
    'logo_path', 'timezone', 'date_format', 'working_days', 'user_limit', 'settings',
])]
class Organization extends Model
{
    use HasFactory, SoftDeletes;

    public const PENDING = 'pending';

    public const INFO_REQUESTED = 'info_requested';

    public const ACTIVE = 'active';

    public const REJECTED = 'rejected';

    public const SUSPENDED = 'suspended';

    public const STATUSES = [self::PENDING, self::INFO_REQUESTED, self::ACTIVE, self::REJECTED, self::SUSPENDED];

    /** Slugs that would clash with application routes. */
    public const RESERVED_SLUGS = ['admin', 'api', 'login', 'logout', 'register', 'settings', 'dashboard', 'www'];

    protected function casts(): array
    {
        return [
            'working_days' => 'array',
            'settings' => 'array',
            'approved_at' => 'datetime',
            'suspended_at' => 'datetime',
            'user_limit' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function applicant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applicant_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return HasMany<OrganizationReview, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(OrganizationReview::class)->latest('id');
    }

    /** @return HasMany<OrganizationDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(OrganizationDocument::class);
    }

    /** @return HasMany<Role, $this> */
    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    /** @return HasMany<Membership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function scopeStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public function isSuspended(): bool
    {
        return $this->status === self::SUSPENDED;
    }

    /** True while the applicant may still edit and resubmit the application. */
    public function isEditableByApplicant(): bool
    {
        return in_array($this->status, [self::REJECTED, self::INFO_REQUESTED], true);
    }

    public function statusLabel(): string
    {
        return [
            self::PENDING => 'Pending review',
            self::INFO_REQUESTED => 'More info requested',
            self::ACTIVE => 'Active',
            self::REJECTED => 'Rejected',
            self::SUSPENDED => 'Suspended',
        ][$this->status];
    }

    /** Latest review row of the given action, e.g. the rejection reason. */
    public function latestReview(string ...$actions): ?OrganizationReview
    {
        return $this->reviews()->whereIn('action', $actions)->first();
    }
}
