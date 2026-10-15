<?php

namespace App\Models;

use App\Models\Scopes\OrganizationScope;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property bool $is_system_admin
 * @property int|null $last_organization_id
 * @property string $status
 */
#[Fillable(['name', 'email', 'phone', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** @var list<string> */
    protected $appends = ['avatar_url'];

    /** The profile photo, or null (the app then shows initials). */
    public function getAvatarUrlAttribute(): ?string
    {
        return $this->avatar_path ? Storage::disk('public')->url($this->avatar_path) : null;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_system_admin' => 'boolean',
            'password' => 'hashed',
        ];
    }

    /**
     * Memberships in every company (the current-company scope is deliberately lifted).
     *
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class)->withoutGlobalScope(OrganizationScope::class);
    }

    /**
     * Companies this person applied to register.
     *
     * @return HasMany<Organization, $this>
     */
    public function applications(): HasMany
    {
        return $this->hasMany(Organization::class, 'applicant_user_id');
    }

    /**
     * Active memberships in active companies, i.e. the companies this user can work in right now.
     *
     * @return Collection<int, Membership>
     */
    public function accessibleMemberships(): Collection
    {
        return $this->memberships()
            ->where('status', Membership::ACTIVE)
            ->whereHas('organization', fn ($q) => $q->where('status', Organization::ACTIVE))
            ->with(['organization', 'role'])
            ->get();
    }

    /** True when the user belongs to companies, but every one of them is suspended. */
    public function onlyInSuspendedCompanies(): bool
    {
        $companies = $this->memberships()
            ->where('status', Membership::ACTIVE)
            ->with('organization')
            ->get()
            ->pluck('organization')
            ->filter();

        return $companies->isNotEmpty() && $companies->every(fn (Organization $o) => $o->isSuspended());
    }

    /**
     * Why this person has no access to a company they used to belong to, e.g.
     * "Your access to ABC Soft ended on 30 Sep 2026." Null when they never left one.
     */
    public function exitMessage(): ?string
    {
        $membership = $this->memberships()->onlyTrashed()
            ->where('status', Membership::EXITED)
            ->with('organization')
            ->latest('left_at')
            ->first();

        if (! $membership || ! $membership->organization) {
            return null;
        }

        $date = $membership->left_at?->format('j M Y');

        return "Your access to {$membership->organization->name}".($date ? " ended on {$date}." : ' has ended.');
    }

    /** The company application that is still waiting on this person (pending, info requested or rejected). */
    public function openApplication(): ?Organization
    {
        return $this->applications()
            ->whereIn('status', [Organization::PENDING, Organization::INFO_REQUESTED, Organization::REJECTED])
            ->latest('id')
            ->first();
    }
}
