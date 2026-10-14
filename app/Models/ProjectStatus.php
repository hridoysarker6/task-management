<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A board column / workflow state of one project.
 *
 * @property int $id
 * @property int $project_id
 * @property string $name
 * @property string $category
 * @property string $color
 * @property int $position
 * @property bool $is_initial
 * @property bool $show_on_board
 */
#[Fillable(['project_id', 'name', 'category', 'color', 'position', 'is_initial', 'show_on_board'])]
class ProjectStatus extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'is_initial' => 'boolean',
            'show_on_board' => 'boolean',
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** How many tasks sit in this status. Zero until the task module exists. */
    public function taskCount(): int
    {
        return Schema::hasTable('tasks') ? (int) DB::table('tasks')->where('status_id', $this->id)->whereNull('deleted_at')->count() : 0;
    }
}
