# Feature: Roadmap view (epics by quarter)

- **Status:** Planned
- **Requested:** 2026-10-15
- **Priority:** Low · **Effort:** Medium
- **Depends on:** `gantt-timeline`
- **Spec file:** `tasks/features/2026-10-15-roadmap-view.md`
- **Related master task items:** `tasks/TASKS.md` Phase 13

## 1. Summary

A high-level board of epics across quarters or months with progress, for managers and stakeholders.

## 2. Goals and non-goals

- Goal: Epics as cards in quarter columns (by their start/due dates), progress bar from `ProgressService::forEpic`
- Goal: Filter by project, department and status
- Goal: Clients see only epics of projects they follow, without effort data
- Not in this change: Editing task-level detail (links to the task page/timeline)

## 3. Behaviour (the spec)

| Who (role / permission / data scope) | Can do | Cannot do |
|---|---|---|
| Anyone who can view the project | See epics and progress | See effort numbers when external |
| Project manager | Drag an epic to another quarter (updates its dates) | - |

Rules (cite `WORKFLOW.md` where one applies, and new rules this feature introduces):

- An epic without dates sits in "Not scheduled"
- Progress counts non-epic descendants (existing rule)

## 4. Data

No schema change.

## 5. Impact analysis

- [x] **Tenancy**: company scoping, tenant isolation of every new query and route binding
  - Visible epics via `Task::visibleTo`
- [x] **Permissions**: new permission names (`PermissionCatalog`, `DefaultRoleTemplate`), policy changes, data scope, project membership, external stakeholders
  - Existing
- [x] **Models and services**: new or changed classes, transactions, soft deletes
  - Roadmap query, `ProgressService`
- [x] **Routes and controllers**: new routes, validation, authorization
  - `GET roadmap`
- [x] **Screens**: pages and components (`resources/js/Pages`, `Components/UI`), empty states, mobile layout, keyboard use
  - `Pages/Roadmap/Index.vue`
- [x] **Existing behaviour at risk**: features that read or write the same data (board, list, backlog, sprints, time, reports, dashboards, search)
  - Epic progress shown elsewhere; dashboards
- [ ] **Notifications**: new events, recipients, preferences (`NotificationEvents`)
- [ ] **Audit log**: admin or security actions to record (`AuditLogger`)
- [ ] **Reports and dashboards**: numbers that change, caches (`DashboardService`)
- [ ] **Queue, mail, scheduler**
- [ ] **Seed data**: `DemoSeeder` / `FeatureTestSeeder` updated so the feature can be tried
- [x] **Docs**: `WORKFLOW.md`, `DATABASE.md`, `PLAN.md`
  - WORKFLOW 12
- [x] **Performance**: queries per page, indexes, large lists
  - One progress query per epic: batch it

Risks and how they are handled:

- Epic date semantics: derive from children if empty, document the rule

## 6. Task list

Order: schema, services and rules, permissions and policies, routes and controllers, screens, notifications and audit, seed data, tests, docs.

- [ ] 6.1 Roadmap data endpoint (epics, dates, progress, visibility)
- [ ] 6.2 Quarter/month columns and epic cards
- [ ] 6.3 Drag to reschedule through `TaskService::update`
- [ ] 6.4 Client-safe variant (no effort)
- [ ] 6.5 Tests: visibility, external view, rescheduling, tenant isolation
- [ ] 6.6 Docs

## 7. Verification (fill in when done)

- New tests:
- Full test run: `php artisan test`
- `vendor/bin/pint --dirty`, `vendor/bin/phpstan analyse`, `npm run build`
- Checked in the browser (pages and roles):
- Known gaps / follow-ups:
