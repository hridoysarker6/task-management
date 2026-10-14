<?php

namespace App\Services;

use App\Models\Comment;
use App\Models\Membership;
use App\Models\Scopes\OrganizationScope;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Comments on tasks. Each is Internal (team only) or Public (also visible to external stakeholders).
 * Mentions are written in the text as `@[Name](m:ID)`; only people who can already see the task count as mentioned.
 */
class CommentService
{
    public const MAX_LENGTH = 10000;

    private const MENTION = '/@\[([^\]]{1,100})\]\(m:(\d+)\)/';

    public function __construct(private TaskService $tasks, private TaskActivityLogger $activity) {}

    /**
     * @throws ValidationException
     */
    public function add(Task $task, Membership $author, string $body, bool $internal = false): Comment
    {
        $body = $this->clean($body);

        // External people can only ever write public comments.
        $internal = $author->isExternal() ? false : $internal;

        return DB::transaction(function () use ($task, $author, $body, $internal) {
            $comment = Comment::create([
                'task_id' => $task->id,
                'membership_id' => $author->id,
                'body' => $body,
                'is_internal' => $internal,
            ]);

            $this->syncMentions($comment, $task, $author);

            // Writing on a task means you want to hear about it.
            $this->tasks->watch($task, $author);
            $this->activity->log($task, 'commented', meta: ['comment_id' => $comment->id, 'is_internal' => $internal], actorId: $author->id);

            return $comment;
        });
    }

    /** Edit the text (and the Internal/Public choice) of your own comment. */
    public function update(Comment $comment, Membership $editor, string $body, ?bool $internal = null): Comment
    {
        $comment->body = $this->clean($body);
        $comment->edited_at = now();

        if ($internal !== null && ! $editor->isExternal()) {
            $comment->is_internal = $internal;
        }

        $comment->save();
        $this->syncMentions($comment, Task::withTrashed()->findOrFail($comment->task_id), $editor);

        return $comment;
    }

    public function delete(Comment $comment): void
    {
        $comment->delete();
    }

    /** The members named in a piece of text: [id => name]. */
    public static function mentionedIds(string $body): array
    {
        preg_match_all(self::MENTION, $body, $matches, PREG_SET_ORDER);

        return collect($matches)->mapWithKeys(fn ($m) => [(int) $m[2] => $m[1]])->all();
    }

    // --- helpers ---------------------------------------------------------------------------------

    /** @throws ValidationException */
    private function clean(string $body): string
    {
        $body = trim($body);

        if ($body === '') {
            throw ValidationException::withMessages(['body' => 'Write something first.']);
        }
        if (mb_strlen($body) > self::MAX_LENGTH) {
            throw ValidationException::withMessages(['body' => 'A comment can have up to '.self::MAX_LENGTH.' characters.']);
        }

        return $body;
    }

    private function syncMentions(Comment $comment, Task $task, Membership $author): void
    {
        $ids = array_keys(self::mentionedIds($comment->body));

        $mentioned = $ids === [] ? collect() : Membership::withoutGlobalScope(OrganizationScope::class)->with('role')
            ->where('organization_id', $task->organization_id)->where('status', Membership::ACTIVE)->whereIn('id', $ids)->get()
            // Mentioning must never open a private task to someone: they have to see it already.
            ->filter(fn (Membership $m) => Task::withoutGlobalScope(OrganizationScope::class)->visibleTo($m)->whereKey($task->id)->exists())
            // An internal comment stays inside the team.
            ->filter(fn (Membership $m) => ! ($comment->is_internal && $m->isExternal()));

        $comment->mentions()->sync($mentioned->pluck('id')->all());

        foreach ($mentioned as $member) {
            $this->tasks->watch($task, $member);
        }
    }
}
