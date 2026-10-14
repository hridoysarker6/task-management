<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $organization_id
 * @property string $email
 * @property string|null $name
 * @property int $role_id
 * @property int|null $department_id
 * @property int|null $reports_to_id
 * @property string|null $reports_to_email
 * @property string $user_type
 * @property string|null $designation
 * @property string|null $employee_code
 * @property string $token_hash
 * @property int|null $invited_by
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $revoked_at
 */
#[Fillable([
    'organization_id', 'email', 'name', 'role_id', 'department_id', 'reports_to_id', 'reports_to_email', 'user_type',
    'designation', 'employee_code', 'rehire_membership_id', 'token_hash', 'invited_by', 'expires_at',
])]
class Invitation extends Model
{
    use BelongsToOrganization, HasFactory;

    public const VALID_DAYS = 7;

    /** The raw token, only available on the instance that just created or renewed it (the DB keeps a hash). */
    public ?string $plainToken = null;

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function newToken(): string
    {
        return Str::random(64);
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class)->withoutGlobalScopes()->withTrashed();
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class)->withoutGlobalScopes()->withTrashed();
    }

    /** @return BelongsTo<Membership, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'invited_by')->withoutGlobalScopes()->withTrashed();
    }

    /** @return BelongsTo<Membership, $this> */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'reports_to_id')->withoutGlobalScopes()->withTrashed();
    }

    /**
     * Invitations that can still be accepted or are still waiting (not accepted, not revoked).
     *
     * @param  Builder<Invitation>  $query
     * @return Builder<Invitation>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('accepted_at')->whereNull('revoked_at');
    }

    /**
     * Open and not yet expired.
     *
     * @param  Builder<Invitation>  $query
     * @return Builder<Invitation>
     */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->open()->where('expires_at', '>', now());
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        return $this->accepted_at === null && $this->revoked_at === null && ! $this->isExpired();
    }

    /** pending | expired | accepted | revoked */
    public function state(): string
    {
        return match (true) {
            $this->accepted_at !== null => 'accepted',
            $this->revoked_at !== null => 'revoked',
            $this->isExpired() => 'expired',
            default => 'pending',
        };
    }
}
