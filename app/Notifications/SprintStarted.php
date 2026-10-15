<?php

namespace App\Notifications;

use App\Models\Sprint;

/** A sprint of one of the person's projects has started. */
class SprintStarted extends AppNotification
{
    public function __construct(public Sprint $sprint) {}

    public function event(): string
    {
        return 'sprint';
    }

    public function title(): string
    {
        return "{$this->sprint->name} has started";
    }

    public function body(): ?string
    {
        return ($this->sprint->goal ? "Goal: {$this->sprint->goal}\n" : '').'Ends '.$this->sprint->end_date->toFormattedDateString().'.';
    }

    public function organizationId(): ?int
    {
        return $this->sprint->organization_id;
    }

    public function url(): string
    {
        return route('projects.board', $this->sprint->project_id);
    }
}
