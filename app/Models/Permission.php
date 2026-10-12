<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'module', 'label', 'sort_order'])]
class Permission extends Model
{
    public $timestamps = false;
}
