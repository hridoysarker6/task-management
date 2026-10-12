<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int|null $organization_id
 * @property string $name
 * @property string $slug
 * @property int $level
 * @property string $data_scope
 * @property bool $is_external
 * @property bool $is_locked
 * @property string|null $description
 */
#[Fillable(['organization_id', 'name', 'slug', 'level', 'data_scope', 'is_external', 'is_locked', 'description'])]
class Role extends Model
{
    use BelongsToOrganization, HasFactory, SoftDeletes;

    public const SCOPES = ['own', 'team', 'department', 'organization'];

    public const COMPANY_ADMIN_SLUG = 'company-admin';

    protected function casts(): array
    {
        return [
            'is_external' => 'boolean',
            'is_locked' => 'boolean',
            'level' => 'integer',
        ];
    }

    /** @return BelongsToMany<Permission, $this> */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permission');
    }

    /** @return HasMany<Membership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * The system-wide default template (organization_id = null), edited by the System Admin.
     *
     * @param  Builder<Role>  $query
     * @return Builder<Role>
     */
    public function scopeTemplate(Builder $query): Builder
    {
        return $query->withoutGlobalScope(OrganizationScope::class)->whereNull('organization_id');
    }

    public function isTemplate(): bool
    {
        return $this->organization_id === null;
    }
}
