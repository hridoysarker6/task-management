<?php

namespace App\Notifications;

use App\Models\Sprint;

/** A sprint of one of the person's projects is complete. */
class SprintCompleted extends AppNotification
{
    /** @param array{completed:int,carried:int,dropped:int,points:float} $summary */
    public function __construct(public Sprint $sprint, public array $summary) {}

    public function event(): string
    {
        return 'sprint';
    }

    public function title(): string
    {
        return "{$this->sprint->name} is complete";
    }

    public function body(): ?string
    {
        return "{$this->summary['completed']} done ({$this->summary['points']} points), {$this->summary['carried']} unfinished moved on.";
    }

    public function organizationId(): ?int
    {
        return $this->sprint->organization_id;
    }

    public function url(): string
    {
        return route('reports.index', ['report' => 'sprint', 'project_id' => $this->sprint->project_id, 'sprint_id' => $this->sprint->id]);
    }
}
