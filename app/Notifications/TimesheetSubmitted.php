<?php

namespace App\Notifications;

use App\Models\Timesheet;

/** A timesheet is waiting for the approver. */
class TimesheetSubmitted extends AppNotification
{
    public function __construct(public Timesheet $timesheet, public string $ownerName) {}

    public function event(): string
    {
        return 'timesheet';
    }

    public function title(): string
    {
        return "{$this->ownerName} submitted a timesheet";
    }

    public function body(): ?string
    {
        return 'Week of '.$this->timesheet->week_start->toFormattedDateString().', '.round($this->timesheet->total_min / 60, 1).' hours.';
    }

    public function organizationId(): ?int
    {
        return $this->timesheet->organization_id;
    }

    public function url(): string
    {
        return route('timesheets.team');
    }

    public function actionText(): string
    {
        return 'Review';
    }
}
