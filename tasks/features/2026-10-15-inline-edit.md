# Feature: Inline editing on list and board

- **Status:** Planned
- **Requested:** 2026-10-15
- **Priority:** High · **Effort:** Medium
- **Depends on:** none
- **Spec file:** `tasks/features/2026-10-15-inline-edit.md`
- **Related master task items:** `tasks/TASKS.md` Phase 13

## 1. Summary

Change a task's title, assignee, priority, due date, story points and status right where it is shown, without opening the task page.

## 2. Goals and non-goals

- Goal: Click a cell in the list (or a field on a board card) and edit it in place; Enter or blur saves, Esc cancels
- Goal: Same rules as the task page: allowed moves, assign scope, archived projects read-only
- Goal: Show the saved state and any refusal inline, never lose the typed value on error
- Not in this change: Editing description or labels inline (stays on the task page)
- Not in this change: Bulk inline edit (bulk actions already exist)

## 3. Behaviour (the spec)

| Who (role / permission / data scope) | Can do | Cannot do |
|---|---|---|
| Anyone who may update the task (`TaskPolicy::update`) | Edit title, priority, due date, points inline | Edit when project is archived or the task is deleted |
| Anyone with `task.assign` in scope (or self-assign) | Change assignee from a people picker limited to `TaskService::assigneeOptions` | Assign outside their data scope |
| Anyone who may change status | Change status from a menu showing only allowed moves with reasons (`statusMenu`) | Make a move the workflow refuses |

Rules (cite `WORKFLOW.md` where one applies, and new rules this feature introduces):

- Reuse `TaskService::update/assign/changeStatus`; no new business rules
- External stakeholders never see or edit effort fields (points, estimates)
- Optimistic UI with rollback on a 422/403

## 4. Data

No schema change.

## 5. Impact analysis

- [x] **Tenancy**: company scoping, tenant isolation of every new query and route binding
  - Task bound through the company-scoped route binding
- [x] **Permissions**: new permission names (`PermissionCatalog`, `DefaultRoleTemplate`), policy changes, data scope, project membership, external stakeholders
  - No new permission; uses existing `update`, `assign`, `changeStatus` policy abilities
- [x] **Models and services**: new or changed classes, transactions, soft deletes
  - Reuses `TaskService`; add a small JSON endpoint per field group
- [x] **Routes and controllers**: new routes, validation, authorization
  - New `PATCH tasks/{task}/field` (JSON) validated by a whitelist of editable fields
- [x] **Screens**: pages and components (`resources/js/Pages`, `Components/UI`), empty states, mobile layout, keyboard use
  - `Tasks/List.vue`, `Components/Tasks/TaskCard.vue`, a new `InlineField.vue` (text, select, date, people picker)
- [x] **Existing behaviour at risk**: features that read or write the same data (board, list, backlog, sprints, time, reports, dashboards, search)
  - Board drag, bulk actions and the task page must keep working; activity log lines still written by `TaskObserver`
- [x] **Notifications**: new events, recipients, preferences (`NotificationEvents`)
  - Assign and status notifications fire exactly as today via `TaskService`
- [ ] **Audit log**: admin or security actions to record (`AuditLogger`)
- [ ] **Reports and dashboards**: numbers that change, caches (`DashboardService`)
- [ ] **Queue, mail, scheduler**
- [ ] **Seed data**: `DemoSeeder` / `FeatureTestSeeder` updated so the feature can be tried
- [ ] **Docs**: `WORKFLOW.md`, `DATABASE.md`, `PLAN.md`
- [x] **Performance**: queries per page, indexes, large lists
  - One small request per edit; no list reload (patch the row locally)

Risks and how they are handled:

- Two people edit the same field: last write wins, show a "changed by someone else" hint using `updated_at` check
- Accidental edits: require Enter/blur, Esc to cancel

## 6. Task list

Order: schema, services and rules, permissions and policies, routes and controllers, screens, notifications and audit, seed data, tests, docs.

- [ ] 6.1 Endpoint `PATCH tasks/{task}/field` with a field whitelist and per-field policy
- [ ] 6.2 `InlineField.vue` component (text, number, date, select, people picker) with saving and error states
- [ ] 6.3 Wire into list columns (title, status, assignee, priority, due, points)
- [ ] 6.4 Wire into board card (priority, assignee, due date)
- [ ] 6.5 Keyboard: Enter saves, Esc cancels, Tab moves to next cell
- [ ] 6.6 Tests: each field, each refusal (archived, scope, workflow), external stakeholder, tenant isolation
- [ ] 6.7 Seed: nothing new; docs: WORKFLOW 6.5 note

## 7. Verification (fill in when done)

- New tests:
- Full test run: `php artisan test`
- `vendor/bin/pint --dirty`, `vendor/bin/phpstan analyse`, `npm run build`
- Checked in the browser (pages and roles):
- Known gaps / follow-ups:
