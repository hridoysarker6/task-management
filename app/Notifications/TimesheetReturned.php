<?php

namespace App\Notifications;

use App\Models\Timesheet;

/** The approver sent a timesheet back with a comment. */
class TimesheetReturned extends AppNotification
{
    public function __construct(public Timesheet $timesheet, public ?string $reviewerName, public string $note) {}

    public function event(): string
    {
        return 'timesheet';
    }

    public function title(): string
    {
        return 'Your timesheet was returned';
    }

    public function body(): ?string
    {
        return ($this->reviewerName ?? 'Your approver').' says: '.$this->note;
    }

    public function organizationId(): ?int
    {
        return $this->timesheet->organization_id;
    }

    public function url(): string
    {
        return route('timesheet', ['week' => $this->timesheet->week_start->toDateString()]);
    }
}
