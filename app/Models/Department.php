<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['organization_id', 'parent_id', 'name', 'code', 'head_membership_id'])]
class Department extends Model
{
    use BelongsToOrganization, HasFactory, SoftDeletes;
}
