# Feature: Task templates and recurring tasks

- **Status:** Planned
- **Requested:** 2026-10-15
- **Priority:** Medium · **Effort:** Large
- **Depends on:** none
- **Spec file:** `tasks/features/2026-10-15-task-templates-and-recurring.md`
- **Related master task items:** `tasks/TASKS.md` Phase 13

## 1. Summary

Save a task (with sub-tasks) as a template and create it again in one click; let a task repeat on a schedule (daily, weekly, monthly).

## 2. Goals and non-goals

- Goal: Create a template from any task, including its sub-tasks, labels and estimates
- Goal: Create tasks from a template in any project the person works in
- Goal: Recurring rule on a task: every N days/weeks/months, on which weekday, until a date or forever
- Goal: The scheduler creates the next copy when due; shows in the next sprint/backlog as chosen
- Not in this change: Complex calendars (nth weekday of month), time zones per task

## 3. Behaviour (the spec)

| Who (role / permission / data scope) | Can do | Cannot do |
|---|---|---|
| Anyone who can create tasks in the project | Use a template; set a recurrence on a task they can edit | Use a template of another project's private details they cannot see |
| Project manager | Manage project templates; set a company-wide template | - |

Rules (cite `WORKFLOW.md` where one applies, and new rules this feature introduces):

- Templates and recurrence are per project and company
- The recurring copy starts in the backlog (or To Do in the active sprint if chosen) with the same assignee if still active
- A paused or archived project creates nothing
- Recurrence is idempotent: running the scheduler twice never creates two copies

## 4. Data

New `task_templates` (organization_id, project_id, name, payload json, created_by, soft delete). New `task_recurrences` (organization_id, task_id/template_id, frequency, interval, weekday, ends_on, next_run_on, last_created_task_id).

## 5. Impact analysis

- [x] **Tenancy**: company scoping, tenant isolation of every new query and route binding
  - Both tables carry `organization_id`; scheduler iterates companies without leaking context
- [x] **Permissions**: new permission names (`PermissionCatalog`, `DefaultRoleTemplate`), policy changes, data scope, project membership, external stakeholders
  - New `template.manage` (PM and leads) and reuse `createTask`; add to `PermissionCatalog` and `DefaultRoleTemplate`
- [x] **Models and services**: new or changed classes, transactions, soft deletes
  - `TemplateService`, `RecurrenceService`, `TaskService::create` reuse
- [x] **Routes and controllers**: new routes, validation, authorization
  - Template CRUD, "create from template", recurrence set/clear
- [x] **Screens**: pages and components (`resources/js/Pages`, `Components/UI`), empty states, mobile layout, keyboard use
  - Template picker in the Create dialog, template manager page, recurrence control on the task page
- [x] **Existing behaviour at risk**: features that read or write the same data (board, list, backlog, sprints, time, reports, dashboards, search)
  - Numbering (`TaskNumberService`), sprint history, notifications for assignment, time totals must not be copied
- [x] **Notifications**: new events, recipients, preferences (`NotificationEvents`)
  - Assignee gets `task_assigned` for the new copy
- [x] **Audit log**: admin or security actions to record (`AuditLogger`)
  - Template create/delete recorded
- [x] **Reports and dashboards**: numbers that change, caches (`DashboardService`)
  - Counts of created tasks change slightly; no new report
- [x] **Queue, mail, scheduler**
  - New daily command `tasks:create-recurring` in `routes/console.php`; add to `start.bat` notes
- [x] **Seed data**: `DemoSeeder` / `FeatureTestSeeder` updated so the feature can be tried
  - A template and a weekly recurring task in `FeatureTestSeeder`
- [x] **Docs**: `WORKFLOW.md`, `DATABASE.md`, `PLAN.md`
  - DATABASE section 6, WORKFLOW 6, README scheduler
- [x] **Performance**: queries per page, indexes, large lists
  - Index `next_run_on`

Risks and how they are handled:

- Runaway creation: cap copies per run and per task, log each creation
- Copying personal data: copy structure only, never comments, time or attachments

## 6. Task list

Order: schema, services and rules, permissions and policies, routes and controllers, screens, notifications and audit, seed data, tests, docs.

- [ ] 6.1 Migration for `task_templates` and `task_recurrences`
- [ ] 6.2 `template.manage` permission and role defaults
- [ ] 6.3 `TemplateService` (save from task, apply to project) incl. sub-tasks and labels
- [ ] 6.4 `RecurrenceService` + `tasks:create-recurring` command, idempotent, scheduled daily
- [ ] 6.5 UI: template picker, manager page, recurrence control
- [ ] 6.6 Audit and notifications wiring
- [ ] 6.7 Tests: apply template, sub-task copy, permissions, idempotent scheduler, paused/archived project, tenant isolation
- [ ] 6.8 Seed data and docs

## 7. Verification (fill in when done)

- New tests:
- Full test run: `php artisan test`
- `vendor/bin/pint --dirty`, `vendor/bin/phpstan analyse`, `npm run build`
- Checked in the browser (pages and roles):
- Known gaps / follow-ups:
