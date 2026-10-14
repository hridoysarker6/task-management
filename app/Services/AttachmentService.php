<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\Membership;
use App\Models\Task;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/** Files on tasks: size and type limits, private storage, an activity line for every upload. */
class AttachmentService
{
    public const MAX_KB = 10240;

    public const MAX_PER_TASK = 25;

    /** No SVG or HTML: both can carry scripts. */
    public const EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'txt', 'csv', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip'];

    public function __construct(private TaskActivityLogger $activity) {}

    /** @throws ValidationException */
    public function add(Task $task, Membership $uploader, UploadedFile $file): Attachment
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, self::EXTENSIONS, true)) {
            throw ValidationException::withMessages(['file' => 'This type of file is not allowed. Allowed: '.implode(', ', self::EXTENSIONS).'.']);
        }
        if ($file->getSize() > self::MAX_KB * 1024) {
            throw ValidationException::withMessages(['file' => 'Files can be up to '.(self::MAX_KB / 1024).' MB.']);
        }
        if (Attachment::where('attachable_type', Task::class)->where('attachable_id', $task->id)->count() >= self::MAX_PER_TASK) {
            throw ValidationException::withMessages(['file' => 'A task can have up to '.self::MAX_PER_TASK.' files.']);
        }

        $path = $file->storeAs("attachments/{$task->organization_id}/{$task->id}", bin2hex(random_bytes(16)).'.'.$extension, 'local');

        $attachment = Attachment::create([
            'organization_id' => $task->organization_id,
            'attachable_type' => Task::class,
            'attachable_id' => $task->id,
            'membership_id' => $uploader->id,
            'disk' => 'local',
            'path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 250),
            'mime' => $file->getMimeType() ?: $file->getClientMimeType(),
            'size_bytes' => $file->getSize(),
        ]);

        $this->activity->log($task, 'attachment_added', 'attachment', null, $attachment->original_name, actorId: $uploader->id);

        return $attachment;
    }

    public function delete(Attachment $attachment, ?Membership $by = null): void
    {
        $attachment->delete(); // the file stays on disk so the record can be restored

        if ($attachment->attachable_type === Task::class && ($task = Task::withTrashed()->find($attachment->attachable_id))) {
            $this->activity->log($task, 'attachment_removed', 'attachment', $attachment->original_name, null, actorId: $by?->id);
        }
    }
}
