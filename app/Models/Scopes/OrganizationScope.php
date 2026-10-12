<?php

namespace App\Models\Scopes;

use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class OrganizationScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        // Outside a company context (console, System Admin area) the scope is inactive;
        // those callers must filter by organization explicitly.
        if ($id = app(CurrentOrganization::class)->id()) {
            $builder->where($model->qualifyColumn('organization_id'), $id);
        }
    }
}
