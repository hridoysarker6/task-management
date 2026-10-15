<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\Scopes\OrganizationScope;
use App\Models\Task;
use App\Services\Notifier;
use Illuminate\Console\Command;

/**
 * The daily reminders: tasks due tomorrow (to the assignee) and tasks overdue (to the assignee and their manager).
 * Safe to run twice in a day: a person is told about a task at most once a day.
 */
class SendTaskReminders extends Command
{
    protected $signature = 'tasks:send-reminders';

    protected $description = 'Remind people of tasks due tomorrow and tasks that are overdue';

    public function handle(Notifier $notifier): int
    {
        $due = 0;
        $late = 0;

        foreach (Organization::where('status', Organization::ACTIVE)->pluck('id') as $organizationId) {
            $open = fn () => Task::withoutGlobalScope(OrganizationScope::class)->where('organization_id', $organizationId)
                ->whereNotNull('assignee_id')->where('type', '!=', 'epic')
                ->whereHas('status', fn ($s) => $s->whereIn('category', ['todo', 'in_progress']))
                ->with('project');

            $open()->whereDate('due_date', today()->addDay())->each(function (Task $t) use ($notifier, &$due) {
                $due += $notifier->dueTomorrow($t);
            });

            $open()->whereDate('due_date', '<', today())->each(function (Task $t) use ($notifier, &$late) {
                $late += $notifier->overdue($t);
            });
        }

        $this->info("Told {$due} about tasks due tomorrow and {$late} about overdue tasks.");

        return self::SUCCESS;
    }
}
