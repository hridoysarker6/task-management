<?php

namespace App\Models\Concerns;

use App\Models\Organization;
use App\Models\Scopes\OrganizationScope;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marks a model as company-owned: every query is limited to the current company
 * and new rows get its organization_id automatically.
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope(new OrganizationScope);

        static::creating(function ($model) {
            if ($model->organization_id === null && ($id = app(CurrentOrganization::class)->id())) {
                $model->organization_id = $id;
            }
        });
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
