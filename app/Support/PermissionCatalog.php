<?php

namespace App\Support;

/**
 * The full permission catalog (WORKFLOW.md section 4.2): module => [name => label].
 * Seeded into the `permissions` table; the order here is the order shown in the matrix.
 */
class PermissionCatalog
{
    /** @return array<string, array<string, string>> */
    public static function modules(): array
    {
        return [
            'Dashboard' => [
                'dashboard.view' => 'View dashboard',
                'dashboard.team' => 'View team dashboard',
                'dashboard.department' => 'View department dashboard',
                'dashboard.organization' => 'View company dashboard',
            ],
            'Company settings' => [
                'company.view_settings' => 'View company settings',
                'company.edit_settings' => 'Edit company settings',
            ],
            'Departments' => [
                'department.view' => 'View departments',
                'department.create' => 'Create departments',
                'department.edit' => 'Edit departments',
                'department.delete' => 'Delete departments',
            ],
            'Users' => [
                'user.view' => 'View users',
                'user.invite' => 'Invite users',
                'user.import' => 'Import users',
                'user.edit' => 'Edit users',
                'user.offboard' => 'Offboard users',
                'user.rehire' => 'Rehire former users',
                'user.view_former' => 'View former employees',
                'user.change_role' => 'Change a user\'s role',
                'user.change_manager' => 'Change a user\'s manager',
            ],
            'Roles & permissions' => [
                'role.view' => 'View roles',
                'role.create' => 'Create roles',
                'role.edit' => 'Edit roles',
                'role.delete' => 'Delete roles',
                'permission.assign' => 'Assign permissions',
            ],
            'Org chart' => [
                'orgchart.view' => 'View org chart',
            ],
            'Projects' => [
                'project.view' => 'View projects',
                'project.create' => 'Create projects',
                'project.edit' => 'Edit projects',
                'project.archive' => 'Archive projects',
                'project.delete' => 'Delete projects',
                'project.manage_members' => 'Manage project members',
                'project.manage_workflow' => 'Manage project workflow',
                'project.manage_settings' => 'Manage project settings',
            ],
            'Tasks' => [
                'task.view' => 'View tasks',
                'task.create' => 'Create tasks',
                'task.edit_own' => 'Edit own tasks',
                'task.edit_any' => 'Edit any task',
                'task.delete_own' => 'Delete own tasks',
                'task.delete_any' => 'Delete any task',
                'task.assign' => 'Assign tasks',
                'task.change_status' => 'Change task status',
                'task.close' => 'Close tasks (move to Done)',
                'task.reopen' => 'Reopen tasks',
                'task.triage' => 'Triage requests',
                'task.move_project' => 'Move tasks between projects',
                'task.bulk_update' => 'Bulk update tasks',
                'task.link' => 'Link tasks',
            ],
            'Comments' => [
                'comment.create' => 'Add comments',
                'comment.edit_own' => 'Edit own comments',
                'comment.delete_own' => 'Delete own comments',
                'comment.delete_any' => 'Delete any comment',
            ],
            'Attachments' => [
                'attachment.upload' => 'Upload attachments',
                'attachment.delete_own' => 'Delete own attachments',
                'attachment.delete_any' => 'Delete any attachment',
            ],
            'Labels' => [
                'label.manage' => 'Manage labels',
            ],
            'Backlog & sprints' => [
                'backlog.view' => 'View backlog',
                'backlog.reorder' => 'Reorder backlog',
                'sprint.view' => 'View sprints',
                'sprint.create' => 'Create sprints',
                'sprint.edit' => 'Edit sprints',
                'sprint.start' => 'Start sprints',
                'sprint.complete' => 'Complete sprints',
                'sprint.delete' => 'Delete sprints',
            ],
            'Time tracking' => [
                'worklog.create' => 'Log work',
                'worklog.edit_own' => 'Edit own work logs',
                'worklog.view_team' => 'View team work logs',
                'worklog.edit_any' => 'Edit any work log',
                'timesheet.approve' => 'Approve timesheets',
            ],
            'Reports' => [
                'report.view' => 'View reports',
                'report.team' => 'Team reports',
                'report.department' => 'Department reports',
                'report.organization' => 'Company reports',
                'report.export' => 'Export reports',
            ],
            'Audit' => [
                'audit.view' => 'View audit log',
            ],
        ];
    }

    /** @return list<string> */
    public static function names(): array
    {
        return collect(self::modules())->flatMap(fn ($perms) => array_keys($perms))->values()->all();
    }
}
