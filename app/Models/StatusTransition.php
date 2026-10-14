<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** One allowed move between two statuses of a project, optionally needing a permission (e.g. task.close). */
#[Fillable(['project_id', 'from_status_id', 'to_status_id', 'required_permission'])]
class StatusTransition extends Model
{
    public $timestamps = false;
}
