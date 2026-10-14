<?php

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\Gate;

/** Shared bits of the project screens: the header every project page shows, and what the viewer may do. */
abstract class ProjectBaseController extends Controller
{
    public function __construct(protected CurrentOrganization $current) {}

    /** @return array<string, mixed> */
    protected function header(Project $project): array
    {
        return [
            'id' => $project->id,
            'key' => $project->key,
            'name' => $project->name,
            'status' => $project->status,
            'is_archived' => $project->isArchived(),
            'abilities' => [
                'update' => Gate::allows('update', $project),
                'change_status' => Gate::allows('changeStatus', $project),
                'delete' => Gate::allows('delete', $project),
                'manage_members' => Gate::allows('manageMembers', $project),
                'manage_workflow' => Gate::allows('manageWorkflow', $project),
                'manage_settings' => Gate::allows('manageSettings', $project),
                'manage_labels' => Gate::allows('manageLabels', $project),
            ],
        ];
    }
}
