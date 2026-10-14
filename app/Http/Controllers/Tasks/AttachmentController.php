<?php

namespace App\Http\Controllers\Tasks;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\Task;
use App\Services\AttachmentService;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    public function __construct(private CurrentOrganization $current, private AttachmentService $attachments) {}

    public function store(Request $request, Task $task): RedirectResponse
    {
        abort_if($task->deleted_at !== null, 404);
        Gate::authorize('attach', $task);

        $request->validate(['files' => ['required', 'array', 'max:'.AttachmentService::MAX_PER_TASK], 'files.*' => ['file']]);

        foreach ($request->file('files') as $file) {
            $this->attachments->add($task, $this->current->membership, $file);
        }

        return back()->with('success', 'File uploaded.');
    }

    /** Files are private: served only to people who can see the task. Images can be shown inline; everything else downloads. */
    public function download(Request $request, Attachment $attachment): StreamedResponse
    {
        $task = Task::find($attachment->attachable_id);
        abort_unless($attachment->attachable_type === Task::class && $task && Gate::allows('view', $task), 404);
        abort_unless($attachment->exists(), 404);

        $disk = Storage::disk($attachment->disk);

        if ($request->boolean('inline') && $attachment->isImage()) {
            return $disk->response($attachment->path, $attachment->original_name, [
                'Content-Type' => $attachment->mime,
                'Content-Disposition' => 'inline; filename="'.addslashes($attachment->original_name).'"',
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "default-src 'none'; img-src 'self'; sandbox",
            ]);
        }

        return $disk->download($attachment->path, $attachment->original_name, ['X-Content-Type-Options' => 'nosniff']);
    }

    public function destroy(Attachment $attachment): RedirectResponse
    {
        $task = Task::find($attachment->attachable_id);
        abort_unless($task && Task::visibleTo($this->current->membership)->whereKey($task->id)->exists(), 404);
        Gate::authorize('deleteAttachment', $attachment);

        $this->attachments->delete($attachment, $this->current->membership);

        return back()->with('success', 'File removed.');
    }
}
