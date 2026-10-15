# Feature: Quick add at the bottom of a column or list

- **Status:** Done (2026-10-15)
- **Requested:** 2026-10-15
- **Priority:** Medium · **Effort:** Small
- **Depends on:** none
- **Spec file:** `tasks/features/2026-10-15-quick-add-task.md`
- **Related master task items:** `tasks/TASKS.md` Phase 13

## 1. Summary

A one-line "+ Add task" box at the foot of each board column, backlog and sprint list: type a title, press Enter, the task appears there.

## 2. Goals and non-goals

- Goal: Create a task in the column's status (or the sprint/backlog it was added in) with just a title
- Goal: Keep typing: the box stays open for the next task
- Goal: Reuse the create rules, including triage and assignee defaults
- Not in this change: Replacing the full Create dialog

## 3. Behaviour (the spec)

| Who (role / permission / data scope) | Can do | Cannot do |
|---|---|---|
| Anyone with `createTask` on the project | Add a task with a title; optional `@name` to assign, `!` priority, `#label` shortcuts | Add in an archived project |

Rules (cite `WORKFLOW.md` where one applies, and new rules this feature introduces):

- Status = the column; backlog = starting status; in a sprint list = To Do and in that sprint
- Title only is required
- Parsing of `@`, `!`, `#` is optional and falls back to plain title

## 4. Data

No schema change.

## 5. Impact analysis

- [x] **Tenancy**: company scoping, tenant isolation of every new query and route binding
  - Project resolved by company-scoped binding
- [x] **Permissions**: new permission names (`PermissionCatalog`, `DefaultRoleTemplate`), policy changes, data scope, project membership, external stakeholders
  - Existing `createTask` policy
- [x] **Models and services**: new or changed classes, transactions, soft deletes
  - `TaskService::create` gains an optional target status/sprint
- [x] **Routes and controllers**: new routes, validation, authorization
  - Reuse `tasks.store` (JSON-friendly) or a thin `tasks.quick` endpoint
- [x] **Screens**: pages and components (`resources/js/Pages`, `Components/UI`), empty states, mobile layout, keyboard use
  - Board column footer, Backlog, Sprints lists
- [x] **Existing behaviour at risk**: features that read or write the same data (board, list, backlog, sprints, time, reports, dashboards, search)
  - Board ordering (`board_position`), backlog rank and sprint history must stay correct
- [x] **Notifications**: new events, recipients, preferences (`NotificationEvents`)
  - Triage/assignment notifications as in `TaskService::create`
- [ ] **Audit log**: admin or security actions to record (`AuditLogger`)
- [ ] **Reports and dashboards**: numbers that change, caches (`DashboardService`)
- [ ] **Queue, mail, scheduler**
- [ ] **Seed data**: `DemoSeeder` / `FeatureTestSeeder` updated so the feature can be tried
- [ ] **Docs**: `WORKFLOW.md`, `DATABASE.md`, `PLAN.md`
- [ ] **Performance**: queries per page, indexes, large lists

Risks and how they are handled:

- Rapid repeated adds: disable the box while a request runs, keep focus

## 6. Task list

Order: schema, services and rules, permissions and policies, routes and controllers, screens, notifications and audit, seed data, tests, docs.

- [x] 6.1 `TaskService::create` options for status and sprint
- [x] 6.2 Quick-add endpoint and validation
- [x] 6.3 `QuickAdd.vue` component with Enter/Esc and token parsing
- [x] 6.4 Add to board columns, backlog and sprint panels
- [x] 6.5 Tests: status per place, sprint membership, archived refusal, no permission, tenant isolation
- [x] 6.6 Docs: WORKFLOW 6.1

## 7. Verification

- Built as planned, with these notes: New `POST tasks/quick` (JSON). `TaskService::create` accepts `status_id` and `sprint_id`; `QuickAddParser` reads `@name`, `!priority` and `#label`.
- New tests: DailyProductivityTest: column status, default status, shortcuts, sprint (needs the sprint right), bad status / foreign sprint / archived, missing title and no project access, client still goes to triage, parser edge cases.
- Full test run, `vendor/bin/pint --dirty`, `vendor/bin/phpstan analyse` and `npm run build`: passed (see the group report).
- Checked in the browser: list inline edit (title), side panel open and close with `?task=` in the address, J/K selection, shortcut help, quick add with `@name !4`.
- Known gaps / follow-ups: dragging a card across swimlanes was verified by tests and not by an automated browser drag.
