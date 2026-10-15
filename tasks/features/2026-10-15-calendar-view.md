# Feature: Calendar view

- **Status:** Planned
- **Requested:** 2026-10-15
- **Priority:** High · **Effort:** Medium
- **Depends on:** none
- **Spec file:** `tasks/features/2026-10-15-calendar-view.md`
- **Related master task items:** `tasks/TASKS.md` Phase 13

## 1. Summary

A month/week calendar of tasks by due date (and sprint dates), with drag to change a date.

## 2. Goals and non-goals

- Goal: Month and week layouts with tasks as chips on their due date
- Goal: Sprint start/end and project end shown as markers
- Goal: Drag a chip to another day to change the due date; click opens the task panel
- Goal: Same filters as the board and list (mine, assignee, type, priority, label)
- Not in this change: Syncing to Google/Outlook (later), recurring-meeting scheduling

## 3. Behaviour (the spec)

| Who (role / permission / data scope) | Can do | Cannot do |
|---|---|---|
| Anyone who can view the tasks | See due-dated tasks they may see; filter | See tasks outside their visibility |
| Anyone who can edit the task | Drag to change the due date | Drag when the project is archived or the task finished |

Rules (cite `WORKFLOW.md` where one applies, and new rules this feature introduces):

- A task with a start and due date spans the days between
- Overdue open tasks are red; done tasks are muted
- Weeks start on Monday (as timesheets); working days from the company setting are emphasised

## 4. Data

No schema change; add an index on `tasks(project_id, due_date)` if the query plan needs it.

## 5. Impact analysis

- [x] **Tenancy**: company scoping, tenant isolation of every new query and route binding
  - Query through `Task::visibleTo` in the current company
- [x] **Permissions**: new permission names (`PermissionCatalog`, `DefaultRoleTemplate`), policy changes, data scope, project membership, external stakeholders
  - Reuses view/update policies; no new permission
- [x] **Models and services**: new or changed classes, transactions, soft deletes
  - `TaskFilters` reused; new `CalendarController`
- [x] **Routes and controllers**: new routes, validation, authorization
  - `GET projects/{project}/calendar` and a cross-project `GET calendar` for My Work
- [x] **Screens**: pages and components (`resources/js/Pages`, `Components/UI`), empty states, mobile layout, keyboard use
  - `Pages/Calendar/Index.vue`, a calendar grid component, tab added to `ProjectHeader.vue`
- [x] **Existing behaviour at risk**: features that read or write the same data (board, list, backlog, sprints, time, reports, dashboards, search)
  - Due-date change must go through `TaskService::update` (activity log and notifications)
- [ ] **Notifications**: new events, recipients, preferences (`NotificationEvents`)
- [ ] **Audit log**: admin or security actions to record (`AuditLogger`)
- [x] **Reports and dashboards**: numbers that change, caches (`DashboardService`)
  - None
- [ ] **Queue, mail, scheduler**
- [x] **Seed data**: `DemoSeeder` / `FeatureTestSeeder` updated so the feature can be tried
  - Spread due dates over the month in `DemoSeeder`
- [x] **Docs**: `WORKFLOW.md`, `DATABASE.md`, `PLAN.md`
  - WORKFLOW section 12 screen map
- [x] **Performance**: queries per page, indexes, large lists
  - Fetch only the visible date range; cap items per day with a "+N more"

Risks and how they are handled:

- Many tasks on one day: cap and expand
- Time zones: use company timezone for "today"

## 6. Task list

Order: schema, services and rules, permissions and policies, routes and controllers, screens, notifications and audit, seed data, tests, docs.

- [ ] 6.1 Calendar data endpoint (range, filters, visibility)
- [ ] 6.2 Month/week grid component with chips, overdue/done styling, markers
- [ ] 6.3 Drag to change due date through `TaskService::update`
- [ ] 6.4 Project tab and a personal "My calendar"
- [ ] 6.5 Phone layout (agenda list)
- [ ] 6.6 Tests: range, visibility per role, drag update, archived refusal, tenant isolation
- [ ] 6.7 Seed spread and docs

## 7. Verification (fill in when done)

- New tests:
- Full test run: `php artisan test`
- `vendor/bin/pint --dirty`, `vendor/bin/phpstan analyse`, `npm run build`
- Checked in the browser (pages and roles):
- Known gaps / follow-ups:
