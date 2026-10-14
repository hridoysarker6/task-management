<?php

namespace App\Support;

/**
 * The default role template and permission matrix (WORKFLOW.md sections 4.1 and 4.3).
 * Seeded as roles with organization_id = null, then copied into each approved company.
 */
class DefaultRoleTemplate
{
    /** @return list<array{slug:string,name:string,level:int,data_scope:string,is_external:bool,is_locked:bool,description:?string}> */
    public static function roles(): array
    {
        return [
            ['slug' => 'company-admin', 'name' => 'Company Admin', 'level' => 0, 'data_scope' => 'organization', 'is_external' => false, 'is_locked' => true, 'description' => 'Full control of the company. Cannot be edited.'],
            ['slug' => 'hod', 'name' => 'HOD', 'level' => 10, 'data_scope' => 'department', 'is_external' => false, 'is_locked' => false, 'description' => 'Head of Department'],
            ['slug' => 'dgm', 'name' => 'DGM', 'level' => 20, 'data_scope' => 'team', 'is_external' => false, 'is_locked' => false, 'description' => 'Deputy General Manager'],
            ['slug' => 'agm', 'name' => 'AGM', 'level' => 30, 'data_scope' => 'team', 'is_external' => false, 'is_locked' => false, 'description' => 'Assistant General Manager'],
            ['slug' => 'dm', 'name' => 'DM', 'level' => 40, 'data_scope' => 'team', 'is_external' => false, 'is_locked' => false, 'description' => 'Deputy Manager'],
            ['slug' => 'project-manager', 'name' => 'Project Manager', 'level' => 50, 'data_scope' => 'team', 'is_external' => false, 'is_locked' => false, 'description' => 'Runs projects across teams'],
            ['slug' => 'team-lead', 'name' => 'Team Lead', 'level' => 60, 'data_scope' => 'team', 'is_external' => false, 'is_locked' => false, 'description' => null],
            ['slug' => 'developer', 'name' => 'Developer', 'level' => 70, 'data_scope' => 'own', 'is_external' => false, 'is_locked' => false, 'description' => null],
            ['slug' => 'internal-stakeholder', 'name' => 'Internal Stakeholder', 'level' => 80, 'data_scope' => 'own', 'is_external' => false, 'is_locked' => false, 'description' => 'Raises and tracks requests'],
            ['slug' => 'external-stakeholder', 'name' => 'External Stakeholder', 'level' => 80, 'data_scope' => 'own', 'is_external' => true, 'is_locked' => false, 'description' => 'Client or vendor; sees only their own projects'],
        ];
    }

    /**
     * Default permissions per role slug. Company Admin resolves to every permission at runtime (locked role).
     *
     * @return array<string, list<string>>
     */
    public static function permissions(): array
    {
        $all = PermissionCatalog::names();

        // Role groups, from highest to lowest.
        $admin = ['company-admin'];
        $hod = [...$admin, 'hod'];
        $senior = [...$hod, 'dgm'];
        $managers = [...$senior, 'agm', 'dm'];
        $pm = [...$managers, 'project-manager'];
        $lead = [...$pm, 'team-lead'];
        $worker = [...$lead, 'developer'];
        $internal = [...$worker, 'internal-stakeholder'];
        $everyone = [...$internal, 'external-stakeholder'];

        // permission => roles that get it by default
        $grants = [
            // Dashboard
            'dashboard.view' => $everyone,
            'dashboard.team' => $lead,
            'dashboard.department' => $senior,
            'dashboard.organization' => $admin,
            // Company settings, roles
            'company.view_settings' => $admin,
            'company.edit_settings' => $admin,
            'role.view' => $admin,
            'role.create' => $admin,
            'role.edit' => $admin,
            'role.delete' => $admin,
            'permission.assign' => $admin,
            // Departments
            'department.view' => $internal,
            'department.create' => $hod,
            'department.edit' => $hod,
            'department.delete' => $hod,
            // Users
            'user.view' => $managers,
            'user.invite' => $managers,
            'user.import' => $managers,
            'user.edit' => $managers,
            'user.change_role' => $senior,
            'user.change_manager' => $senior,
            'user.offboard' => $hod,
            'user.rehire' => $hod,
            'user.view_former' => $hod,
            // Org chart
            'orgchart.view' => $internal,
            // Projects
            'project.view' => $everyone,
            'project.create' => $pm,
            'project.edit' => $pm,
            'project.archive' => $pm,
            'project.delete' => $hod,
            'project.manage_members' => $lead,
            'project.manage_workflow' => $lead,
            'project.manage_settings' => $lead,
            // Tasks
            'task.view' => $everyone,
            'task.create' => $everyone,
            'task.edit_own' => $everyone,
            'task.delete_own' => $everyone,
            'task.edit_any' => $lead,
            'task.assign' => $lead,
            'task.link' => $lead,
            'task.bulk_update' => $lead,
            'task.move_project' => $pm,
            'task.change_status' => $worker,
            'task.close' => $lead,
            'task.reopen' => [...$lead, 'internal-stakeholder', 'external-stakeholder'],
            'task.triage' => ['company-admin', 'hod', 'dm', 'project-manager', 'team-lead'],
            'task.delete_any' => ['company-admin', 'hod', 'project-manager'],
            // Comments & attachments
            'comment.create' => $everyone,
            'comment.edit_own' => $everyone,
            'comment.delete_own' => $everyone,
            'comment.delete_any' => ['company-admin', 'hod', 'project-manager'],
            'attachment.upload' => $everyone,
            'attachment.delete_own' => $everyone,
            'attachment.delete_any' => ['company-admin', 'hod', 'project-manager'],
            // Labels
            'label.manage' => $lead,
            // Backlog & sprints
            'backlog.view' => $internal,
            'backlog.reorder' => ['company-admin', 'dm', 'project-manager', 'team-lead'],
            'sprint.view' => $everyone,
            'sprint.create' => ['company-admin', 'dm', 'project-manager', 'team-lead'],
            'sprint.edit' => ['company-admin', 'dm', 'project-manager', 'team-lead'],
            'sprint.start' => ['company-admin', 'dm', 'project-manager', 'team-lead'],
            'sprint.complete' => ['company-admin', 'dm', 'project-manager', 'team-lead'],
            'sprint.delete' => ['company-admin', 'dm', 'project-manager', 'team-lead'],
            // Time tracking
            'worklog.create' => $worker,
            'worklog.edit_own' => $worker,
            'worklog.view_team' => $lead,
            'worklog.edit_any' => $admin,
            'timesheet.approve' => ['company-admin', 'dm', 'team-lead'],
            // Reports
            'report.view' => $lead,
            'report.team' => $lead,
            'report.export' => $lead,
            'report.department' => $senior,
            'report.organization' => $senior,
            // Audit
            'audit.view' => $hod,
        ];

        $matrix = array_fill_keys(array_column(self::roles(), 'slug'), []);
        foreach ($all as $permission) {
            foreach ($grants[$permission] ?? [] as $slug) {
                $matrix[$slug][] = $permission;
            }
        }

        return $matrix;
    }
}
