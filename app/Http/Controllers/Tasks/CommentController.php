<?php

namespace App\Http\Controllers\Tasks;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\Task;
use App\Services\CommentService;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CommentController extends Controller
{
    public function __construct(private CurrentOrganization $current, private CommentService $comments) {}

    public function store(Request $request, Task $task): RedirectResponse
    {
        abort_if($task->deleted_at !== null, 404);
        Gate::authorize('comment', $task);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:'.CommentService::MAX_LENGTH],
            'is_internal' => ['sometimes', 'boolean'],
        ]);

        $this->comments->add($task, $this->current->membership, $data['body'], (bool) ($data['is_internal'] ?? false));

        return back()->with('success', 'Comment added.');
    }

    public function update(Request $request, Comment $comment): RedirectResponse
    {
        $this->assertVisibleTo($comment);
        Gate::authorize('editComment', $comment);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:'.CommentService::MAX_LENGTH],
            'is_internal' => ['sometimes', 'boolean'],
        ]);

        $this->comments->update($comment, $this->current->membership, $data['body'], $data['is_internal'] ?? null);

        return back()->with('success', 'Comment updated.');
    }

    public function destroy(Comment $comment): RedirectResponse
    {
        $this->assertVisibleTo($comment);
        Gate::authorize('deleteComment', $comment);

        $this->comments->delete($comment);

        return back()->with('success', 'Comment deleted.');
    }

    /** A comment is only reachable through a task the person can see, and external people never reach internal ones. */
    private function assertVisibleTo(Comment $comment): void
    {
        $task = Task::find($comment->task_id);
        $me = $this->current->membership;

        abort_unless($task && Task::visibleTo($me)->whereKey($task->id)->exists(), 404);
        abort_if($me->isExternal() && $comment->is_internal, 404);
    }
}
