<?php

namespace App\Support;

/**
 * The kinds of notification a person can switch on or off (WORKFLOW.md section 10), with what they get by default.
 * Each entry: label shown on the settings page, and whether it goes to the bell (app) and to email by default.
 */
class NotificationEvents
{
    /** @var array<string, array{label:string,description:string,app:bool,mail:bool}> */
    public const EVENTS = [
        'task_assigned' => ['label' => 'Assigned to me', 'description' => 'A task is assigned to you, or taken away from you.', 'app' => true, 'mail' => true],
        'task_in_triage' => ['label' => 'New request to triage', 'description' => 'A request is waiting for you to accept or reject it.', 'app' => true, 'mail' => false],
        'triage_decision' => ['label' => 'Request accepted or rejected', 'description' => 'The outcome of a request you raised.', 'app' => true, 'mail' => true],
        'status_changed' => ['label' => 'Status changes', 'description' => 'A task you raised, work on or watch moves to another status.', 'app' => true, 'mail' => false],
        'task_closed' => ['label' => 'Closed or reopened', 'description' => 'A task you raised, work on or watch is closed or reopened.', 'app' => true, 'mail' => true],
        'comment' => ['label' => 'Comments', 'description' => 'Someone comments on a task you watch.', 'app' => true, 'mail' => false],
        'mention' => ['label' => 'Mentions', 'description' => 'Someone mentions you with @.', 'app' => true, 'mail' => true],
        'due_tomorrow' => ['label' => 'Due tomorrow', 'description' => 'A daily reminder of your tasks due the next day.', 'app' => true, 'mail' => true],
        'overdue' => ['label' => 'Overdue', 'description' => 'A daily reminder of overdue tasks, yours and your team’s.', 'app' => true, 'mail' => true],
        'sprint' => ['label' => 'Sprints', 'description' => 'A sprint of one of your projects starts or is completed.', 'app' => true, 'mail' => false],
        'timesheet' => ['label' => 'Timesheets', 'description' => 'A timesheet is submitted for your approval, or returned to you.', 'app' => true, 'mail' => false],
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::EVENTS);
    }
}
