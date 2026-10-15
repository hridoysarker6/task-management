# Feature: Gantt / timeline view

- **Status:** Planned
- **Requested:** 2026-10-15
- **Priority:** Medium · **Effort:** Large
- **Depends on:** none
- **Spec file:** `tasks/features/2026-10-15-gantt-timeline.md`
- **Related master task items:** `tasks/TASKS.md` Phase 13

## 1. Summary

A timeline of epics, tasks and sprints with bars from start to due date and arrows for "blocks" dependencies.

## 2. Goals and non-goals

- Goal: Rows grouped by epic and sprint; bars from `start_date` to `due_date`
- Goal: Drag a bar to move it or its ends to resize (updates dates)
- Goal: Dependency arrows from `blocks` links; warn when a blocked task starts before its blocker ends
- Goal: Zoom: week, month, quarter; today line; project end marker
- Not in this change: Resource levelling, critical path calculation, baselines

## 3. Behaviour (the spec)

| Who (role / permission / data scope) | Can do | Cannot do |
|---|---|---|
| Anyone who can view the project | See the timeline for tasks they may see | - |
| Anyone who can edit tasks | Move or resize bars | Make `start_date` later than `due_date` |

Rules (cite `WORKFLOW.md` where one applies, and new rules this feature introduces):

- Tasks without dates appear in an "unscheduled" tray that can be dragged onto the timeline
- Moving a blocker does not move blocked tasks automatically; it only shows a conflict warning
- Date edits go through `TaskService::update` validation (`assertDates`)

## 4. Data

No schema change (uses `start_date`, `due_date`, `task_links`).

## 5. Impact analysis

- [x] **Tenancy**: company scoping, tenant isolation of every new query and route binding
  - Visible tasks and links through company scope
- [x] **Permissions**: new permission names (`PermissionCatalog`, `DefaultRoleTemplate`), policy changes, data scope, project membership, external stakeholders
  - Reuses existing abilities
- [x] **Models and services**: new or changed classes, transactions, soft deletes
  - `TimelineController`, link query for `blocks`
- [x] **Routes and controllers**: new routes, validation, authorization
  - `GET projects/{project}/timeline`
- [x] **Screens**: pages and components (`resources/js/Pages`, `Components/UI`), empty states, mobile layout, keyboard use
  - `Pages/Timeline/Index.vue`, a canvas/SVG bar chart with drag and resize, tab in `ProjectHeader.vue`
- [x] **Existing behaviour at risk**: features that read or write the same data (board, list, backlog, sprints, time, reports, dashboards, search)
  - Calendar and board date edits; sprint dates; task links page
- [ ] **Notifications**: new events, recipients, preferences (`NotificationEvents`)
- [ ] **Audit log**: admin or security actions to record (`AuditLogger`)
- [ ] **Reports and dashboards**: numbers that change, caches (`DashboardService`)
- [ ] **Queue, mail, scheduler**
- [ ] **Seed data**: `DemoSeeder` / `FeatureTestSeeder` updated so the feature can be tried
- [x] **Docs**: `WORKFLOW.md`, `DATABASE.md`, `PLAN.md`
  - WORKFLOW section 12
- [x] **Performance**: queries per page, indexes, large lists
  - Virtualise rows for 500+ tasks; load by date window

Risks and how they are handled:

- Complex interaction code: build read-only first, then add drag and resize
- Library choice: prefer a small custom SVG over a heavy dependency (check offline)

## 6. Task list

Order: schema, services and rules, permissions and policies, routes and controllers, screens, notifications and audit, seed data, tests, docs.

- [ ] 6.1 Timeline data endpoint (tasks, epics, sprints, links, window)
- [ ] 6.2 Read-only timeline rendering with zoom and today line
- [ ] 6.3 Dependency arrows and conflict warnings
- [ ] 6.4 Drag/resize with validation and rollback
- [ ] 6.5 Unscheduled tray
- [ ] 6.6 Phone fallback (list by date)
- [ ] 6.7 Tests: data endpoint visibility, date validation, conflicts, tenant isolation
- [ ] 6.8 Docs

## 7. Verification (fill in when done)

- New tests:
- Full test run: `php artisan test`
- `vendor/bin/pint --dirty`, `vendor/bin/phpstan analyse`, `npm run build`
- Checked in the browser (pages and roles):
- Known gaps / follow-ups:
