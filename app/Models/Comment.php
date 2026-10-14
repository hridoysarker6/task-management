<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A comment on a task. Internal comments are hidden from external stakeholders.
 * Mentions are written into the body as `@[Name](m:ID)` and mirrored in comment_mentions.
 *
 * @property int $id
 * @property int $task_id
 * @property int $membership_id
 * @property string $body
 * @property bool $is_internal
 * @property Carbon|null $edited_at
 * @property Carbon $created_at
 */
#[Fillable(['task_id', 'membership_id', 'body', 'is_internal', 'edited_at'])]
class Comment extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'is_internal' => 'boolean',
            'edited_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class)->withTrashed();
    }

    /** @return BelongsTo<Membership, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'membership_id')->withoutGlobalScopes()->withTrashed();
    }

    /** @return BelongsToMany<Membership, $this> */
    public function mentions(): BelongsToMany
    {
        return $this->belongsToMany(Membership::class, 'comment_mentions')->withoutGlobalScopes()->withTrashed();
    }
}
