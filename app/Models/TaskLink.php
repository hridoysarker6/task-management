<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A directional link between two tasks. Only one row is stored per pair;
 * the other side's wording ("is blocked by", "is duplicated by") is derived when reading.
 *
 * @property int $id
 * @property int $source_task_id
 * @property int $target_task_id
 * @property string $type
 */
#[Fillable(['source_task_id', 'target_task_id', 'type', 'created_by'])]
class TaskLink extends Model
{
    /** How each link reads from the source side and from the target side. */
    public const WORDING = [
        'blocks' => ['blocks', 'is blocked by'],
        'relates_to' => ['relates to', 'relates to'],
        'duplicates' => ['duplicates', 'is duplicated by'],
        'clones' => ['clones', 'is cloned by'],
    ];

    /** @return BelongsTo<Task, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'source_task_id')->withTrashed();
    }

    /** @return BelongsTo<Task, $this> */
    public function target(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'target_task_id')->withTrashed();
    }
}
