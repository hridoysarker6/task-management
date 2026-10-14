<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Support\Facades\DB;

/**
 * Hands out the next task number of a project (ECP-1, ECP-2, ...). The project row is locked while the counter
 * moves, so two people creating a task at the same moment can never get the same number.
 */
class TaskNumberService
{
    /** Must run inside a transaction (it is, when called from TaskService::create). */
    public function next(Project $project): int
    {
        return DB::transaction(function () use ($project) {
            // Lock the row and read the counter that is really in the database, not the one on this instance.
            $counter = (int) DB::table('projects')->where('id', $project->id)->lockForUpdate()->value('task_counter');

            $next = $counter + 1;
            DB::table('projects')->where('id', $project->id)->update(['task_counter' => $next]);
            $project->task_counter = $next;
            $project->syncOriginalAttribute('task_counter');

            return $next;
        });
    }
}
